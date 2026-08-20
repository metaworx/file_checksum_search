/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E tests for the global duplicates page.
 */

const appId = 'file_checksum_search'

// Deterministic stub hashes for the duplicate groups.
const H1 = '0b4e7a0e5fe84ad35fb5f95b9ceeac79'
const H2 = '7c6a180b36896a0a8c02787eeafb0e4c'

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

const findAllDuplicatesUrl = '**/ocs/v2.php/apps/file_checksum_search/api/v1/duplicates*'
const recalcUrl = '**/ocs/v2.php/apps/file_checksum_search/api/v1/file/*/recalc*'

const file = ( fileid, path ) => ( { fileid, path, name: path.split( '/' ).pop() } )

describe( 'FCIAS Duplicates page', () => {
	before( () => {
		occ = Cypress.env( 'occ' ) || occ
		adminUser = Cypress.env( 'NC_ADMIN_USER' ) || adminUser
		adminPassword = Cypress.env( 'NC_ADMIN_PASSWORD' ) || adminPassword
		cy.exec( `${ occ } app:enable ${ appId }`, { failOnNonZeroExit: false } )
	} )

	beforeEach( () => {
		cy.login( adminUser, adminPassword )
	} )

	it( 'loads the duplicates page', () => {
		cy.intercept( 'GET', findAllDuplicatesUrl, { duplicates: [] } ).as( 'duplicates' )

		cy.visit( DUPLICATES_URL )

		cy.get( '#fcias-duplicates', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.contains( 'button', 'Duplicates' ).should( 'exist' )
		cy.contains( 'button', 'Verify hashes' ).should( 'exist' )
		cy.contains( 'label', 'Only matching' ).should( 'exist' )
	} )

	it( 'verifies hashes when the "Verify hashes" button is clicked', () => {
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
				},
			],
		} ).as( 'duplicates' )
		cy.intercept( 'POST', recalcUrl, { success: true, hash: H1, algo: 'sha1' } ).as( 'recalc' )

		cy.visit( DUPLICATES_URL )
		cy.get( '.db-group', { timeout: FIND_TIMEOUT } ).should( 'have.length', 1 )

		cy.contains( 'button', 'Verify hashes' ).click()

		// All stubbed files match, so the button flips to the verified state
		// and one recalc request was issued per file.
		cy.contains( 'button', '✓ Verified', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '@recalc.all' ).should( 'have.length', 2 )
	} )

	it( 'filters groups with the "Only matching" checkbox', () => {
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
		cy.contains( 'label', 'Only matching' ).find( 'input[type="checkbox"]' ).check()
		cy.get( '.db-group' ).should( 'have.length', 1 )
		cy.get( '.db-hash' ).should( 'contain', H1 ).and( 'not.contain', H2 )

		// Unchecking restores both groups.
		cy.contains( 'label', 'Only matching' ).find( 'input[type="checkbox"]' ).uncheck()
		cy.get( '.db-group' ).should( 'have.length', 2 )
	} )
} )
