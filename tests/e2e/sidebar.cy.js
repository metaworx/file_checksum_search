/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E tests for the Files app checksums sidebar tab.
 */

const appId = 'file_checksum_search'

// Deterministic stub hashes rendered in the sidebar.
const SHA1 = '0b4e7a0e5fe84ad35fb5f95b9ceeac79'
const MD5 = '827ccb0eea8a706c4c34a16891f84e7b'

// Default to the CI layout: Cypress runs in the repo root and the
// Nextcloud checkout lives in ./nextcloud. Override via CYPRESS_occ
// for local/ddev runs (e.g. CYPRESS_occ="php /var/www/html/occ").
let occ = 'php nextcloud/occ'
let adminUser = 'admin'
let adminPassword = 'admin'

// Allow a generous timeout when locating UI elements: the first app/page
// request can be slow on a cold PHP worker.
const FIND_TIMEOUT = 60000

const testDir = `sidebar-e2e-${ Date.now() }`
const fileName = 'checksums-e2e.txt'
const filePath = `/${ testDir }/${ fileName }`

let fileId = null

const getHashesUrl = '**/ocs/v2.php/apps/file_checksum_search/api/v1/file/*/hashes'
const findDuplicatesUrl = '**/ocs/v2.php/apps/file_checksum_search/api/v1/file/*/duplicates'
const recalcUrl = '**/ocs/v2.php/apps/file_checksum_search/api/v1/file/*/recalc*'

const webdavUrl = ( path ) => `/remote.php/dav/files/${ adminUser }${ path }`

describe( 'FCIAS Files sidebar', () => {
	before( () => {
		occ = Cypress.env( 'occ' ) || occ
		adminUser = Cypress.env( 'NC_ADMIN_USER' ) || adminUser
		adminPassword = Cypress.env( 'NC_ADMIN_PASSWORD' ) || adminPassword

		cy.exec( `${ occ } app:enable ${ appId }`, { failOnNonZeroExit: false } )

		// Create a real file via WebDAV so a genuine file node exists for the
		// sidebar tab registration.
		cy.request( {
			method: 'MKCOL',
			url: webdavUrl( `/${ testDir }` ),
			auth: { user: adminUser, pass: adminPassword },
			failOnStatusCode: false,
		} )
		cy.request( {
			method: 'PUT',
			url: webdavUrl( filePath ),
			auth: { user: adminUser, pass: adminPassword },
			headers: { 'Content-Type': 'text/plain' },
			body: 'FCIAS e2e checksum test content',
		} )

		// Resolve the numeric file id from the DAV properties.
		cy.request( {
			method: 'PROPFIND',
			url: webdavUrl( filePath ),
			auth: { user: adminUser, pass: adminPassword },
			headers: { Depth: '0' },
		} ).then( ( res ) => {
			const match = String( res.body ).match( /<oc:fileid>(\d+)<\/oc:fileid>/ )
			fileId = match ? Number( match[ 1 ] ) : null
			expect( fileId, 'DAV PROPFIND should return oc:fileid' ).to.be.a( 'number' ).and.greaterThan( 0 )
		} )
	} )

	beforeEach( () => {
		cy.login( adminUser, adminPassword )
	} )

	it( 'opens a file and shows checksums in the sidebar tab', () => {
		cy.intercept( 'GET', getHashesUrl, {
			hashes: [
				{ algo: 'sha1', hash: SHA1, updated_at: '2026-01-01T00:00:00+00:00' },
				{ algo: 'md5', hash: MD5, updated_at: '2026-01-01T00:00:00+00:00' },
			],
		} ).as( 'getHashes' )

		cy.visit( `/index.php/apps/files/files/${ fileId }` )

		cy.contains( 'button', 'Checksums', { timeout: FIND_TIMEOUT } ).click()
		cy.wait( '@getHashes' )

		cy.get( '.fcias-hash-table .fcias-selectable-hash', { timeout: FIND_TIMEOUT } )
			.should( 'have.length', 2 )
		cy.get( '.fcias-hash-table' ).should( 'contain', SHA1 ).and( 'contain', MD5 )

		cy.get( '.fcias-recalc-btn' ).should( 'have.length', 2 )
		cy.contains( '.fcias-dup-btn', 'Find duplicates' ).should( 'exist' )
	} )

	it( 'recalculates a hash and finds duplicates from the sidebar', () => {
		cy.intercept( 'GET', getHashesUrl, {
			hashes: [ { algo: 'sha1', hash: SHA1 } ],
		} ).as( 'getHashes' )
		cy.intercept( 'POST', recalcUrl, { success: true, hash: SHA1, algo: 'sha1' } ).as( 'recalc' )
		cy.intercept( 'GET', findDuplicatesUrl, {
			duplicates: [
				{
					algo: 'sha1',
					hash_value: SHA1,
					files: [ { fileid: fileId, path: filePath } ],
				},
			],
		} ).as( 'findDuplicates' )

		cy.visit( `/index.php/apps/files/files/${ fileId }` )
		cy.contains( 'button', 'Checksums', { timeout: FIND_TIMEOUT } ).click()
		cy.wait( '@getHashes' )

		cy.get( '.fcias-recalc-btn[data-algo="sha1"]' ).click()
		cy.wait( '@recalc' )
		cy.get( '.fcias-recalc-btn[data-algo="sha1"]' ).should( 'contain', 'Recalc SHA-1' )

		cy.get( '.fcias-dup-btn' ).click()
		cy.wait( '@findDuplicates' )
		cy.get( '.fcias-dup-results' ).should( 'contain', fileName )
	} )
} )
