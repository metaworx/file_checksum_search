/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E tests for the global duplicates page.
 *
 * Builds its own state and depends on no other spec. It used to read
 * whatever checksums.cy.js had left behind, which is how it rotted: each
 * run added two more files with the same content, and by 145 of them
 * Verify hashes was hitting the per-user recalculation rate limit
 * partway through and failing on a real instance for a reason that had
 * nothing to do with the page.
 *
 * Now: three files created here, hashes stated by a fixture rather than
 * computed, and a reset in front of both so the page is showing this
 * run's files and nothing else.
 */

const appId = 'file_checksum_search'

// Default to the CI layout: Cypress runs in the repo root and the
// Nextcloud checkout lives in ./nextcloud. Override via CYPRESS_occ
// for local/ddev runs (e.g. CYPRESS_occ="php /var/www/html/occ").
let occ = 'php nextcloud/occ'
let adminUser = 'admin'
let adminPassword = 'admin'

// Allow a generous timeout when locating UI elements: the first app/page
// request can be slow on a cold PHP worker.
const FIND_TIMEOUT = 60000

const DUPLICATES_URL = '/index.php/apps/file_checksum_search/duplicates'

// The directory and contents tests/e2e/fixtures/duplicates.json describes.
// A fixed directory, not a timestamped one: re-running overwrites the same
// three files instead of leaving a fourth, fifth and sixth copy behind.
const dupDir = 'fcias-e2e-duplicates'
const files = [
	{ name: 'a.txt', content: 'foo' },
	{ name: 'b.txt', content: 'foo' },
	{ name: 'c.txt', content: 'bar' },
]

// sha1('foo'), shared by a.txt and b.txt — the one duplicate group this
// spec asserts on. c.txt is sha1('bar') and forms no group, which is what
// makes "how many groups" a meaningful question.
const DUP_HASH = '0beec7b5ea3f0fdbc95d0dd47f3c5bc275da8a33'

// Stub hashes used only by the "Only matching" filter test, which needs
// one fully-verified and one mixed group to exercise the checkbox.
const H1 = '0b4e7a0e5fe84ad35fb5f95b9ceeac79'
const H2 = '7c6a180b36896a0a8c02787eeafb0e4c'

const findAllDuplicatesUrl = '**/ocs/v2.php/apps/file_checksum_search/api/v1/duplicates*'
const recalcUrl = '**/ocs/v2.php/apps/file_checksum_search/api/v1/file/*/recalc*'

const file = ( fileid, path ) => ( { fileid, path, name: path.split( '/' ).pop() } )

const webdavUrl = ( path ) => `/remote.php/dav/files/${ adminUser }${ path }`

// The group this run created, found by its hash rather than by position:
// the page may legitimately show others, and asserting on the first one
// would make this spec depend on an ordering nothing promises.
const ownGroup = () => cy.get( '.db-group', { timeout: FIND_TIMEOUT } )
	.filter( ( _i, el ) => el.querySelector( '.db-hash' )?.textContent?.trim() === DUP_HASH )

