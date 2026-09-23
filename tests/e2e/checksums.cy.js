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
/** sha1(dupContent): what the way across accounts carries in its address. */
const DUP_SHA1 = '5853843c7e93df9018a3cf5df1fda7b85d6ca07b'

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
	// The tab's registered id, not its caption: the sidebar is the one part
	// of this app that already goes through t(), so its label is the first
	// thing translation will move.
	cy.get( '#tab-button-file_checksum_search-checksums', { timeout: FIND_TIMEOUT } ).click()
}

const fileUrl = ( fileId ) =>
	`/index.php/apps/files/files/${ fileId }?dir=${ encodeURIComponent( '/' + dupDir ) }&opendetails=true`

const extractFileId = ( res ) => {
	const match = String( res.body ).match( /<(?:[a-zA-Z0-9]+:)?fileid>\s*(\d+)\s*<\/(?:[a-zA-Z0-9]+:)?fileid>/ )
	return match ? Number( match[ 1 ] ) : null
}

let fileIdA = null
let fileIdB = null

// The sidebar's first quick button is the acting user's preference, else the
// instance default, and these specs click it by name. Both are pinned in
// before() and put back in after(): this spec runs as the administrator, an
// account that outlives the run and can have a preference of its own.
let defaultAlgorithmWas = ''
let preferredAlgorithmWas = ''

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

			cy.fciasDefaultAlgorithm(
				{ user: adminUser, password: adminPassword },
				'sha1',
			).then( ( previous ) => {
				defaultAlgorithmWas = previous
			} )
			cy.fciasPreferredAlgorithm(
				{ user: adminUser, password: adminPassword },
				'',
			).then( ( previous ) => {
				preferredAlgorithmWas = previous
			} )

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

	after( () => {
		cy.fciasDefaultAlgorithm(
			{ user: adminUser, password: adminPassword },
			defaultAlgorithmWas,
		)
		cy.fciasPreferredAlgorithm(
			{ user: adminUser, password: adminPassword },
			preferredAlgorithmWas,
		)
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

		// The quick buttons are composed per file — the user's preference or the
		// default, then the governing rule's first other algorithm — so there are
		// one or two of them, plus the picker's own button.
		cy.get( '.fcias-recalc-btn' ).should( 'have.length.at.least', 2 )
		cy.get( '.fcias-dup-btn' ).should( 'exist' )
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

	// The way across accounts is one link, offered to those who may look
	// across accounts and to nobody else. It lands on the Duplicates page's
	// Others tab with this file's hash filled in and the whole reach named,
	// so the group is already there — the administrator's own two copies
	// as their paths behind a house, and the other account's as a location
	// behind a person, as text, since a link to it would open to nothing.
	it( 'offers the administrator the way across accounts, and an account nobody named not', () => {
		expect( fileIdA, 'fileIdA should be resolved' ).to.be.a( 'number' ).and.greaterThan( 0 )

		cy.env( [ 'NC_ADMIN_USER', 'NC_ADMIN_PASSWORD' ] ).then( ( env ) => {
			const admin = { user: env.NC_ADMIN_USER || 'admin', password: env.NC_ADMIN_PASSWORD || 'admin' }

			cy.fciasMakeAccount( admin, 'owner' ).then( ( owner ) => {
				// A third copy, in the other account's tree, hashed by that
				// account over the API — the sidebar computes its own two the
				// same way. Cookies first: cy.request() shares the browser's
				// jar, and with the administrator's session in it these
				// writes would be theirs.
				cy.clearCookies()
				const theirs = ( path ) => `/remote.php/dav/files/${ owner.user }${ path }`
				const asThem = { user: owner.user, pass: owner.password }
				cy.request( { method: 'MKCOL', url: theirs( `/${ dupDir }` ), auth: asThem, failOnStatusCode: false } )
				cy.request( {
					method: 'PUT',
					url: theirs( `/${ dupDir }/${ fileNameA }` ),
					auth: asThem,
					headers: { 'Content-Type': 'text/plain' },
					body: dupContent,
				} )
				cy.request( {
					method: 'PROPFIND',
					url: theirs( `/${ dupDir }/${ fileNameA }` ),
					auth: asThem,
					headers: { Depth: '0', 'Content-Type': 'application/xml' },
					body: propfindBody,
				} ).then( ( res ) => {
					const theirId = extractFileId( res )
					expect( theirId, 'the other account\'s copy should have an id' ).to.be.a( 'number' ).and.greaterThan( 0 )
					cy.request( {
						method: 'POST',
						url: `/ocs/v2.php/apps/file_checksum_search/api/v1/file/${ theirId }/recalc?algo=sha1`,
						auth: asThem,
						headers: { 'OCS-APIRequest': 'true' },
					} ).its( 'body.success' ).should( 'eq', true )
				} )

				// The administrator's file A, hashed by the first case; the
				// button beside Find duplicates, and where it points.
				cy.login( adminUser, adminPassword )
				cy.visit( fileUrl( fileIdA ) )
				openChecksumsTab()
				cy.get( '.fcias-dup-btn', { timeout: FIND_TIMEOUT } ).should( 'exist' )
				cy.get( '[data-testid="fcias-dup-across"]', { timeout: FIND_TIMEOUT } )
					.should( 'have.attr', 'href' )
					.and( 'include', `#others?hash=${ DUP_SHA1 }&algo=sha1&all=1` )

				// Follow it by address rather than by click: it opens a new
				// tab, which a spec cannot look into.
				cy.get( '[data-testid="fcias-dup-across"]' ).invoke( 'attr', 'href' ).then( ( href ) => {
					cy.visit( href )
				} )
				const group = () => cy.get( '[data-testid="fcias-others"] .db-group', { timeout: FIND_TIMEOUT } )
				group().should( 'have.length', 1 )
				group().find( '.db-hash' ).should( 'contain', DUP_SHA1 )
				group().find( '.db-group-header' ).click()
				group().find( '.db-file-label', { timeout: FIND_TIMEOUT } ).should( 'have.length', 3 )
				group().find( '.db-file-label > a .fcias-location-icon[data-kind="own"]' ).should( 'have.length', 2 )
				group().find( '.db-file-label > .db-file-unopenable .fcias-location-icon[data-kind="home"]' ).should( 'have.length', 1 )
				group().find( '.db-file-label > .db-file-unopenable' )
					.should( 'contain', `/${ owner.user }/files/${ dupDir }/${ fileNameA }` )

				cy.fciasDeleteAccount( admin, owner.user )
			} )

			// An account nobody named gets the section, and no way across.
			cy.fciasMakeAccount( admin, 'nobody' ).then( ( nobody ) => {
				cy.clearCookies()
				const theirs = ( path ) => `/remote.php/dav/files/${ nobody.user }${ path }`
				const asThem = { user: nobody.user, pass: nobody.password }
				cy.request( {
					method: 'PUT',
					url: theirs( `/${ fileNameA }` ),
					auth: asThem,
					headers: { 'Content-Type': 'text/plain' },
					body: dupContent,
				} )
				cy.request( {
					method: 'PROPFIND',
					url: theirs( `/${ fileNameA }` ),
					auth: asThem,
					headers: { Depth: '0', 'Content-Type': 'application/xml' },
					body: propfindBody,
				} ).then( ( res ) => {
					const theirId = extractFileId( res )
					cy.login( nobody.user, nobody.password )
					cy.visit( `/index.php/apps/files/files/${ theirId }?opendetails=true` )
					openChecksumsTab()
					cy.get( '.fcias-dup-btn', { timeout: FIND_TIMEOUT } ).should( 'exist' )
					cy.get( '[data-testid="fcias-dup-across"]' ).should( 'not.exist' )
				} )
				cy.fciasDeleteAccount( admin, nobody.user )
			} )
		} )
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
