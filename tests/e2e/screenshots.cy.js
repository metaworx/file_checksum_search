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

/**
 * Every capture: one window, one theme, one locale (the browser's, forced
 * to en-US). 1600 wide, the headless window's own width (cypress.config.cjs):
 * the admin rules table needs the room beside the settings navigation, and
 * a viewport wider than the window is scaled down with every shot in it.
 */
const VIEWPORT = { width: 1600, height: 900 }

const ADMIN_URL = '/index.php/settings/admin/file_checksum_search'
const PERSONAL_URL = '/index.php/settings/user/file_checksum_search_personal'
const DUPLICATES_URL = '/index.php/apps/file_checksum_search/duplicates'

const propfindBody = [
	'<?xml version="1.0"?>',
	'<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">',
	'<d:prop><oc:fileid/></d:prop>',
	'</d:propfind>',
].join( '' )

const extractFileId = ( res ) => {
	const match = String( res.body ).match( /<(?:[a-zA-Z0-9]+:)?fileid>\s*(\d+)\s*<\/(?:[a-zA-Z0-9]+:)?fileid>/ )
	return match ? Number( match[ 1 ] ) : null
}

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
	// The administrator's a.txt once more, so the Others tab has a row that
	// is not the viewer's own.
	{ path: 'Documents/foo.txt', content: 'foo' },
]

/** The app password minted for alice, for the two Sudo tokens shots. */
const APP_PASSWORD_NAME = 'fcias-screenshots'
let appPasswordId = null

/** Alice's notes.txt, for the refused-recalculation shot; resolved in before(). */
let aliceNotesId = null

/** The settings pages scroll inside this element, not the window. */
const SETTINGS_SCROLLER = '#app-content-vue'

/** The administrator's files; fixtures/duplicates.json states their sha1. */
const ADMIN_DIR = 'fcias-e2e-duplicates'
const ADMIN_FILES = [
	{ name: 'a.txt', content: 'foo' },
	{ name: 'b.txt', content: 'foo' },
	{ name: 'c.txt', content: 'bar' },
]
/** sha1('foo'): what a.txt and b.txt share, per fixtures/duplicates.json. */
const DUP_SHA1 = '0beec7b5ea3f0fdbc95d0dd47f3c5bc275da8a33'

/** What the administrator's theme was before this run; put back in after(). */
let adminThemeWas = null

/** The administrator's a.txt, for the sidebar shot; resolved in before(). */
let adminFileId = null

/** Whether before() set the instance up; a skipped run has nothing to put back. */
let armed = false

/** Instance settings the captures pin, and what they were; put back in after(). */
let ruleEditingWas = null
let defaultAlgorithmWas = null

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

/**
 * One capture, named after the file it becomes in docs/Screenshots/ (the
 * config's after:screenshot hook moves it there). An element where the shot
 * is one part of the page; the viewport where the chrome around it is the
 * point.
 */
const shot = ( name, subject = null ) => (
	subject
		? subject.screenshot( name, { overwrite: true } )
		: cy.screenshot( name, { capture: 'viewport', overwrite: true } )
)

/** The `.fcias-section` an element sits in: one heading, its hint and its control. */
const sectionOf = ( selector ) => cy.get( selector, { timeout: FIND_TIMEOUT } ).parents( '.fcias-section' ).first()

/** The viewport, cropped to the box around several elements at once, plus a margin. */
const shotUnion = ( name, selectors, margin = 24 ) => {
	cy.window().then( ( win ) => {
		const rects = selectors
			.map( ( selector ) => win.document.querySelector( selector ) )
			.filter( ( el ) => el !== null )
			.map( ( el ) => el.getBoundingClientRect() )
		expect( rects, `elements for ${ name }` ).to.have.length( selectors.length )
		const left = Math.max( 0, Math.floor( Math.min( ...rects.map( ( r ) => r.left ) ) ) - margin )
		const top = Math.max( 0, Math.floor( Math.min( ...rects.map( ( r ) => r.top ) ) ) - margin )
		const right = Math.min( VIEWPORT.width, Math.ceil( Math.max( ...rects.map( ( r ) => r.right ) ) ) + margin )
		const bottom = Math.min( VIEWPORT.height, Math.ceil( Math.max( ...rects.map( ( r ) => r.bottom ) ) ) + margin )
		cy.screenshot( name, { capture: 'viewport', overwrite: true, clip: { x: left, y: top, width: right - left, height: bottom - top } } )
	} )
}

