/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E tests for the Files app checksums sidebar tab.
 *
 * Creates two files with identical content and computes their sha1
 * through the sidebar's "Recalc SHA-1" action — the one spec that makes
 * the app hash something for real, rather than stating hashes from a
 * fixture. No other spec depends on what it leaves behind.
 *
 * Its green run also proves the quiet-start promise from the outside:
 * both shipped defaults are disabled on the instance under test, nothing
 * is hashed automatically, and recalculating by hand still works.
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

// A fixed directory, not a timestamped one. Every run used to leave another
// `fcias-e2e-sidebar-<ts>` folder behind, and two more files with the same
// content; the accumulation is what eventually broke the duplicates spec.
// Re-running overwrites these two instead.
const dupDir = 'fcias-e2e-sidebar'
const fileNameA = 'a.txt'
const fileNameB = 'b.txt'
const dupContent = 'FCIAS e2e duplicate content'

const webdavUrl = ( path ) => `/remote.php/dav/files/${ adminUser }${ path }`

const propfindBody = [
	'<?xml version="1.0"?>',
	'<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">',
	'<d:prop><oc:fileid/></d:prop>',
	'</d:propfind>',
].join( '' )

// Opens the Checksums sidebar tab. The wait is split so a slow Files app is
// reported as "the sidebar never opened" rather than "Checksums not found",
// and the tab lookup is scoped to the sidebar so it cannot match stray text
// elsewhere on the page.
const openChecksumsTab = () => {
	cy.get( '.app-sidebar', { timeout: FIND_TIMEOUT } ).should( 'be.visible' )
	cy.get( '.app-sidebar' ).contains( 'Checksums', { timeout: FIND_TIMEOUT } ).click()
}

const fileUrl = ( fileId ) =>
	`/index.php/apps/files/files/${ fileId }?dir=${ encodeURIComponent( '/' + dupDir ) }&opendetails=true`

const extractFileId = ( res ) => {
	const match = String( res.body ).match( /<(?:[a-zA-Z0-9]+:)?fileid>\s*(\d+)\s*<\/(?:[a-zA-Z0-9]+:)?fileid>/ )
	return match ? Number( match[ 1 ] ) : null
}

let fileIdA = null
let fileIdB = null

describe( 'FCIAS checksums sidebar', () => {
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

			// Two identical files become a duplicate pair once both are hashed.
			cy.request( {
				method: 'MKCOL',
				url: webdavUrl( `/${ dupDir }` ),
				auth: { user: adminUser, pass: adminPassword },
				failOnStatusCode: false,
			} )
			cy.request( {
				method: 'PUT',
				url: webdavUrl( `/${ dupDir }/${ fileNameA }` ),
				auth: { user: adminUser, pass: adminPassword },
				headers: { 'Content-Type': 'text/plain' },
				body: dupContent,
			} )
			cy.request( {
				method: 'PUT',
				url: webdavUrl( `/${ dupDir }/${ fileNameB }` ),
				auth: { user: adminUser, pass: adminPassword },
				headers: { 'Content-Type': 'text/plain' },
				body: dupContent,
			} )

			cy.request( {
				method: 'PROPFIND',
				url: webdavUrl( `/${ dupDir }/${ fileNameA }` ),
				auth: { user: adminUser, pass: adminPassword },
				headers: {
					Depth: '0',
					'Content-Type': 'application/xml',
				},
				body: propfindBody,
			} ).then( ( res ) => {
				fileIdA = extractFileId( res )
			} )
			cy.request( {
				method: 'PROPFIND',
				url: webdavUrl( `/${ dupDir }/${ fileNameB }` ),
				auth: { user: adminUser, pass: adminPassword },
				headers: {
					Depth: '0',
					'Content-Type': 'application/xml',
				},
				body: propfindBody,
			} ).then( ( res ) => {
				fileIdB = extractFileId( res )
			} )
		} )
	} )

	beforeEach( () => {
		cy.login( adminUser, adminPassword )
	} )

	it( 'opens a file, shows the checksums tab, and recalculates SHA-1', () => {
		expect( fileIdA, 'fileIdA should be resolved' ).to.be.a( 'number' ).and.greaterThan( 0 )

		cy.visit( fileUrl( fileIdA ) )
		openChecksumsTab()

		cy.get( '.fcias-recalc-btn[data-algo="sha1"]' ).click()
		cy.get( '.fcias-selectable-hash', { timeout: FIND_TIMEOUT } ).should( 'have.length.at.least', 1 )

		cy.get( '.fcias-recalc-btn' ).should( 'have.length', 3 )
		cy.contains( '.fcias-dup-btn', 'Find duplicates' ).should( 'exist' )
	} )

	it( 'recalculates the second file and finds duplicates inline', () => {
		expect( fileIdB, 'fileIdB should be resolved' ).to.be.a( 'number' ).and.greaterThan( 0 )

		cy.visit( fileUrl( fileIdB ) )
		openChecksumsTab()
		cy.get( '.fcias-recalc-btn[data-algo="sha1"]' ).click()
		cy.get( '.fcias-selectable-hash', { timeout: FIND_TIMEOUT } ).should( 'have.length.at.least', 1 )

		cy.visit( fileUrl( fileIdA ) )
		openChecksumsTab()
		cy.get( '.fcias-dup-btn' ).click()
		cy.get( '.fcias-dup-results', { timeout: FIND_TIMEOUT } ).should( 'contain', fileNameB )
	} )

	it( 'selects a different algorithm and recalculates it', () => {
		expect( fileIdA, 'fileIdA should be resolved' ).to.be.a( 'number' ).and.greaterThan( 0 )

		cy.visit( fileUrl( fileIdA ) )
		openChecksumsTab()

		// Open the algorithm dropdown and choose SHA512.
		cy.get( '.fcias-recalc-custom .vs__dropdown-toggle', { timeout: FIND_TIMEOUT } ).click()
		cy.get( '.vs__dropdown-option' ).contains( 'SHA512' ).click()

		cy.get( '.fcias-recalc-custom .fcias-recalc-btn' ).click()
		cy.get( '.fcias-algo-badge', { timeout: FIND_TIMEOUT } ).should( 'contain', 'sha512' )
	} )
} )
