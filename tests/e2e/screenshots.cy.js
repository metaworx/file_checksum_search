/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The screenshot set, as a spec.
 *
 * Not part of the suite: it runs only when asked, with `CYPRESS_capture=1`,
 * and without that it skips itself. What it does is put the instance into
 * the one state every capture starts from — demo accounts with readable
 * names, a light theme, a group folder with a group on it, files with
 * hashes, three enabled rules across the bands — take the shots into
 * `docs/Screenshots/`, and put the instance back. Repeatable, so the set
 * can be retaken at the next change of the UI rather than aging in place.
 *
 * The accounts are the harness's own `alice` and `bob`, not minted ones: a
 * shot of the Others tab prints an account's uid in a location, and
 * `fcias_e2e_bob_1a2b3c4d/files/…` is not what the app store should show.
 * Each run gives them a fresh random password and a display name; nothing
 * is deleted afterwards, since they are the instance's demo accounts.
 *
 * Step 1 of AP Screenshots v1.1: the scaffold, nothing captured yet.
 */

const appId = 'file_checksum_search'

// Nextcloud checkout lives in ./nextcloud. Override via CYPRESS_occ.
let occ = 'php nextcloud/occ'
let adminUser = 'admin'
let adminPassword = 'admin'

const FIND_TIMEOUT = 60000
const EXEC_TIMEOUT = 300000

/** Every capture: one window, one theme, one locale (the browser's, forced to en-US). */
const VIEWPORT = { width: 1440, height: 900 }

const ADMIN_URL = '/index.php/settings/admin/file_checksum_search'

/** The demo accounts; passwords are minted per run in before(). */
const demo = {
	alice: { user: 'alice', password: '', name: 'Alice Example' },
	bob: { user: 'bob', password: '', name: 'Bob Example' },
}

/** The group that gets the second group folder; created and deleted here. */
const DESIGNERS = 'fcias-designers'
const DESIGN_ASSETS_FOLDER_ID = 2

/** Alice's files; fixtures/screenshots-alice.json states their sha1. */
const ALICE_FILES = [
	{ path: 'Photos/holiday.txt', content: 'FCIAS screenshot set: a holiday photo, twice.' },
	{ path: 'Documents/holiday.txt', content: 'FCIAS screenshot set: a holiday photo, twice.' },
	{ path: 'Documents/notes.txt', content: 'FCIAS screenshot set: notes nobody else has.' },
]

/** The administrator's files; fixtures/duplicates.json states their sha1. */
const ADMIN_DIR = 'fcias-e2e-duplicates'
const ADMIN_FILES = [
	{ name: 'a.txt', content: 'foo' },
	{ name: 'b.txt', content: 'foo' },
	{ name: 'c.txt', content: 'bar' },
]

/** What the administrator's theme was before this run; put back in after(). */
let adminThemeWas = null

/** Whether before() set the instance up; a skipped run has nothing to put back. */
let armed = false

