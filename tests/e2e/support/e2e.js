Cypress.on( 'uncaught:exception', ( err ) => {
	// Nextcloud core apps (Photos, Recommendations, User Status, …) throw
	// benign unhandled rejections while the dashboard loads on a fresh
	// install (missing upload folder, 404s on their OCS endpoints). The
	// specs assert on the DOM, so ignore these instead of failing.
	console.error( 'Ignoring uncaught exception:', err.message )
	return false
} )

Cypress.Commands.add( 'login', ( user = 'admin', password = 'admin' ) => {
	cy.session( [ user, password ], () => {
		cy.visit( '/login' )
		cy.get( 'input[name="user"]' ).type( user )
		cy.get( 'input[name="password"]' ).type( `${ password }{enter}` )
		// Wait until we leave the login page
		cy.url().should( 'not.include', '/login' )
	} )
} )

// ---------------------------------------------------------------------------
// Failure diagnostic
//
// Runs only for a test that has already failed, so a green run pays nothing
// beyond a string test per console call. The payload goes through cy.task()
// because cy.log() does not reach stdout under `cypress run`, which is
// exactly where the context is needed. See TESTING.md 9.5 for how to read it.
// ---------------------------------------------------------------------------

const DIAG_LOG_LIMIT = 12;

Cypress.on( 'window:before:load', ( win ) => {
	win.__fciasDiagLog = [];
	for ( const level of [ 'error', 'warn' ] ) {
		const original = win.console[ level ];
		win.console[ level ] = ( ...args ) => {
			try {
				const line = args.map( String ).join( ' ' );
				// Every error is worth keeping; warnings only when related.
				if ( level === 'error' || /FCIAS|file_checksum_search|sidebar/i.test( line ) ) {
					if ( win.__fciasDiagLog.length < DIAG_LOG_LIMIT ) {
						win.__fciasDiagLog.push( `${ level }: ${ line }`.slice( 0, 300 ) );
					}
				}
			} catch ( e ) {
				// A console tap must never break the page under test.
			}
			original.apply( win.console, args );
		};
	}
} );

const collectDiagnostic = ( win, testTitle ) => {
	const payload = { test: testTitle };
	try {
		const doc = win.document;
		const sidebar = doc.querySelector( '.app-sidebar' );
		const header = doc.querySelector( '.app-sidebar-header__mainname' )
			|| doc.querySelector( '.app-sidebar-header' );

		payload.url = win.location?.href ?? null;
		payload.sidebarOpen = !!sidebar;
		// "Loading …" here means the files app never established a context,
		// in which case no app's tab renders — not just ours.
		payload.sidebarHeader = header?.textContent?.trim()?.slice( 0, 80 ) ?? null;
		payload.sidebarTabs = [ ...doc.querySelectorAll( '.app-sidebar [role="tab"]' ) ]
			.map( ( el ) => ( el.getAttribute( 'aria-label' ) || el.textContent || '' ).trim() )
			.filter( Boolean );
		// Absent here means the app was disabled, or its init script was not
		// injected, for this particular page load.
		payload.fciasScripts = [ ...doc.querySelectorAll( 'script[src*="file_checksum_search"]' ) ]
			.map( ( el ) => ( el.getAttribute( 'src' ) || '' ).split( '/' ).pop() );
		payload.consoleLog = win.__fciasDiagLog ?? [];
	} catch ( e ) {
		payload.collectionError = String( e );
	}
	return payload;
};

afterEach( function () {
	if ( this.currentTest?.state !== 'failed' ) {
		return;
	}
	const testTitle = this.currentTest.fullTitle();
	cy.window( { log: false, timeout: 4000 } ).then( ( win ) => {
		cy.task( 'fciasDiag', collectDiagnostic( win, testTitle ), { log: false } );
	} );
} );

// ---------------------------------------------------------------------------
// App state: reset and fixtures
//
// A spec that asserts on hashes needs to know what was there before it ran.
// Uploading files and waiting for the background job gives neither: the job
// runs on its own schedule, and what earlier specs left behind accumulates.
// These two put the instance in a known state instead — reset it, then state
// the hashes outright.
// ---------------------------------------------------------------------------

/**
 * How long the state commands may take.
 *
 * `cy.exec()` defaults to 60 seconds, and clearing hashes in the foreground is
 * one metadata document rewritten per file — measured at roughly five files a
 * second against ddev, so a few hundred files already exceed the default.
 * `maintenance:repair` is slow for its own reasons.
 */
const FCIAS_EXEC_TIMEOUT = 300000

/**
 * Take the instance back to no hashes and no queue, synchronously.
 *
 * `--now` rather than the default: the deferred reset leaves the clearing to
 * the background job, and a spec cannot wait for a job it does not control.
 * `maintenance:repair` afterwards recreates whatever the app seeds on install,
 * so the state is the shipped one rather than merely empty.
 *
 * @param {string} occ  How to invoke occ, e.g. `php nextcloud/occ`.
 */
Cypress.Commands.add( 'resetFciasState', ( occ ) => {
	cy.exec( `${ occ } fcias:reset --hashes --status --force --now`, {
		timeout: FCIAS_EXEC_TIMEOUT,
	} )
	cy.exec( `${ occ } maintenance:repair`, {
		timeout: FCIAS_EXEC_TIMEOUT,
		failOnNonZeroExit: false,
	} )
} )

/**
 * Give files their hashes from a fixture, without computing anything.
 *
 * The fixture travels in on standard input rather than by path: `cy.exec()`
 * runs on the host while occ may run inside a container, and the two do not
 * agree on where the repository is. A redirect works either way.
 *
 * Fixtures name no storage, so their paths are read as relative to $user's
 * files directory — which is what lets one fixture serve any instance.
 *
 * @param {string} occ   How to invoke occ.
 * @param {string} name  A file in tests/e2e/fixtures, without the extension.
 * @param {string} user  Whose files directory the paths are measured from.
 */
Cypress.Commands.add( 'importFciasFixture', ( occ, name, user = 'admin' ) => {
	const fixture = `tests/e2e/fixtures/${ name }.json`

	cy.exec(
		`${ occ } fcias:import --replace --hashes --user=${ user } --stamp=mtime < ${ fixture }`,
		{ timeout: FCIAS_EXEC_TIMEOUT },
	).then( ( { stdout } ) => {
		// An import that resolved nothing is the failure worth catching early:
		// it means the spec's files are not where the fixture says they are,
		// and every later assertion would fail for a reason that looks unrelated.
		expect( stdout, `importing ${ name }` ).to.match( /written|overwritten/ )
	} )
} )
