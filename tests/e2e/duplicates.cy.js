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

// Two empty files, as tests/e2e/fixtures/empty-files.json states them, and
// the sha1 of no input they share.
const emptyDir = 'fcias-e2e-empty'
const emptyFiles = [ 'empty-1.txt', 'empty-2.txt' ]
const EMPTY_SHA1 = 'da39a3ee5e6b4b0d3255bfef95601890afd80709'

// Three copies of their own, as tests/e2e/fixtures/resolve.json states them,
// for the case that deletes and restores: sha1('fcias resolve').
const resolveDir = 'fcias-e2e-resolve'
const resolveFiles = [ 'one.txt', 'two.txt', 'three.txt' ]
const RESOLVE_CONTENT = 'fcias resolve'
const RESOLVE_HASH = '6f9b3d2b51ba91aaae04bcc3b20b03d2aded95ac'

const recalcUrl = '**/ocs/v2.php/apps/file_checksum_search/api/v1/file/*/recalc*'

const webdavUrl = ( path ) => `/remote.php/dav/files/${ adminUser }${ path }`

// The group this run created, found by its hash rather than by position:
// the page may legitimately show others, and asserting on the first one
// would make this spec depend on an ordering nothing promises.
const ownGroup = () => cy.get( '.db-group', { timeout: FIND_TIMEOUT } )
	.filter( ( _i, el ) => el.querySelector( '.db-hash' )?.textContent?.trim() === DUP_HASH )

// An entry of a menu, the group header's or a row's, its menu opened. The
// menu is an NcActions popover, rendered outside the group, so the entry is
// looked for in the open popover rather than below the toggle.
const menuEntry = ( scope, action ) => {
	scope.find( '.action-item__menutoggle' ).first().click()
	return cy.get( `.action-item__popper [data-action="${ action }"] button`, { timeout: FIND_TIMEOUT } ).filter( ':visible' )
}

