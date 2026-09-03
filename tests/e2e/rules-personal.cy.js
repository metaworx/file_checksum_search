/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E tests for what a user who is not an administrator sees:
 * their own rules page, a rule they may not touch, and a file that is
 * somebody else's.
 *
 * The two tests at the end are the ones with no coverage anywhere else.
 * A file's verdict is resolved from its *owner's* identity, not from the
 * path of whoever happens to be acting — so a recipient writing into a
 * share is governed by the owner's rules. And a hash search returns only
 * what the searcher could have opened. Both were integration-tested at
 * most; neither had been asked of a running server.
 */

const appId = 'file_checksum_search'

// Default to the CI layout: Cypress runs in the repo root and the
// Nextcloud checkout lives in ./nextcloud. Override via CYPRESS_occ
// for local/ddev runs (e.g. CYPRESS_occ="php /var/www/html/occ").
let occ = 'php nextcloud/occ'
let adminUser = 'admin'
let adminPassword = 'admin'

// Made in before(), deleted in after(), and named for the run rather than
// for the instance: nothing here authenticates as an account it did not
// create. The base rides in the uid so a failure still says which of the
// two was the sharer.
let alice = {}
let bob = {}

const FIND_TIMEOUT = 60000

const PERSONAL_URL = '/index.php/settings/user/file_checksum_search_personal'

// Alice's, and named so no instance skeleton has one already: a folder
// bob also happens to own would be mounted under a different name and
// the paths below would stop meaning what they say.
const sharedDir = 'fcias-e2e-shared'
const sharedFile = 'note.txt'
const privateDir = 'fcias-e2e-private'
const privateFile = 'secret.txt'

// sha1('FCIAS e2e private content'), stated by fixtures/private.json for
// a file alice shares with nobody.
const PRIVATE_SHA1 = '59b280295a6bfa90201c4effb28745c030480fbb'

const propfindBody = [
	'<?xml version="1.0"?>',
	'<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">',
	'<d:prop><oc:fileid/></d:prop>',
	'</d:propfind>',
].join( '' )

let sharedFileIdForBob = null

const dav = ( who, path ) => `/remote.php/dav/files/${ who.user }${ path }`

// The unified-search provider's own endpoint, which is what the search
// modal calls. A core OCS route, so unlike this app's own endpoints it
// *does* wrap its answer in {ocs:{data}}.
const searchAs = ( who, term ) => cy.clearCookies().then( () => cy.request( {
	url: `/ocs/v2.php/search/providers/${ appId }_provider/search`
		+ `?term=${ term }&format=json`,
	auth: { user: who.user, pass: who.password },
	headers: { 'OCS-APIRequest': 'true' },
	failOnStatusCode: false,
} ) )

// What the rule-editing permission was before this spec touched it, so
// after() can put that back rather than a guess. Restoring it to `true`
// would leave an instance permanently permissive; worse, a failure between
// revoking and restoring used to leave it permanently denied.
let ruleEditingWas = false

// Likewise the instance's default algorithm: the sidebar's first quick button
// is it, absent a preference, so a spec that clicks the sha1 button is
// asserting on state the settings page can change.
let defaultAlgorithmWas = ''

const ruleEditingForEveryone = ( allow ) => cy.fciasRuleEditing(
	{ user: adminUser, password: adminPassword },
	allow,
)

const openChecksumsTab = () => {
	cy.get( '.app-sidebar', { timeout: FIND_TIMEOUT } ).should( 'be.visible' )
	// The tab's registered id, not its caption: the sidebar is the one part
	// of this app that already goes through t(), so its label is the first
	// thing translation will move.
	cy.get( '#tab-button-file_checksum_search-checksums', { timeout: FIND_TIMEOUT } ).click()
}