const strongPassword = () => {
	const bytes = new Uint8Array( 24 )
	crypto.getRandomValues( bytes )
	return 'Fc1!' + Array.from( bytes, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' )
}

/** One provisioning-API write on an account, as the administrator. */
const provision = ( uid, key, value ) => {
	cy.clearCookies()
	return cy.request( {
		method: 'PUT',
		url: `/ocs/v2.php/cloud/users/${ uid }?format=json`,
		auth: { user: adminUser, pass: adminPassword },
		headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
		body: { key, value },
	} ).its( 'body.ocs.meta.statuscode' ).should( 'eq', 200 )
}

/**
 * One WebDAV call as an account. Cookies first: cy.request() shares the
 * browser's jar, and a session left there by an earlier call — the
 * administrator's, from provisioning — would answer for the Basic auth
 * given here and see alice's path as somebody else's, a 404.
 */
const dav = ( account, method, path, body = undefined ) => cy.clearCookies().then( () => cy.request( {
	method,
	url: `/remote.php/dav/files/${ account.user }/${ path }`,
	auth: { user: account.user, pass: account.password },
	headers: body === undefined ? {} : { 'Content-Type': 'text/plain' },
	body,
	failOnStatusCode: false,
} ) )

const exec = ( command ) => cy.exec( `${ occ } ${ command }`, { timeout: EXEC_TIMEOUT, failOnNonZeroExit: false } )

/** Set the theme an account sees; `null` puts it back to the instance default. */
const theme = ( uid, value ) => (
	value === null
		? exec( `user:setting --delete ${ uid } theming enabled-themes` )
		: exec( `user:setting ${ uid } theming enabled-themes '${ JSON.stringify( [ value ] ) }'` )
)

describe( 'FCIAS screenshots', () => {
	before( function () {
		const suite = this
		cy.env( [ 'capture', 'occ', 'NC_ADMIN_USER', 'NC_ADMIN_PASSWORD' ] ).then( ( env ) => {
			if ( ! env.capture ) {
				// Not a test: a tool. Skipped unless asked for by name.
				suite.skip()
			}
			if ( env.occ ) {
				occ = env.occ
			}
			if ( env.NC_ADMIN_USER ) {
				adminUser = env.NC_ADMIN_USER
			}
			if ( env.NC_ADMIN_PASSWORD ) {
				adminPassword = env.NC_ADMIN_PASSWORD
			}
		} ).then( () => {
			exec( `app:enable ${ appId }` )

			// The demo accounts: a password this run knows, and a name a
			// reader can read. Asserted, so a missing account fails here
			// rather than as a bare 401 three commands later.
			for ( const account of Object.values( demo ) ) {
				account.password = strongPassword()
				provision( account.user, 'password', account.password )
				provision( account.user, 'displayname', account.name )
			}

			// One theme for the whole set. The administrator's own choice is
			// remembered and put back; the demo accounts stay light.
			exec( `user:setting ${ adminUser } theming enabled-themes` ).then( ( { code, stdout } ) => {
				adminThemeWas = code === 0 && stdout.trim() !== '' ? stdout.trim() : null
			} )
			for ( const uid of [ adminUser, demo.alice.user, demo.bob.user ] ) {
				theme( uid, 'light' )
			}

			// A group on the second group folder, so the rule dialog and the
			// picker have a named folder to show.
			exec( `group:add ${ DESIGNERS }` )
			exec( `group:adduser ${ DESIGNERS } ${ demo.alice.user }` )
			exec( `group:adduser ${ DESIGNERS } ${ demo.bob.user }` )
			exec( `groupfolders:group ${ DESIGN_ASSETS_FOLDER_ID } ${ DESIGNERS } read write` )

			// Files, then their hashes stated from the fixtures: the reset
			// first, or it would clear what was just stated.
			const admin = { user: adminUser, password: adminPassword }
			dav( admin, 'MKCOL', ADMIN_DIR )
			for ( const { name, content } of ADMIN_FILES ) {
				dav( admin, 'PUT', `${ ADMIN_DIR }/${ name }`, content )
			}
			for ( const dir of [ 'Photos', 'Documents' ] ) {
				dav( demo.alice, 'MKCOL', dir )
			}
			for ( const { path, content } of ALICE_FILES ) {
				dav( demo.alice, 'PUT', path, content ).its( 'status' ).should( 'be.oneOf', [ 201, 204 ] )
			}
			cy.resetFciasState( occ )
			cy.importFciasFixture( occ, 'duplicates', adminUser )
			cy.importFciasFixture( occ, 'screenshots-alice', demo.alice.user )

			// Three enabled rules across the bands: the enforced group-folder
			// rule (band 2), alice's own (band 5), the shipped home default
			// (band 7); the catch-all default stays disabled, so the table
			// shows a placeholder row too.
			cy.fciasResetRules( occ )
			exec( 'fcias:rules:list -o json' ).then( ( { stdout } ) => {
				const homeDefault = JSON.parse( stdout ).find( ( rule ) => rule.selector === 'home:*' )
				expect( homeDefault, 'the shipped home:* default' ).to.exist
				exec( `fcias:rules:modify ${ homeDefault.id } --enable` )
			} )
			exec( `fcias:rules:add --selector home:${ demo.alice.user } --path 'Photos/**' --type include -a sha256 --enable` )
			exec( 'fcias:rules:add --selector groupfolder:1 --path \'**\' --type include -a sha1 --enforced --enable' )
			cy.then( () => {
				armed = true
			} )
		} )
	} )

	after( () => {
		if ( ! armed ) {
			return
		}
		cy.fciasResetRules( occ )
		exec( `groupfolders:group ${ DESIGN_ASSETS_FOLDER_ID } ${ DESIGNERS } --delete` )
		exec( `group:delete ${ DESIGNERS }` )
		theme( adminUser, adminThemeWas )
		// The files, not the folders: Photos and Documents are alice's own on
		// the demo instance, and may hold more than this spec put there.
		for ( const { path } of ALICE_FILES ) {
			dav( demo.alice, 'DELETE', path )
		}
	} )

	beforeEach( () => {
		cy.viewport( VIEWPORT.width, VIEWPORT.height )
		cy.login( adminUser, adminPassword )
	} )

	// The scaffold, checked where the captures will look: three rules enabled
	// across the bands, a placeholder row for what no rule covers, no idle
	// banner, and the demo accounts under their display names.
	it( 'holds the state every capture starts from', () => {
		exec( 'fcias:rules:list -o json' ).then( ( { stdout } ) => {
			const rules = JSON.parse( stdout )
			expect( rules.filter( ( rule ) => rule.enabled === 'yes' ).map( ( rule ) => rule.selector ).sort() )
				.to.deep.equal( [ 'groupfolder:1', 'home:*', `home:${ demo.alice.user }` ] )
		} )

		cy.visit( ADMIN_URL )
		cy.get( '#fcias-rules-list', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '#fcias-idle-banner' ).should( 'not.exist' )
		cy.get( '#fcias-rules-list tr[data-placeholder]' ).should( 'exist' )
		cy.get( '#fcias-rules-list' ).should( 'contain', 'Team Docs' )

		cy.clearCookies()
		cy.request( {
			url: `/ocs/v2.php/cloud/users/${ demo.alice.user }?format=json`,
			auth: { user: adminUser, pass: adminPassword },
			headers: { 'OCS-APIRequest': 'true' },
		} ).its( 'body.ocs.data.displayname' ).should( 'eq', demo.alice.name )

		cy.login( demo.alice.user, demo.alice.password )
		cy.visit( '/index.php/apps/files' )
		cy.get( '[data-cy-files-list]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
	} )
} )