/** The id of the rule with this selector (and path, if given), from occ. */
const ruleId = ( selector, path = null ) => exec( 'fcias:rules:list -o json' ).then( ( { stdout } ) => {
	const rule = JSON.parse( stdout ).find( ( r ) => r.selector === selector && ( path === null || r.path === path ) )
	expect( rule, `a rule on ${ selector }` ).to.exist
	return rule.id
} )

/** Click one of the admin page's tabs and wait for its panel. */
const adminTab = ( label, panel ) => {
	cy.contains( '.fcias-tabs .fcias-tab', label, { timeout: FIND_TIMEOUT } ).click()
	cy.get( `#fcias-tab-panel-${ panel }`, { timeout: FIND_TIMEOUT } ).should( 'be.visible' )
	// The click scrolls the tab into view; the shot starts at the page top.
	cy.get( SETTINGS_SCROLLER ).scrollTo( 'top', { ensureScrollable: false } )
	cy.wait( 300 )
}

/**
 * The viewport, cropped to the box around an element with a margin: the
 * modal and the settings content sit on a page whose chrome is not the
 * point, and an element capture of either would miss its frame or scroll
 * the wrong container.
 */
const shotAround = ( name, subject, margin = 24, trim = 0 ) => {
	const chain = typeof subject === 'string' ? cy.get( subject, { timeout: FIND_TIMEOUT } ) : subject
	chain.first().then( ( $el ) => {
		const rect = $el[ 0 ].getBoundingClientRect()
		// Down to the lowest descendant, not the element's own box: a tab
		// panel's box can end above the table it holds.
		const bottom = Math.max( rect.bottom, ...Array.from( $el[ 0 ].querySelectorAll( '*' ) ).map( ( child ) => child.getBoundingClientRect().bottom ) )
		const x = Math.max( 0, Math.floor( rect.left ) - margin )
		const y = Math.max( 0, Math.floor( rect.top ) - margin )
		// The trim takes a scrollbar off an edge the crop reaches; a crop
		// that ends inside the viewport gets a little room instead.
		const width = Math.min( VIEWPORT.width - x, Math.ceil( rect.width ) + 2 * margin ) - trim
		const wanted = Math.ceil( bottom - rect.top ) + 2 * margin
		const height = y + wanted >= VIEWPORT.height ? VIEWPORT.height - y - trim : wanted + 12
		cy.screenshot( name, { capture: 'viewport', overwrite: true, clip: { x, y, width, height } } )
	} )
}