// Past core's password dialog into the Others tab, where the dialog opens:
// whether it does is not this spec's to know (`fciasConfirmPasswordIfAsked`).
const intoOthers = () => cy.fciasConfirmPasswordIfAsked( adminPassword, ( $body ) => $body.find( '[data-testid="fcias-others"]' ).length > 0 )

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
			// Two empty files, in a folder of their own: the listing leaves
			// their group out by default, which one case asserts, and the
			// screenshots show fcias-e2e-duplicates, which they would change.
			cy.request( {
				method: 'MKCOL',
				url: webdavUrl( `/${ emptyDir }` ),
				auth: { user: adminUser, pass: adminPassword },
				failOnStatusCode: false,
			} )

			for ( const name of emptyFiles ) {
				cy.request( {
					method: 'PUT',
					url: webdavUrl( `/${ emptyDir }/${ name }` ),
					auth: { user: adminUser, pass: adminPassword },
					headers: { 'Content-Type': 'text/plain' },
					body: '',
				} )
			}

			// Three copies for the case that deletes and restores, each written
			// once: a re-run's overwrite would give them versions, and the
			// trash would still hold the last run's. So the folder goes first,
			// to the trash, and the trash is emptied.
			cy.request( {
				method: 'DELETE',
				url: webdavUrl( `/${ resolveDir }` ),
				auth: { user: adminUser, pass: adminPassword },
				failOnStatusCode: false,
			} )
			cy.exec( `${ occ } trashbin:cleanup ${ adminUser }`, { failOnNonZeroExit: false } )
			cy.request( {
				method: 'MKCOL',
				url: webdavUrl( `/${ resolveDir }` ),
				auth: { user: adminUser, pass: adminPassword },
				failOnStatusCode: false,
			} )

			for ( const name of resolveFiles ) {
				cy.request( {
					method: 'PUT',
					url: webdavUrl( `/${ resolveDir }/${ name }` ),
					auth: { user: adminUser, pass: adminPassword },
					headers: { 'Content-Type': 'text/plain' },
					body: RESOLVE_CONTENT,
				} )
			}

			cy.resetFciasState( occ )
			cy.importFciasFixture( occ, 'duplicates', adminUser )
			cy.importFciasFixture( occ, 'empty-files', adminUser )
			cy.importFciasFixture( occ, 'resolve', adminUser )
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

	it( 'verifies a group through its own menu and it comes back matching', () => {
		cy.visit( DUPLICATES_URL )

		ownGroup().should( 'have.length', 1 )
		// Per group, never per page: reading every file costs time and, on
		// metered storage, money.
		menuEntry( ownGroup().find( '.db-group-header' ), 'verifyAll' ).click()

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
		menuEntry( ownGroup().find( '.db-file-item' ).first(), 'verify' ).click()

		// That file answers; the group as a whole is not claimed as verified
		// while its other file has never been read.
		ownGroup().find( '.db-file-item .db-verified', { timeout: FIND_TIMEOUT } )
			.should( 'have.length', 1 )
		ownGroup().find( '.db-group-header-status' ).should( 'not.exist' )
	} )

	it( 'links a file to its folder, details pane open and file not opened', () => {
		cy.visit( DUPLICATES_URL )

		ownGroup().should( 'have.length', 1 )
		ownGroup().find( '.db-group-header' ).click()

		// Core's /f/{fileid} answers with a redirect to the file's folder.
		// /apps/files/files/{fileid} without a `dir` listed the root folder,
		// where a file in any other folder could not be found.
		ownGroup().find( '.db-file-item a[href]' ).first().invoke( 'attr', 'href' ).then( ( href ) => {
			cy.request( { url: href, followRedirect: false } ).then( ( response ) => {
				expect( response.status ).to.eq( 303 )
				const target = new URL( response.headers.location, Cypress.config( 'baseUrl' ) )
				expect( target.searchParams.get( 'dir' ) ).to.eq( `/${ dupDir }` )
				expect( target.searchParams.get( 'opendetails' ) ).to.eq( 'true' )
				expect( target.searchParams.get( 'openfile' ) ).to.eq( 'false' )
			} )
		} )
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
		menuEntry( ownGroup().find( '.db-group-header' ), 'verifyAll' ).click()

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
	// not a state an ordinary listing should slip into.
	it( 'offers the Others tab to the administrator', () => {
		cy.visit( DUPLICATES_URL )
		cy.get( '.db-tab[data-tab="others"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
	} )

	// Every control says what it is, with a label a reader can see and a
	// help button beside it. By the `for`, not the caption.
	it( 'labels the algorithm, min, limit, hash and path controls', () => {
		cy.visit( DUPLICATES_URL )
		for ( const id of [
			'fcias-duplicates-algorithm',
			'fcias-duplicates-min',
			'fcias-duplicates-limit',
			'fcias-duplicates-hash',
			'fcias-duplicates-path',
		] ) {
			cy.get( `label[for="${ id }"]`, { timeout: FIND_TIMEOUT } ).should( 'exist' )
			cy.get( `#${ id }` ).should( 'exist' )
		}
		cy.get( '.db-label .fcias-help-icon' ).should( 'have.length', 5 )
	} )

	// The tab asks the server who may be named, and shows the listing on its
	// own amber ground with nothing loaded until a target is chosen.
	it( 'opens the Others tab with a picker and nothing named yet', () => {
		cy.intercept( 'GET', '**/apps/file_checksum_search/api/v1/sudo/selectable*' ).as( 'selectable' )
		cy.visit( DUPLICATES_URL )

		cy.get( '.db-tab[data-tab="others"]', { timeout: FIND_TIMEOUT } ).click()
		intoOthers()
		cy.wait( '@selectable', { timeout: FIND_TIMEOUT } )

		cy.get( '[data-testid="fcias-others"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '[data-testid="fcias-target-picker"]' ).should( 'exist' )
		cy.get( '[data-testid="fcias-awaiting-scope"]' ).should( 'exist' )
		// Its own controls, so the ordinary tab's filters are left alone.
		cy.get( '#fcias-others-min' ).should( 'exist' )
	} )

	// The filter narrows to a group this run planted, by the start of its
	// hash; a fragment from the middle finds nothing until Match anywhere in the hash
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

	// Empty files all share one checksum per algorithm; the listing leaves
	// their group out until the switch asks for it, and the address keeps
	// the answer. The two made in `before()`.
	it( 'leaves the empty files out until the switch asks for them', () => {
		cy.visit( `${ DUPLICATES_URL }#mine?hash=${ EMPTY_SHA1 }` )
		cy.get( '.db-empty', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '[data-testid="fcias-empty-group"]' ).should( 'not.exist' )

		cy.get( '[data-testid="fcias-duplicates-include-empty"] input[type="checkbox"]' )
			.click( { force: true } )
		cy.get( '[data-testid="fcias-empty-group"]', { timeout: FIND_TIMEOUT } ).should( 'have.length', 1 )
		cy.location( 'hash' ).should( 'contain', 'includeEmpty=1' )
	} )

	// A bookmarked #others opens the tab, the way #help does. The hash
	// is safe to honour: every cross-account read is confirmed server-side, so
	// arriving by URL reveals nothing on its own.
	it( 'opens the Others tab straight from the hash', () => {
		cy.intercept( 'GET', '**/apps/file_checksum_search/api/v1/sudo/selectable*' ).as( 'selectable' )
		cy.visit( `${ DUPLICATES_URL }#others` )
		intoOthers()

		cy.get( '[data-testid="fcias-others"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.wait( '@selectable', { timeout: FIND_TIMEOUT } )
	} )

	// A group and an account can carry the same name — "admin" is both on a
	// stock instance — so the picker says which is which.
	it( 'marks groups apart from accounts in the picker', () => {
		cy.intercept( 'GET', '**/apps/file_checksum_search/api/v1/sudo/selectable*' ).as( 'selectable' )
		cy.visit( `${ DUPLICATES_URL }#others` )
		intoOthers()
		cy.wait( '@selectable', { timeout: FIND_TIMEOUT } )

		cy.get( '[data-testid="fcias-target-picker"] input', { timeout: FIND_TIMEOUT } )
			.click( { force: true } )
		cy.get( '.vs__dropdown-menu', { timeout: FIND_TIMEOUT } )
			.should( 'contain', '(group)' )
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
		intoOthers()
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
	// shows the location — home:<uid>//… — for a file that is not the
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
				// A fresh account: the folder cannot exist yet, so a refusal
				// here is a defect and not a rerun, and it fails loudly rather
				// than as a 404 on the PUT that follows.
				cy.request( { method: 'MKCOL', url: theirs( `/${ dupDir }` ), auth: asThem } )
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
				intoOthers()
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
					.filter( ( _i, el ) => label( el ).startsWith( `home:${ account.user }//` ) )
					.should( 'have.length', 2 )
				group().find( '.db-file-label' )
					.filter( ( _i, el ) => label( el ).startsWith( `home:${ adminUser }//` ) )
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
		intoOthers()

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

	// What the page does to its own rows: of three copies, one chosen and
	// moved to the trash, where the group still shows it, and brought back
	// from there. Last, since it changes the files; `before()` writes them,
	// as DAV takes no write from a logged-in browser session.
	it( 'moves a copy chosen in a group to the trash, and restores it', () => {
		const group = () => cy.get( '.db-group', { timeout: FIND_TIMEOUT } )
			.filter( ( _i, el ) => el.querySelector( '.db-hash' )?.textContent?.trim() === RESOLVE_HASH )
		const trashed = () => group().find( '[data-testid="fcias-trash"]', { timeout: FIND_TIMEOUT } )

		cy.visit( `${ DUPLICATES_URL }#mine?hash=${ RESOLVE_HASH }` )
		group().should( 'have.length', 1 )

		// Select, from the group's menu, opens the group to choose in.
		menuEntry( group().find( '.db-group-header' ), 'select' ).click()
		group().should( 'have.class', 'open' )
		group().find( '.db-file-item' ).should( 'have.length', 3 )

		// All chosen, then two let go: one copy to the trash, as the dialog
		// proposes, with no version to place, the files written once. One
		// only, as a group with fewer than two copies outside the trash is
		// resolved, and leaves the page.
		menuEntry( group().find( '.db-group-header' ), 'selectAll' ).click()
		group().find( 'input[data-testid="fcias-select-row"]' ).eq( 0 ).uncheck( { force: true } )
		group().find( 'input[data-testid="fcias-select-row"]' ).eq( 2 ).uncheck( { force: true } )

		// Escape is Cancel: the dialog goes, and nothing with it.
		menuEntry( group().find( '.db-group-header' ), 'deleteSelected' ).click()
		cy.get( '[role="dialog"] [data-testid="fcias-delete-rows"] > li', { timeout: FIND_TIMEOUT } ).should( 'have.length', 1 )
		cy.get( 'body' ).type( '{esc}' )
		cy.get( '[role="dialog"]' ).should( 'not.exist' )
		trashed().should( 'not.exist' )

		menuEntry( group().find( '.db-group-header' ), 'deleteSelected' ).click()
		cy.get( '[role="dialog"] [data-testid="fcias-delete-rows"] > li', { timeout: FIND_TIMEOUT } ).should( 'have.length', 1 )
		cy.get( '[data-testid="fcias-delete-target"]' ).should( 'not.exist' )
		cy.get( '[role="dialog"] .dialog__actions button' ).last().click()

		trashed().should( 'have.length', 1 )
		group().find( '.db-file-item' ).should( 'have.length', 3 )

		// Back from the trash, through its own menu.
		menuEntry( trashed().first().closest( '.db-file-item' ), 'restore' ).click()

		group().find( '[data-testid="fcias-trash"]' ).should( 'not.exist' )
		group().find( '.db-file-item' ).should( 'have.length', 3 )
	} )

	// The path filter keeps the groups with a file it matches: the run's
	// resolve folder holds one of its groups and not the other.
	it( 'filters the listing by path, a folder or a name anywhere', () => {
		const groupOf = ( hash ) => cy.get( '.db-group', { timeout: FIND_TIMEOUT } )
			.filter( ( _i, el ) => el.querySelector( '.db-hash' )?.textContent?.trim() === hash )

		cy.visit( `${ DUPLICATES_URL }#mine?path=/${ resolveDir }` )
		cy.get( '#fcias-duplicates-path', { timeout: FIND_TIMEOUT } ).should( 'have.value', `/${ resolveDir }` )
		groupOf( RESOLVE_HASH ).should( 'have.length', 1 )
		groupOf( DUP_HASH ).should( 'not.exist' )

		cy.get( '#fcias-duplicates-path' ).clear().type( 'two.txt' )
		cy.url().should( 'include', 'path=two.txt' )
		groupOf( RESOLVE_HASH ).should( 'have.length', 1 )
		groupOf( DUP_HASH ).should( 'not.exist' )

		cy.get( '#fcias-duplicates-path' ).clear().type( '/nowhere-fcias' )
		cy.contains( '.db-empty', 'No duplicate files found', { timeout: FIND_TIMEOUT } )
	} )

	// One group a load: Load more adds the next under it, and goes once
	// there are no more. The run's two groups are all there are.
	it( 'loads more groups under those shown', () => {
		cy.visit( `${ DUPLICATES_URL }#mine?limit=1` )
		cy.get( '.db-group', { timeout: FIND_TIMEOUT } ).should( 'have.length', 1 )

		cy.get( '[data-testid="fcias-load-more"]' ).click()

		cy.get( '.db-group', { timeout: FIND_TIMEOUT } ).should( 'have.length', 2 )
		cy.get( '[data-testid="fcias-load-more"]' ).should( 'not.exist' )
	} )

	// Across the page: a folder that holds every copy is left alone, a
	// name is chosen, and the choice goes through one confirmation and
	// comes back with Undo.
	it( 'chooses across the page, deletes the choice, and takes it back', () => {
		const resolveGroup = () => cy.get( '.db-group', { timeout: FIND_TIMEOUT } )
			.filter( ( _i, el ) => el.querySelector( '.db-hash' )?.textContent?.trim() === RESOLVE_HASH )

		cy.visit( DUPLICATES_URL )
		resolveGroup().should( 'have.length', 1 )
		cy.get( '[data-testid="fcias-start-selecting"]' ).click()

		// The run's three copies are all under its folder.
		cy.get( '#fcias-duplicates-select-pattern' ).type( `/${ resolveDir }` )
		cy.get( '[data-testid="fcias-select-matching"]' ).click()
		resolveGroup().find( '[data-testid="fcias-group-left-alone"]' ).should( 'exist' )
		cy.get( '[data-testid="fcias-selection-count"]' ).should( 'have.text', '0 rows selected' )

		// Keep one copy off, the folder takes all three, as a content that
		// must go everywhere would: the confirmation says the group keeps
		// none. Escape leaves it with nothing gone.
		cy.contains( '[data-testid="fcias-selection-bar"]', 'Turn off Keep one copy to select them too.' )
		cy.get( '[data-testid="fcias-keep-one"] input' ).uncheck( { force: true } )
		cy.get( '[data-testid="fcias-select-matching"]' ).click()
		cy.get( '[data-testid="fcias-selection-count"]' ).should( 'have.text', '3 rows selected' )
		cy.get( '[data-testid="fcias-delete-page"]' ).click()
		cy.get( '[data-testid="fcias-page-delete-emptied"]', { timeout: FIND_TIMEOUT } ).should( 'contain', 'In 1 group no copy stays outside the trash.' )
		cy.get( 'body' ).type( '{esc}' )
		cy.get( '[role="dialog"]' ).should( 'not.exist' )
		resolveGroup().find( '[data-testid="fcias-trash"]' ).should( 'not.exist' )
		cy.get( '[data-testid="fcias-select-none"]' ).click()
		cy.get( '[data-testid="fcias-keep-one"] input' ).check( { force: true } )

		cy.get( '#fcias-duplicates-select-pattern' ).clear().type( 'two.txt' )
		cy.get( '[data-testid="fcias-select-matching"]' ).click()
		resolveGroup().find( '[data-testid="fcias-group-selected"]' ).should( 'have.text', '1 selected' )

		cy.get( '[data-testid="fcias-delete-page"]' ).click()
		cy.get( '[data-testid="fcias-page-delete-summary"]', { timeout: FIND_TIMEOUT } ).should( 'contain', '1 row goes to the trash.' )
		cy.get( '[role="dialog"] .dialog__actions button' ).last().click()

		resolveGroup().find( '[data-testid="fcias-trash"]', { timeout: FIND_TIMEOUT } ).should( 'have.length', 1 )

		cy.contains( 'button', /^\s*Undo\s*$/, { timeout: FIND_TIMEOUT } ).click()

		resolveGroup().find( '[data-testid="fcias-trash"]', { timeout: FIND_TIMEOUT } ).should( 'not.exist' )
		resolveGroup().find( '.db-file-item' ).should( 'have.length', 3 )
	} )

} )