describe( 'FCIAS Duplicates page', () => {
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

			cy.request( {
				method: 'MKCOL',
				url: webdavUrl( `/${ dupDir }` ),
				auth: { user: adminUser, pass: adminPassword },
				failOnStatusCode: false,
			} )

			for ( const { name, content } of files ) {
				cy.request( {
					method: 'PUT',
					url: webdavUrl( `/${ dupDir }/${ name }` ),
					auth: { user: adminUser, pass: adminPassword },
					headers: { 'Content-Type': 'text/plain' },
					body: content,
				} )
			}

			// Reset first, then state the hashes: the files have to exist
			// before the import can resolve their paths, and the reset has to
			// come before the import or it would clear what was just stated.
			cy.resetFciasState( occ )
			cy.importFciasFixture( occ, 'duplicates', adminUser )
		} )
	} )

	beforeEach( () => {
		cy.login( adminUser, adminPassword )
	} )

	it( 'loads the duplicates page and shows this run\'s duplicate group', () => {
		cy.visit( DUPLICATES_URL )

		cy.get( '#fcias-duplicates', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '.db-tab.is-active' ).should( 'exist' )
		ownGroup().should( 'have.length', 1 )
		ownGroup().find( '.db-count' ).should( 'contain', '2' )
	} )

	it( 'verifies hashes and the group comes back matching', () => {
		cy.visit( DUPLICATES_URL )

		ownGroup().should( 'have.length', 1 )
		cy.get( '.verify-btn' ).click()

		// Identical content and a stated hash that agrees with it, so every
		// file verifies. The status class is the assertion rather than the
		// button's caption, which is a translatable string.
		ownGroup().find( '.db-group-header-status.verified', { timeout: FIND_TIMEOUT } )
			.should( 'exist' )
		ownGroup().find( '.db-group-header-status.mixed' ).should( 'not.exist' )
	} )

	it( 'stops verifying when the rate limit answers, and says so', () => {
		// Stubbed, because the real limit is 20 recalculations a minute and
		// a test that reached it honestly would take a minute to do it. The
		// contract being checked is the frontend's: on a 429 it stops where
		// it is rather than marking every file it never asked about as a
		// mismatch, which is what it used to do.
		cy.intercept( 'POST', recalcUrl, {
			statusCode: 429,
			body: { success: false, error: 'Too many requests' },
		} ).as( 'recalcLimited' )

		cy.visit( DUPLICATES_URL )
		ownGroup().should( 'have.length', 1 )
		cy.get( '.verify-btn' ).click()

		cy.wait( '@recalcLimited' )

		// It says why, rather than leaving a half-verified list looking
		// finished.
		cy.get( '.db-error', { timeout: FIND_TIMEOUT } ).should( 'exist' ).and( 'not.be.empty' )

		// And nothing is marked either way: a file it never got an answer
		// for is not a mismatch.
		cy.get( '.db-group-header-status' ).should( 'not.exist' )
	} )

	// The switch is the sudoer boundary made visible: the administrator gets
	// it, an account nobody named does not. What happens after the switch —
	// core's password dialog — is exercised by the AP's closing full run.
	it( 'offers "Show all users" to the administrator', () => {
		cy.visit( DUPLICATES_URL )
		cy.get( '[data-testid="fcias-show-all"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
	} )

	it( 'does not offer "Show all users" to an account nobody named', () => {
		cy.env( [ 'NC_ADMIN_USER', 'NC_ADMIN_PASSWORD' ] ).then( ( env ) => {
			const admin = { user: env.NC_ADMIN_USER || 'admin', password: env.NC_ADMIN_PASSWORD || 'admin' }
			cy.fciasMakeAccount( admin, 'nobody' ).then( ( account ) => {
				cy.login( account.user, account.password )
				cy.visit( DUPLICATES_URL )
				cy.get( '[data-testid="fcias-only-matching"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
				cy.get( '[data-testid="fcias-show-all"]' ).should( 'not.exist' )
				cy.fciasDeleteAccount( admin, account.user )
			} )
		} )
	} )

	it( 'filters groups with the "Only matching" checkbox', () => {
		// Stubbed, deliberately: this asserts the frontend's contract with a
		// response shape — one fully-verified group and one mixed — that the
		// server would only produce from files whose content had been made to
		// disagree with their stored hashes. The server side of verification
		// is the test above.
		cy.intercept( 'GET', findAllDuplicatesUrl, {
			duplicates: [
				{
					algo: 'sha1',
					hash_value: H1,
					file_count: 2,
					files: [
						file( 1001, '/folder/one.txt' ),
						file( 1002, '/folder/two.txt' ),
					],
					match_count: 2,
					mismatch_count: 0,
				},
				{
					algo: 'sha256',
					hash_value: H2,
					file_count: 2,
					files: [
						file( 2001, '/other/a.txt' ),
						file( 2002, '/other/b.txt' ),
					],
					match_count: 1,
					mismatch_count: 1,
				},
			],
		} ).as( 'duplicates' )

		cy.visit( DUPLICATES_URL )
		cy.get( '.db-group', { timeout: FIND_TIMEOUT } ).should( 'have.length', 2 )

		// Only the fully-verified group remains when "Only matching" is set.
		// NcCheckboxRadioSwitch hides its native input behind a styled label, so
		// Cypress must be told the click on the hidden input is intended.
		cy.get( '[data-testid="fcias-only-matching"] input[type="checkbox"]' ).check( { force: true } )
		cy.get( '.db-group' ).should( 'have.length', 1 )
		cy.get( '.db-hash' ).should( 'contain', H1 ).and( 'not.contain', H2 )

		// Unchecking restores both groups.
		cy.get( '[data-testid="fcias-only-matching"] input[type="checkbox"]' ).uncheck( { force: true } )
		cy.get( '.db-group' ).should( 'have.length', 2 )
	} )
} )
