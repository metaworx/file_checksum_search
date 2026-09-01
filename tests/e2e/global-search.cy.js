/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E test for the unified (global) search provider.
 *
 * Builds its own state and depends on no other spec. It used to search
 * for a hash that checksums.cy.js happened to have indexed, which made it
 * fail for reasons in a spec it never mentions — and pinned that spec's
 * file contents in place, since changing them changed this constant.
 */

const appId = 'file_checksum_search'

// The file this spec creates, and the hash the fixture states for it.
// sha1('FCIAS e2e search needle') — nothing else on the instance has it,
// which is what lets the assertion be "these results" and not "at least
// one". Keep the three in step: content, hash, and fixtures/search.json.
const searchDir = 'fcias-e2e-search'
const searchFile = 'needle.txt'
const searchContent = 'FCIAS e2e search needle'
const SHA1 = '029548e960064e7ded4bb37f147ad2f64bfb3bae'

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

const SEARCH_INPUT = '[data-cy-unified-search-input]'

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
		} ).then( () => {
			cy.exec( `${ occ } app:enable ${ appId }`, { failOnNonZeroExit: false } )

			cy.request( {
				method: 'MKCOL',
				url: `/remote.php/dav/files/${ adminUser }/${ searchDir }`,
				auth: { user: adminUser, pass: adminPassword },
				failOnStatusCode: false,
			} )
			cy.request( {
				method: 'PUT',
				url: `/remote.php/dav/files/${ adminUser }/${ searchDir }/${ searchFile }`,
				auth: { user: adminUser, pass: adminPassword },
				headers: { 'Content-Type': 'text/plain' },
				body: searchContent,
			} )

			// No reset here. The hash this searches for belongs to one file
			// this spec owns, so what else the instance holds cannot change
			// the answer — and resetting would throw away the state the
			// duplicates spec built before it.
			cy.importFciasFixture( occ, 'search', adminUser )
		} )
	} )

	beforeEach( () => {
		cy.login( adminUser, adminPassword )
	} )

	// The unified-search field is a controlled Vue input that is re-rendered as
	// results stream in, and typing into it a character at a time is unreliable:
	// runs have ended up with only the first character in the field, and
	// cy.clear() can leave a residue that the following type() appends to,
	// producing a query like "ddeadbeef...". Either way the search then
	// correctly reports no matches and the test fails somewhere further down.
	// Setting the value through the native setter and dispatching a single
	// input event is atomic, and the input event is what the component binds to.
	const enterQuery = ( value ) => {
		cy.get( SEARCH_INPUT ).then( ( $input ) => {
			const el = $input[ 0 ]
			const setValue = Object.getOwnPropertyDescriptor(
				el.ownerDocument.defaultView.HTMLInputElement.prototype,
				'value',
			).set
			setValue.call( el, value )
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) )
		} )
		cy.get( SEARCH_INPUT ).should( 'have.value', value )
	}

	it( 'lists the File Checksums provider and finds files by hash', () => {
		// The provider request is the only reliable signal that a query has
		// actually been answered: the input is debounced, so asserting straight
		// after typing races the search.
		cy.intercept(
			'GET',
			'**/search/providers/file_checksum_search_provider/search**',
		).as( 'hashSearch' )

		cy.visit( '/index.php/apps/files/' )

		// Open the global search (trigger markup differs slightly across NC 33/34).
		cy.get( '.unified-search-menu button', { timeout: FIND_TIMEOUT } ).first().click()

		// FCIAS appears under the "Places" provider filter.
		cy.get( '[data-cy-unified-search-filter="places"] button' ).click()
		cy.contains( 'File Checksums', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		// Dismiss the popover by focusing the search input.
		cy.get( SEARCH_INPUT ).click()

		// A valid but non-existent hash yields no file results.
		enterQuery( MISSING )
		cy.wait( '@hashSearch', { timeout: FIND_TIMEOUT } )
		cy.get( '.unified-search-modal', { timeout: FIND_TIMEOUT } )
			.should( 'not.contain', searchFile )

		// The real hash lists the indexed files.
		enterQuery( SHA1 )
		cy.wait( '@hashSearch', { timeout: FIND_TIMEOUT } )

		// Scoped to the modal: an unscoped cy.contains() also matches the Files
		// list rendered behind the overlay, which passes even when the search
		// returned nothing.
		cy.get( '.unified-search-modal', { timeout: FIND_TIMEOUT } )
			.should( 'contain', searchFile )

		// Results link to the file details view (opendetails=true), not the file itself.
		cy.get( '.unified-search-modal a[href*="opendetails=true"][href*="openfile=false"]', { timeout: FIND_TIMEOUT } )
			.should( 'exist' )
	} )
} )