const openChecksumsTab = () => {
	cy.get( '.app-sidebar', { timeout: FIND_TIMEOUT } ).should( 'be.visible' )
	cy.get( '#tab-button-file_checksum_search-checksums', { timeout: FIND_TIMEOUT } ).click()
}

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
			const admin = { user: adminUser, password: adminPassword }
			exec( `app:enable ${ appId }` )

			// The demo accounts: a password this run knows, and a name a
			// reader can read. Asserted, so a missing account fails here
			// rather than as a bare 401 three commands later.
			for ( const account of Object.values( demo ) ) {
				account.password = strongPassword()
				provision( account.user, 'password', account.password )
				provision( account.user, 'displayname', account.name )
			}

			// Two instance settings the shots depend on: every user may edit
			// rules, so alice's own rule is hers to edit on her page, and the
			// default algorithm is the shipped one rather than whatever an
			// earlier session left. Both remembered and put back.
			cy.fciasRuleEditing( admin, true ).then( ( previous ) => {
				ruleEditingWas = previous
			} )
			cy.fciasDefaultAlgorithm( admin, 'sha1' ).then( ( previous ) => {
				defaultAlgorithmWas = previous
			} )

			// Files an earlier run left in the trash keep their hashes and
			// would sit in every group on the Duplicates page — the
			// administrator's from other specs, alice's from this one's own
			// teardown.
			exec( `trashbin:cleanup ${ adminUser }` )
			exec( `trashbin:cleanup ${ demo.alice.user }` )

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
			dav( admin, 'MKCOL', ADMIN_DIR )
			for ( const { name, content } of ADMIN_FILES ) {
				dav( admin, 'PUT', `${ ADMIN_DIR }/${ name }`, content )
			}
			cy.clearCookies()
			cy.request( {
				method: 'PROPFIND',
				url: `/remote.php/dav/files/${ adminUser }/${ ADMIN_DIR }/${ ADMIN_FILES[ 0 ].name }`,
				auth: { user: adminUser, pass: adminPassword },
				headers: { Depth: '0', 'Content-Type': 'application/xml' },
				body: propfindBody,
			} ).then( ( res ) => {
				adminFileId = extractFileId( res )
				expect( adminFileId, 'the administrator\'s a.txt should have an id' ).to.be.a( 'number' ).and.greaterThan( 0 )
			} )

			// An app password for alice, minted without her login password
			// and never shown: the Sudo tokens shots need one to grant. Its
			// grant goes with it in after().
			exec( `user:auth-tokens:add ${ demo.alice.user } --name=${ APP_PASSWORD_NAME } -n` )
			exec( `user:auth-tokens:list ${ demo.alice.user } --output=json` ).then( ( { stdout } ) => {
				const token = JSON.parse( stdout ).find( ( t ) => t.name === APP_PASSWORD_NAME )
				expect( token, 'the app password occ minted' ).to.not.eq( undefined )
				appPasswordId = token.id
			} )
			for ( const dir of [ 'Photos', 'Documents' ] ) {
				dav( demo.alice, 'MKCOL', dir )
			}
			for ( const { path, content } of ALICE_FILES ) {
				dav( demo.alice, 'PUT', path, content ).its( 'status' ).should( 'be.oneOf', [ 201, 204 ] )
			}
			cy.clearCookies()
			cy.request( {
				method: 'PROPFIND',
				url: `/remote.php/dav/files/${ demo.alice.user }/Documents/notes.txt`,
				auth: { user: demo.alice.user, pass: demo.alice.password },
				headers: { Depth: '0', 'Content-Type': 'application/xml' },
				body: propfindBody,
			} ).then( ( res ) => {
				aliceNotesId = extractFileId( res )
				expect( aliceNotesId, 'alice\'s notes.txt should have an id' ).to.be.a( 'number' ).and.greaterThan( 0 )
			} )
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
		const admin = { user: adminUser, password: adminPassword }
		cy.fciasResetRules( occ )
		if ( appPasswordId !== null ) {
			exec( `user:auth-tokens:delete ${ demo.alice.user } ${ appPasswordId }` )
		}
		cy.fciasRuleEditing( admin, ruleEditingWas === true )
		cy.fciasDefaultAlgorithm( admin, defaultAlgorithmWas ?? '' )
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

	// ─── step 2: the five names the app store already shows ─────────

	// The admin page as it opens: the heading, the five tabs, the algorithm
	// allowlist with its default, and the rules below down to the fold,
	// which falls after the first two bands.
	it( 'Admin-Settings', () => {
		cy.visit( ADMIN_URL )
		cy.get( '#fcias-rules-list tr[data-placeholder]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.window().then( ( win ) => win.scrollTo( 0, 0 ) )
		// No margin, and the scrollbars at the right and bottom edges trimmed.
		shotAround( 'Admin-Settings', '#fcias-admin-settings', 0, 16 )
	} )

	// The rules table on its own: every band with its header, one rule per
	// band of interest, the pen and menu column, and a placeholder row for
	// what no rule covers. Step 3's first name; the table is where the model
	// shows, and the page shot above cuts it at the fold.
	it( 'Admin-Rules', () => {
		cy.visit( ADMIN_URL )
		cy.get( '#fcias-rules-list tr[data-placeholder]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		// The settings page scrolls inside `#app-content-vue`, which Cypress's
		// element capture and stitching do not move (there is a second, older
		// `main.app-content` on the page that does not scroll at all).
		// Scrolled to its end — the section is the last thing on the page and
		// shorter than the viewport, so that shows all of it — and the
		// section cut out of the viewport.
		cy.get( '#app-content-vue', { timeout: FIND_TIMEOUT } ).scrollTo( 'bottom' )
		cy.wait( 300 )
		shotAround( 'Admin-Rules', sectionOf( '#fcias-rules-list' ), 16 )
	} )

	// Alice's personal page: the enforced band she may not touch, her own
	// rule, the defaults below, and her preferred algorithm.
	it( 'User-Settings', () => {
		cy.login( demo.alice.user, demo.alice.password )
		cy.visit( PERSONAL_URL )
		cy.get( '#fcias-personal-rules', { timeout: FIND_TIMEOUT } ).should( 'contain', 'Photos' )
		shot( 'User-Settings', cy.get( '#fcias-personal-settings' ) )
	} )

	// The Files sidebar on a hashed file, as the administrator: the hashes,
	// the Recalculate buttons, Find duplicates and Find across accounts.
	it( 'File-Detail-Pane', () => {
		cy.visit( `/index.php/apps/files/files/${ adminFileId }?dir=${ encodeURIComponent( '/' + ADMIN_DIR ) }&opendetails=true` )
		openChecksumsTab()
		cy.get( '.fcias-selectable-hash', { timeout: FIND_TIMEOUT } ).should( 'have.length.at.least', 1 )
		cy.get( '[data-testid="fcias-dup-across"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		// From the file's name down: the preview above it is a blank the
		// text file cannot fill.
		cy.get( '.app-sidebar' ).then( ( $sidebar ) => {
			const { width, height } = $sidebar[ 0 ].getBoundingClientRect()
			const top = 240
			const bottom = 8 // the rounded corner, and the page showing through it
			cy.get( '.app-sidebar' ).screenshot( 'File-Detail-Pane', { overwrite: true, clip: { x: 0, y: top, width, height: height - top - bottom } } )
		} )
	} )

	// The Duplicates page on Mine, one group opened to its files and their
	// Verify buttons.
	it( 'Duplicates-Page', () => {
		cy.visit( DUPLICATES_URL )
		cy.get( '.db-group', { timeout: FIND_TIMEOUT } ).should( 'have.length.at.least', 1 )
		// The group of this spec's own files, opened; the rest of the
		// administrator's duplicates — whatever the enabled home rule hashed
		// meanwhile — stay collapsed around it.
		cy.contains( '.db-group', DUP_SHA1, { timeout: FIND_TIMEOUT } ).find( '.db-group-header' ).click()
		cy.contains( '.db-group', DUP_SHA1 ).find( '.db-file-label', { timeout: FIND_TIMEOUT } ).should( 'have.length', 2 )
		// Down to the last group, not the page's full height.
		cy.get( '.db-group' ).last().then( ( $last ) => {
			cy.get( '.db-wrap' ).then( ( $wrap ) => {
				const height = Math.ceil( $last[ 0 ].getBoundingClientRect().bottom - $wrap[ 0 ].getBoundingClientRect().top ) + 16
				cy.get( '.db-wrap' ).screenshot( 'Duplicates-Page', { overwrite: true, clip: { x: 0, y: 0, width: Math.ceil( $wrap[ 0 ].getBoundingClientRect().width ), height } } )
			} )
		} )
	} )

	// The unified search on a real hash, the File Checksums provider listing
	// the two files that carry it. The hash is typed the way global-search
	// does it: through the native setter and one input event, since the
	// field is debounced and cy.type() races it.
	it( 'Unified_Search', () => {
		cy.intercept( 'GET', '**/search/providers/file_checksum_search_provider/search**' ).as( 'hashSearch' )
		cy.visit( '/index.php/apps/files/' )
		cy.get( '.unified-search-menu button', { timeout: FIND_TIMEOUT } ).first().click()
		cy.get( '[data-cy-unified-search-input]', { timeout: FIND_TIMEOUT } ).then( ( $input ) => {
			const el = $input[ 0 ]
			const setValue = Object.getOwnPropertyDescriptor( el.ownerDocument.defaultView.HTMLInputElement.prototype, 'value' ).set
			setValue.call( el, DUP_SHA1 )
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) )
		} )
		cy.wait( '@hashSearch', { timeout: FIND_TIMEOUT } )
		cy.get( '.unified-search-modal', { timeout: FIND_TIMEOUT } ).should( 'contain', ADMIN_FILES[ 0 ].name )
		// The modal with its frame, once its entrance has finished.
		cy.get( '.unified-search-modal' ).should( 'be.visible' )
		cy.wait( 600 )
		shotAround( 'Unified_Search', '.modal-container', 40 )
	} )

	// ─── step 3: the surfaces no shot showed ────────────────────────

	it( 'Admin-Permissions', () => {
		cy.visit( ADMIN_URL )
		adminTab( 'Permissions', 'permissions' )
		shotAround( 'Admin-Permissions', '#fcias-admin-settings', 0, 16 )
	} )

	// After a drain and one sweep, so Status Info carries real heartbeats.
	it( 'Admin-Advanced', () => {
		exec( 'fcias:queue:drain --all' )
		exec( 'background-job:list --output=json' ).then( ( { stdout } ) => {
			const sweep = JSON.parse( stdout ).find( ( job ) => String( job.class ).endsWith( 'RuleProcessingJob' ) )
			if ( sweep ) {
				exec( `background-job:execute --force-execute ${ sweep.id }` )
			}
		} )
		cy.visit( ADMIN_URL )
		adminTab( 'Advanced', 'advanced' )
		cy.get( '.fcias-status-table', { timeout: FIND_TIMEOUT } ).should( 'contain', 'Background Jobs' )
		shotAround( 'Admin-Advanced', '#fcias-admin-settings', 0, 16 )
	} )

	// Alice grants her app password; the admin tab then lists the grant.
	it( 'User-Sudo-Tokens', () => {
		cy.login( demo.alice.user, demo.alice.password )
		cy.visit( PERSONAL_URL )
		const row = `#fcias-personal-sudo-tokens [data-token-id="${ appPasswordId }"]`
		cy.get( row, { timeout: FIND_TIMEOUT } ).should( 'contain', APP_PASSWORD_NAME )
		const toggle = () => cy.get( `${ row } .checkbox-radio-switch input[type="checkbox"]`, { timeout: FIND_TIMEOUT } )
		toggle().should( 'not.be.checked' )
		toggle().click( { force: true } )
		toggle().should( 'be.checked' )
		cy.get( SETTINGS_SCROLLER ).scrollTo( 'bottom', { ensureScrollable: false } )
		cy.wait( 300 )
		shotAround( 'User-Sudo-Tokens', '#fcias-personal-sudo-tokens', 16 )
	} )

	it( 'Admin-Sudo-Tokens', () => {
		cy.visit( `${ ADMIN_URL }#tokens` )
		cy.get( `[data-grant="${ demo.alice.user }/${ appPasswordId }"]`, { timeout: FIND_TIMEOUT } ).should( 'contain', APP_PASSWORD_NAME )
		shotAround( 'Admin-Sudo-Tokens', '#fcias-admin-settings', 0, 16 )
	} )

	// One row's menu open: Edit, Disable, Re-apply, Delete, the pen beside.
	it( 'Rule-Row-Menu', () => {
		cy.visit( ADMIN_URL )
		cy.get( '#fcias-rules-list tr[data-band="5"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( SETTINGS_SCROLLER ).scrollTo( 'bottom' )
		cy.wait( 300 )
		cy.get( '#fcias-rules-list tr[data-band="5"] .action-item__menutoggle' ).first().click()
		cy.get( '.action-item__popper [data-action="edit"]', { timeout: FIND_TIMEOUT } ).should( 'be.visible' )
		shotUnion( 'Rule-Row-Menu', [ '#fcias-rules-list tr[data-band="5"]', '.action-item__popper' ], 16 )
	} )

	// The dialog from the placeholder for the uncovered group folder, with
	// the folder picker open on the one folder it offers.
	it( 'Rule-Dialog', () => {
		cy.visit( ADMIN_URL )
		cy.get( `#fcias-rules-list tr[data-placeholder="groupfolder:${ DESIGN_ASSETS_FOLDER_ID }"] [data-action="create"]`, { timeout: FIND_TIMEOUT } ).click()
		cy.get( '#fcias-rule-form', { timeout: FIND_TIMEOUT } ).should( 'be.visible' )
		cy.get( '#fcias-rule-selector-target', { timeout: FIND_TIMEOUT } ).trigger( 'keydown', { key: 'ArrowDown', keyCode: 40 } )
		cy.get( '.vs__dropdown-menu', { timeout: FIND_TIMEOUT } ).should( 'be.visible' )
		cy.wait( 300 )
		shotUnion( 'Rule-Dialog', [ '.modal-container', '.vs__dropdown-menu' ], 24 )
	} )

	// A rule on a group folder that does not exist: inert, and badged so.
	it( 'Provider-Missing', () => {
		exec( 'fcias:rules:add --selector groupfolder:99 --path \'**\' --type include -a sha1 --enable' )
		cy.visit( ADMIN_URL )
		cy.get( '.fcias-provider-missing', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( SETTINGS_SCROLLER ).scrollTo( 'bottom' )
		cy.wait( 300 )
		shotAround( 'Provider-Missing', sectionOf( '#fcias-rules-list' ), 16 )
		ruleId( 'groupfolder:99' ).then( ( id ) => exec( `fcias:rules:delete ${ id } -y` ) )
	} )

	// The Others tab on the administrator's hash over the whole reach: the
	// two own copies behind a house, alice's behind a person, as text.
	it( 'Duplicates-Others', () => {
		cy.visit( `${ DUPLICATES_URL }#others?hash=${ DUP_SHA1 }&all=1` )
		cy.get( '[data-testid="fcias-others"] .db-group', { timeout: FIND_TIMEOUT } ).should( 'have.length.at.least', 1 )
		cy.contains( '[data-testid="fcias-others"] .db-group', DUP_SHA1 ).find( '.db-group-header' ).click()
		cy.get( '[data-testid="fcias-others"] .db-file-unopenable', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '[data-testid="fcias-others"] .db-group' ).last().then( ( $last ) => {
			cy.get( '.db-wrap' ).then( ( $wrap ) => {
				const height = Math.ceil( $last[ 0 ].getBoundingClientRect().bottom - $wrap[ 0 ].getBoundingClientRect().top ) + 16
				cy.get( '.db-wrap' ).screenshot( 'Duplicates-Others', { overwrite: true, clip: { x: 0, y: 0, width: Math.ceil( $wrap[ 0 ].getBoundingClientRect().width ), height } } )
			} )
		} )
	} )

	// A file an exclude rule covers: the sidebar refuses to recalculate it
	// and says which rule.
	it( 'Sidebar-Excluded', () => {
		exec( `fcias:rules:add --selector home:${ demo.alice.user } --path 'Documents/notes.txt' --type exclude --enable` )
		cy.login( demo.alice.user, demo.alice.password )
		cy.visit( `/index.php/apps/files/files/${ aliceNotesId }?dir=${ encodeURIComponent( '/Documents' ) }&opendetails=true` )
		openChecksumsTab()
		cy.get( '.fcias-recalc-btn', { timeout: FIND_TIMEOUT } ).first().click()
		cy.get( '.fcias-recalc-error', { timeout: FIND_TIMEOUT } ).should( 'not.be.empty' )
		// The message fades in.
		cy.wait( 600 )
		cy.get( '.app-sidebar' ).then( ( $sidebar ) => {
			const { width, height } = $sidebar[ 0 ].getBoundingClientRect()
			const top = 240
			cy.get( '.app-sidebar' ).screenshot( 'Sidebar-Excluded', { overwrite: true, clip: { x: 0, y: top, width, height: height - top - 8 } } )
		} )
		ruleId( `home:${ demo.alice.user }`, 'Documents/notes.txt' ).then( ( id ) => exec( `fcias:rules:delete ${ id } -y` ) )
	} )

	// No enabled include rule: the banner. Every rule disabled for the
	// shot and enabled again after.
	it( 'Admin-Idle-Banner', () => {
		const enabled = []
		exec( 'fcias:rules:list -o json' ).then( ( { stdout } ) => {
			for ( const rule of JSON.parse( stdout ).filter( ( r ) => r.enabled === 'yes' ) ) {
				enabled.push( rule.id )
				exec( `fcias:rules:modify ${ rule.id } --disable` )
			}
		} )
		exec( 'config:app:delete file_checksum_search idle_banner_ack' )
		cy.visit( ADMIN_URL )
		cy.get( '#fcias-idle-banner', { timeout: FIND_TIMEOUT } ).should( 'be.visible' )
		shotAround( 'Admin-Idle-Banner', '#fcias-admin-settings', 0, 16 )
		cy.then( () => {
			for ( const id of enabled ) {
				exec( `fcias:rules:modify ${ id } --enable` )
			}
		} )
	} )
} )
