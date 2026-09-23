/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E tests for the global duplicates page.
 *
 * Builds its own state and depends on no other spec. It used to read
 * whatever checksums.cy.js had left behind, which is how it rotted: each
 * run added two more files with the same content, and by 145 of them
 * verification was hitting the per-user recalculation rate limit
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

const recalcUrl = '**/ocs/v2.php/apps/file_checksum_search/api/v1/file/*/recalc*'

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

	it( 'verifies a group through its own button and it comes back matching', () => {
		cy.visit( DUPLICATES_URL )

		ownGroup().should( 'have.length', 1 )
		// Per group, never per page: reading every file costs time and, on
		// metered storage, money.
		ownGroup().find( '.db-verify-all' ).click()

		// Identical content and a stated hash that agrees with it, so every
		// file verifies. The status class is the assertion rather than the
		// button's caption, which is a translatable string.
		ownGroup().find( '.db-group-header-status.verified', { timeout: FIND_TIMEOUT } )
			.should( 'exist' )
		ownGroup().find( '.db-group-header-status.mixed' ).should( 'not.exist' )
	} )

	it( 'verifies one file on its own', () => {
		cy.visit( DUPLICATES_URL )

		ownGroup().should( 'have.length', 1 )
		ownGroup().find( '.db-group-header' ).click()
		ownGroup().find( '.db-file-item' ).first().find( '.db-verify-file' ).click()

		// That file answers; the group as a whole is not claimed as verified
		// while its other file has never been read.
		ownGroup().find( '.db-file-item .db-verified', { timeout: FIND_TIMEOUT } )
			.should( 'have.length', 1 )
		ownGroup().find( '.db-group-header-status' ).should( 'not.exist' )
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
		ownGroup().find( '.db-verify-all' ).click()

		cy.wait( '@recalcLimited' )

		// It says why, rather than leaving a half-verified list looking
		// finished.
		cy.get( '.db-error', { timeout: FIND_TIMEOUT } ).should( 'exist' ).and( 'not.be.empty' )

		// And nothing is marked either way: a file it never got an answer
		// for is not a mismatch.
		cy.get( '.db-group-header-status' ).should( 'not.exist' )
	} )

	// The Others tab is the sudoer boundary made visible: the
	// administrator gets it, an account nobody named does not. It is a tab
	// rather than a switch because it shows other people's files, and that is
	// not a state an ordinary listing should slip into. Core's password dialog
	// is skipped within thirty minutes of a login, which a Cypress session is.
	it( 'offers the Others tab to the administrator', () => {
		cy.visit( DUPLICATES_URL )
		cy.get( '.db-tab[data-tab="others"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
	} )

	// Every control says what it is, with a label a reader can see and a
	// help button beside it. By the `for`, not the caption.
	it( 'labels the algorithm, min, limit and hash controls', () => {
		cy.visit( DUPLICATES_URL )
		for ( const id of [
			'fcias-duplicates-algorithm',
			'fcias-duplicates-min',
			'fcias-duplicates-limit',
			'fcias-duplicates-hash',
		] ) {
			cy.get( `label[for="${ id }"]`, { timeout: FIND_TIMEOUT } ).should( 'exist' )
			cy.get( `#${ id }` ).should( 'exist' )
		}
		cy.get( '.db-label .fcias-help-icon' ).should( 'have.length', 4 )
	} )

	// The tab asks the server who may be named, and shows the listing on its
	// own amber ground with nothing loaded until a target is chosen.
	it( 'opens the Others tab with a picker and nothing named yet', () => {
		cy.intercept( 'GET', '**/apps/file_checksum_search/api/v1/sudo/selectable*' ).as( 'selectable' )
		cy.visit( DUPLICATES_URL )

		cy.get( '.db-tab[data-tab="others"]', { timeout: FIND_TIMEOUT } ).click()
		cy.wait( '@selectable', { timeout: FIND_TIMEOUT } )

		cy.get( '[data-testid="fcias-others"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '[data-testid="fcias-target-picker"]' ).should( 'exist' )
		cy.get( '[data-testid="fcias-awaiting-scope"]' ).should( 'exist' )
		// Its own controls, so the ordinary tab's filters are left alone.
		cy.get( '#fcias-others-min' ).should( 'exist' )
	} )

	// The filter narrows to a group this run planted, by the start of its
	// hash; a fragment from the middle finds nothing until Search anywhere
	// says to look there too.
	it( 'filters the listing by hash, and by fragment when asked', () => {
		cy.visit( DUPLICATES_URL )

		ownGroup().should( 'have.length', 1 )

		cy.get( '#fcias-duplicates-hash', { timeout: FIND_TIMEOUT } ).type( DUP_HASH.slice( 0, 6 ) )
		cy.get( '.db-group', { timeout: FIND_TIMEOUT } ).should( 'have.length', 1 )
		ownGroup().should( 'have.length', 1 )

		// A slice from the middle: no prefix matches it.
		cy.get( '#fcias-duplicates-hash' ).clear().type( DUP_HASH.slice( 6, 12 ) )
		cy.get( '.db-empty', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		cy.get( '[data-testid="fcias-duplicates-anywhere"] input[type="checkbox"]' )
			.click( { force: true } )
		ownGroup().should( 'have.length', 1 )
	} )

	// A bookmarked #others opens the tab, the way #help does. The hash
	// is safe to honour: every cross-account read is confirmed server-side, so
	// arriving by URL reveals nothing on its own.
	it( 'opens the Others tab straight from the hash', () => {
		cy.intercept( 'GET', '**/apps/file_checksum_search/api/v1/sudo/selectable*' ).as( 'selectable' )
		cy.visit( `${ DUPLICATES_URL }#others` )

		cy.get( '[data-testid="fcias-others"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.wait( '@selectable', { timeout: FIND_TIMEOUT } )
	} )

	// A group and an account can carry the same name — "admin" is both on a
	// stock instance — so the picker says which is which.
	it( 'marks groups apart from accounts in the picker', () => {
		cy.intercept( 'GET', '**/apps/file_checksum_search/api/v1/sudo/selectable*' ).as( 'selectable' )
		cy.visit( `${ DUPLICATES_URL }#others` )
		cy.wait( '@selectable', { timeout: FIND_TIMEOUT } )

		cy.get( '[data-testid="fcias-target-picker"] input', { timeout: FIND_TIMEOUT } )
			.click( { force: true } )
		cy.get( '.vs__dropdown-menu', { timeout: FIND_TIMEOUT } )
			.should( 'contain', '(Group)' )
	} )

	// The picker must actually scope the listing. It once did not: the page
	// calls /api/v1/sudo/duplicates, which ignored users[]/groups[] and fell
	// through to every account, so every selection looked identical. Asserting
	// that two different selections give different answers is what catches it.
	it( 'scopes the listing to whoever the picker names', () => {
		const sudoUrl = '**/apps/file_checksum_search/api/v1/sudo/duplicates*'
		cy.intercept( 'GET', '**/apps/file_checksum_search/api/v1/sudo/selectable*' ).as( 'selectable' )
		cy.intercept( 'GET', sudoUrl ).as( 'sudoList' )

		cy.visit( `${ DUPLICATES_URL }#others` )
		cy.wait( '@selectable', { timeout: FIND_TIMEOUT } )

		// This run's own duplicates belong to the administrator, so naming
		// them shows the planted group…
		cy.get( '[data-testid="fcias-target-picker"] input', { timeout: FIND_TIMEOUT } )
			.click( { force: true } )
		cy.get( '.vs__dropdown-menu li', { timeout: FIND_TIMEOUT } )
			.contains( new RegExp( `^${ adminUser }$` ) )
			.click( { force: true } )

		cy.wait( '@sudoList', { timeout: FIND_TIMEOUT } ).its( 'request.url' )
			.should( 'include', `users%5B%5D=${ adminUser }` )
		cy.get( '#fcias-others-hash', { timeout: FIND_TIMEOUT } ).type( DUP_HASH.slice( 0, 6 ) )
		cy.get( '[data-testid="fcias-others"] .db-group', { timeout: FIND_TIMEOUT } )
			.should( 'have.length', 1 )
	} )

	// A row in a cross-account listing says whose file it is. Several
	// people's copies of one file all answer to the same path, so the page
	// shows the location — /<uid>/files/… — for a file that is not the
	// viewer's, and the plain path for the viewer's own. The rule itself has
	// unit tests; this is the one place a rendered row is looked at with a
	// foreign file actually in it, which no case above has: every file this
	// spec plants belongs to the administrator.
	it( 'labels another account\'s file by where it lives, and its own by its path', () => {
		cy.env( [ 'NC_ADMIN_USER', 'NC_ADMIN_PASSWORD' ] ).then( ( env ) => {
			const admin = { user: env.NC_ADMIN_USER || 'admin', password: env.NC_ADMIN_PASSWORD || 'admin' }
			cy.fciasMakeAccount( admin, 'owner' ).then( ( account ) => {
				// The same files in the other account's tree, with the same
				// stated hashes: two more copies of this run's group. Cookies
				// first — cy.request() shares the browser's jar, and with the
				// administrator's session in it these writes would be theirs.
				cy.clearCookies()
				const theirs = ( path ) => `/remote.php/dav/files/${ account.user }${ path }`
				const asThem = { user: account.user, pass: account.password }
				cy.request( { method: 'MKCOL', url: theirs( `/${ dupDir }` ), auth: asThem, failOnStatusCode: false } )
				for ( const { name, content } of files ) {
					cy.request( {
						method: 'PUT',
						url: theirs( `/${ dupDir }/${ name }` ),
						auth: asThem,
						headers: { 'Content-Type': 'text/plain' },
						body: content,
					} )
				}
				cy.importFciasFixture( occ, 'duplicates', account.user )

				cy.login( adminUser, adminPassword )
				cy.intercept( 'GET', '**/apps/file_checksum_search/api/v1/sudo/selectable*' ).as( 'selectable' )
				cy.visit( `${ DUPLICATES_URL }#others` )
				cy.wait( '@selectable', { timeout: FIND_TIMEOUT } )

				// Name both accounts, so one group holds the viewer's copies
				// and the other account's side by side. Typed rather than
				// picked from the opening list: past the prefill threshold the
				// picker searches as you type, and this instance is past it.
				for ( const uid of [ adminUser, account.user ] ) {
					cy.get( '[data-testid="fcias-target-picker"] input', { timeout: FIND_TIMEOUT } )
						.click( { force: true } )
						.type( uid, { force: true } )
					cy.get( '.vs__dropdown-menu li', { timeout: FIND_TIMEOUT } )
						.contains( new RegExp( `^${ uid }$` ) )
						.click( { force: true } )
				}
				cy.get( '#fcias-others-hash', { timeout: FIND_TIMEOUT } ).type( DUP_HASH.slice( 0, 6 ) )

				const group = () => cy.get( '[data-testid="fcias-others"] .db-group', { timeout: FIND_TIMEOUT } )
				group().should( 'have.length', 1 )
				group().find( '.db-group-header' ).click()
				group().find( '.db-file-label', { timeout: FIND_TIMEOUT } ).should( 'have.length', 4 )

				// The label is the row's first child, a link or not. Two rows
				// are the other account's and say so; the viewer's own two keep
				// their path and never read as a location.
				const label = ( el ) => el.firstElementChild?.textContent ?? ''
				group().find( '.db-file-label' )
					.filter( ( _i, el ) => label( el ).startsWith( `/${ account.user }/files/` ) )
					.should( 'have.length', 2 )
				group().find( '.db-file-label' )
					.filter( ( _i, el ) => label( el ).startsWith( `/${ adminUser }/files/` ) )
					.should( 'have.length', 0 )

				// And only the viewer's own rows link: a file link resolves in
				// the viewer's folder, so the other account's rows would open
				// to nothing and are text instead.
				group().find( '.db-file-label > a' ).should( 'have.length', 2 )
				group().find( '.db-file-label > .db-file-unopenable' ).should( 'have.length', 2 )

				cy.fciasDeleteAccount( admin, account.user )
			} )
		} )
	} )

	// The page's state is its address: the tab, the filters, the page and
	// — on Others — whose files. Read on arrival, so a pasted address opens
	// on the search it names with no click; written on every change, so
	// the address bar can be copied as the search.
	it( 'opens Others from the address with the hash filled in and the whole reach named', () => {
		cy.visit( `${ DUPLICATES_URL }#others?hash=${ DUP_HASH.slice( 0, 8 ) }&all=1` )

		cy.get( '[data-testid="fcias-others"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '#fcias-others-hash', { timeout: FIND_TIMEOUT } ).should( 'have.value', DUP_HASH.slice( 0, 8 ) )
		cy.get( '[data-testid="fcias-target-picker"]' ).should( 'contain', 'All accounts' )
		cy.get( '[data-testid="fcias-awaiting-scope"]' ).should( 'not.exist' )
		cy.get( '[data-testid="fcias-others"] .db-group .db-hash', { timeout: FIND_TIMEOUT } ).should( 'contain', DUP_HASH )
	} )

	it( 'writes the filters to the address, and reads them back', () => {
		cy.visit( DUPLICATES_URL )
		cy.get( '#fcias-duplicates-hash', { timeout: FIND_TIMEOUT } ).type( 'abc' )
		cy.location( 'hash' ).should( 'eq', '#mine?hash=abc' )
		cy.get( '#fcias-duplicates-limit' ).clear().type( '10' )
		cy.location( 'hash' ).should( 'eq', '#mine?hash=abc&limit=10' )

		// A tab change is a place to go back to; a filter change is not.
		cy.get( '.db-tab[data-tab="help"]' ).click()
		cy.location( 'hash' ).should( 'eq', '#help' )
		cy.go( 'back' )
		cy.location( 'hash' ).should( 'eq', '#mine?hash=abc&limit=10' )
		cy.get( '#fcias-duplicates-hash' ).should( 'have.value', 'abc' )
		cy.get( '#fcias-duplicates-limit' ).should( 'have.value', '10' )

		// A shared address should open, not refuse: what a field cannot
		// take is clamped or dropped, and the address says what was kept.
		// A visit that changes only the fragment does not load the page —
		// Cypress, like a browser, raises `hashchange` — so the reload is
		// what makes this a pasted address read on load.
		cy.visit( `${ DUPLICATES_URL }#mine?algo=whirlpool&limit=9999` )
		cy.reload()
		cy.get( '#fcias-duplicates-limit', { timeout: FIND_TIMEOUT } ).should( 'have.value', '500' )
		cy.location( 'hash' ).should( 'eq', '#mine?limit=500' )
	} )

	it( 'does not offer the Others tab to an account nobody named', () => {
		cy.env( [ 'NC_ADMIN_USER', 'NC_ADMIN_PASSWORD' ] ).then( ( env ) => {
			const admin = { user: env.NC_ADMIN_USER || 'admin', password: env.NC_ADMIN_PASSWORD || 'admin' }
			cy.fciasMakeAccount( admin, 'nobody' ).then( ( account ) => {
				cy.login( account.user, account.password )
				cy.visit( DUPLICATES_URL )
				// The page is up — its own tab is there — but the cross-account
				// one is not offered to an account nobody named.
				cy.get( '.db-tab[data-tab="mine"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
				cy.get( '.db-tab[data-tab="others"]' ).should( 'not.exist' )
				cy.fciasDeleteAccount( admin, account.user )
			} )
		} )
	} )

} )
