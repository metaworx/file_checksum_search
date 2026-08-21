/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E test for the unified (global) search provider.
 *
 * Depends on checksums.cy.js (which runs first) having indexed the
 * sha1 of the shared duplicate content.
 */

const appId = 'file_checksum_search'

// sha1('FCIAS e2e duplicate content') — the token indexed by checksums.cy.js.
const SHA1 = '5853843c7e93df9018a3cf5df1fda7b85d6ca07b'

// A well-formed 40-char hex hash that is not present in the index.
const MISSING = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef'

// Default to the CI layout: Cypress runs in the repo root and the
// Nextcloud checkout lives in ./nextcloud. Override via CYPRESS_occ
// for local/ddev runs (e.g. CYPRESS_occ="php /var/www/html/occ").
let occ = 'php nextcloud/occ'
let adminUser = 'admin'
let adminPassword = 'admin'

// Allow a generous timeout when locating UI elements: the first app/page
// request can be slow on a cold PHP worker.
const FIND_TIMEOUT = 60000

describe( 'FCIAS global search', () => {
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
		} )
	} )

	beforeEach( () => {
		cy.exec( `${ occ } app:enable ${ appId }`, { failOnNonZeroExit: false } )
		cy.login( adminUser, adminPassword )
	} )

	it( 'lists the File Checksums provider and finds files by hash', () => {
		cy.visit( '/index.php/apps/files/' )

		// Open the global search (trigger markup differs slightly across NC 33/34).
		cy.get( '.unified-search-menu button', { timeout: FIND_TIMEOUT } ).first().click()

		// FCIAS appears under the "Places" provider filter.
		cy.get( '[data-cy-unified-search-filter="places"] button' ).click()
		cy.contains( 'File Checksums', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		// Dismiss the popover by focusing the search input.
		cy.get( '[data-cy-unified-search-input]' ).click()

		// A valid but non-existent hash yields no file results.
		cy.get( '[data-cy-unified-search-input]' ).type( MISSING )
		cy.get( '.unified-search-modal', { timeout: FIND_TIMEOUT } )
			.should( 'not.contain', 'a.txt' )
			.and( 'not.contain', 'b.txt' )

		// The real hash lists the indexed files.
		cy.get( '[data-cy-unified-search-input]' ).clear().type( SHA1 )
		cy.contains( 'a.txt', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.contains( 'b.txt', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		// Results link to the file details view (opendetails=true), not the file itself.
		cy.get( '.unified-search-modal a[href*="opendetails=true"][href*="openfile=false"]', { timeout: FIND_TIMEOUT } )
			.should( 'exist' )
	} )
} )