describe( 'FCIAS for a user who is not an administrator', () => {
	before( () => {
		cy.env( [ 'occ', 'NC_ADMIN_USER', 'NC_ADMIN_PASSWORD' ] ).then( ( env ) => {
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
			cy.exec( `${ occ } app:enable ${ appId }`, { failOnNonZeroExit: false } )

			const admin = { user: adminUser, password: adminPassword }

			cy.fciasMakeAccount( admin, 'alice' ).then( ( account ) => {
				alice = account
			} )
			cy.fciasMakeAccount( admin, 'bob' ).then( ( account ) => {
				bob = account
			} )

			// Everything below reads alice.user and bob.user, and a Cypress
			// command's options are built when it is *queued*, not when it
			// runs — so the rest of this hook has to be queued from a later
			// then(), after the two assignments above have happened. With
			// the fixed names this was a module constant and the question
			// never arose.
		} ).then( () => {
			cy.fciasResetRules( occ )

			// Set rather than assumed: the shipped default is that nobody
			// but an administrator may write a rule, and the developer
			// instance has it switched on while a fresh CI one does not.
			ruleEditingForEveryone( true ).then( ( previous ) => {
				ruleEditingWas = previous
			} )

			// Bob has no preference, so his sidebar offers the instance
			// default first; the refusal test below clicks it by name.
			cy.fciasDefaultAlgorithm(
				{ user: adminUser, password: adminPassword },
				'sha1',
			).then( ( previous ) => {
				defaultAlgorithmWas = previous
			} )

			// Alice's two folders: one she shares with bob, one she does not.
			for ( const dir of [ sharedDir, privateDir ] ) {
				cy.request( {
					method: 'MKCOL',
					url: dav( alice, `/${ dir }` ),
					auth: { user: alice.user, pass: alice.password },
					failOnStatusCode: false,
				} )
			}

			cy.request( {
				method: 'PUT',
				url: dav( alice, `/${ sharedDir }/${ sharedFile }` ),
				auth: { user: alice.user, pass: alice.password },
				headers: { 'Content-Type': 'text/plain' },
				body: 'shared content',
			} )
			cy.request( {
				method: 'PUT',
				url: dav( alice, `/${ privateDir }/${ privateFile }` ),
				auth: { user: alice.user, pass: alice.password },
				headers: { 'Content-Type': 'text/plain' },
				body: 'FCIAS e2e private content',
			} )

			// Idempotent: sharing an already-shared path answers 400, which
			// is a re-run rather than a failure.
			cy.request( {
				method: 'POST',
				url: '/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json',
				auth: { user: alice.user, pass: alice.password },
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
				body: {
					path: `/${ sharedDir }`,
					shareType: 0,
					shareWith: bob.user,
					permissions: 31,
				},
				failOnStatusCode: false,
			} )

			// An administrator's enforced rule over alice's files: hers to
			// obey, not to edit. Aimed at the folder she keeps to herself,
			// deliberately — an enforced `**` would be band 1 over
			// everything, and the first match decides, so it would shadow
			// her own exclude below and quietly make bob's test pass for the
			// wrong reason.
			cy.exec(
				`${ occ } fcias:rules:add --selector home:${ alice.user }`
				+ ` --path '/${ privateDir }/**' --type include -a sha1 --enforced`,
			)

			// Alice's own rule: the exclude over the folder she shares, which
			// is the one bob will run into without ever seeing it.
			cy.ocs( {
				method: 'POST',
				url: '/api/v1/rules',
				body: {
					selector: `home:${ alice.user }`,
					path: `/${ sharedDir }/**`,
					type: 'exclude',
				},
				...alice,
			} ).its( 'status' ).should( 'eq', 200 )

			// Bob's own id for alice's file: the same file, a different
			// mount, and the number the sidebar will be opened with.
			cy.request( {
				method: 'PROPFIND',
				url: dav( bob, `/${ sharedDir }/${ sharedFile }` ),
				auth: { user: bob.user, pass: bob.password },
				headers: {
					Depth: '0',
					'Content-Type': 'application/xml',
				},
				body: propfindBody,
			} ).then( ( res ) => {
				const match = String( res.body )
					.match( /<(?:[a-zA-Z0-9]+:)?fileid>\s*(\d+)\s*<\/(?:[a-zA-Z0-9]+:)?fileid>/ )

				sharedFileIdForBob = match
					? Number( match[ 1 ] )
					: null
			} )

			cy.importFciasFixture( occ, 'private', alice.user )
		} )
	} )

	after( () => {
		ruleEditingForEveryone( ruleEditingWas )
		cy.fciasDefaultAlgorithm(
			{ user: adminUser, password: adminPassword },
			defaultAlgorithmWas,
		)

		// Rules first: the enforced one names `home:<alice>`, and clearing
		// what points at an account before the account itself is the order
		// that stays truthful rather than merely working.
		cy.fciasResetRules( occ )

		const admin = { user: adminUser, password: adminPassword }

		cy.fciasDeleteAccount( admin, alice.user )
		cy.fciasDeleteAccount( admin, bob.user )
	} )

	describe( 'her own rules page', () => {
		beforeEach( () => {
			cy.login( alice.user, alice.password )
		} )

		it( 'shows her rule in her own band and the enforced one above it', () => {
			cy.visit( PERSONAL_URL )
			cy.get( '#fcias-personal-rules', { timeout: FIND_TIMEOUT } ).should( 'exist' )

			// Band 1 is an enforced exact selector, band 5 the same selector
			// unenforced — which is where a user's own rules live.
			cy.get( '#fcias-personal-rules tr[data-band="1"]' ).should( 'have.length', 1 )
			cy.get( '#fcias-personal-rules tr[data-band="5"]' ).should( 'have.length.at.least', 1 )
		} )

		it( 'gives her no way to edit what an administrator enforced', () => {
			cy.visit( PERSONAL_URL )

			// Not merely hidden: the row offers no edit affordance at all,
			// neither the pen nor the menu behind it.
			cy.get( '#fcias-personal-rules tr[data-band="1"] [data-action="edit"]' )
				.should( 'not.exist' )
			cy.get( '#fcias-personal-rules tr[data-band="1"] .action-item__menutoggle' )
				.should( 'not.exist' )

			// And her own row does.
			cy.get( '#fcias-personal-rules tr[data-band="5"] [data-action="edit"]' )
				.should( 'exist' )
		} )

		it( 'offers Add Rule only while she is permitted to write one', () => {
			cy.visit( PERSONAL_URL )
			cy.get( '#fcias-personal-add', { timeout: FIND_TIMEOUT } ).should( 'exist' )

			// cy.ocs() clears cookies, which ends alice's session — so log in
			// again rather than reloading into the login page.
			ruleEditingForEveryone( false )
			cy.login( alice.user, alice.password )
			cy.visit( PERSONAL_URL )
			cy.get( '#fcias-personal-rules', { timeout: FIND_TIMEOUT } ).should( 'exist' )
			cy.get( '#fcias-personal-add' ).should( 'not.exist' )

			ruleEditingForEveryone( true )
		} )
	} )

	describe( 'a file that belongs to somebody else', () => {
		beforeEach( () => {
			cy.login( bob.user, bob.password )
		} )

		it( 'refuses bob the recalculation alice\'s rule excludes, and says why', () => {
			expect( sharedFileIdForBob, 'bob\'s id for the shared file' )
				.to.be.a( 'number' ).and.greaterThan( 0 )

			cy.visit(
				`/index.php/apps/files/files/${ sharedFileIdForBob }`
				+ `?dir=${ encodeURIComponent( `/${ sharedDir }` ) }&opendetails=true`,
			)
			openChecksumsTab()

			cy.get( '.fcias-recalc-btn[data-algo="sha1"]', { timeout: FIND_TIMEOUT } ).click()

			// The verdict comes from the file's owner, not from the person
			// asking: bob has never seen alice's rule and cannot edit it,
			// and it governs his write all the same. The refusal carries the
			// reason rather than failing silently.
			cy.get( '.fcias-recalc-error', { timeout: FIND_TIMEOUT } )
				.should( 'exist' )
				.and( 'not.be.empty' )
		} )
	} )

	describe( 'searching for a hash', () => {
		it( 'finds the owner\'s file for the owner', () => {
			searchAs( alice, PRIVATE_SHA1 ).then( ( { status, body } ) => {
				expect( status ).to.eq( 200 )

				const entries = body.ocs.data.entries

				expect( entries, 'alice can open it, so she can find it' )
					.to.have.length.at.least( 1 )
				expect( entries.some( ( e ) => e.title === privateFile ) ).to.eq( true )
			} )
		} )

		it( 'finds nothing for someone who cannot reach it', () => {
			// Knowing the hash is not authorisation. Integration tests cover
			// the provider's filtering; this is the whole stack answering.
			searchAs( bob, PRIVATE_SHA1 ).then( ( { status, body } ) => {
				expect( status ).to.eq( 200 )
				expect( body.ocs.data.entries ).to.have.length( 0 )
			} )
		} )
	} )
} )
