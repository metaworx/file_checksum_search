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
		// The rules table, for the specs that are not about the sidebar. When
		// a rules assertion times out the question is always the same — did
		// the table render, and what is in it — and the sidebar probes above
		// answer none of it.
		const rulesList = doc.querySelector( '#fcias-cron-list' );

		payload.rulesList = rulesList
			? rulesList.textContent.replace( /\s+/g, ' ' ).trim().slice( 0, 300 )
			: null;
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

/**
 * Take the rule set back to what a fresh install ships.
 *
 * Deletes every rule, then lets the repair step recreate the two shipped
 * defaults — both disabled, which is the quiet-start promise and the state
 * every rules spec starts from. `fcias:repair` rather than
 * `maintenance:repair`: it runs this app's steps and no other app's, which is
 * both faster and the difference between a reset and an instance-wide event.
 *
 * occ rather than REST, because REST refuses some of these mutations by
 * permission design — a reset that a permission model can veto is not a
 * reset.
 *
 * The acknowledgement of the idle banner goes too. It is not a rule, but it
 * is the one other thing the rules pages remember between runs, and a spec
 * asserting the banner appears cannot know why it did not.
 *
 * @param {string} occ  How to invoke occ, e.g. `php nextcloud/occ`.
 */
Cypress.Commands.add( 'fciasResetRules', ( occ ) => {
	cy.exec( `${ occ } fcias:rules:list -o json`, {
		timeout: FCIAS_EXEC_TIMEOUT,
		failOnNonZeroExit: false,
	} )
		.then( ( { stdout } ) => {
			let rules = []

			try
			{
				rules = JSON.parse( stdout )
			}
			catch ( e )
			{
				// No rules at all prints something that is not JSON. Nothing
				// to delete is a legitimate starting point, not a failure.
			}

			for ( const rule of rules )
			{
				cy.exec( `${ occ } fcias:rules:delete ${ rule.id } -y`, {
					timeout: FCIAS_EXEC_TIMEOUT,
					failOnNonZeroExit: false,
				} )
			}
		} )

	cy.exec( `${ occ } config:app:delete file_checksum_search idle_banner_ack`, {
		timeout: FCIAS_EXEC_TIMEOUT,
		failOnNonZeroExit: false,
	} )

	cy.exec( `${ occ } fcias:repair`, { timeout: FCIAS_EXEC_TIMEOUT } )
} )

/**
 * Make sure alice and bob exist.
 *
 * The developer instance has them; CI starts with admin alone. Creating them
 * here is what lets one cross-user spec run in both places rather than being
 * skipped in the environment that matters.
 *
 * A user who already exists makes `user:add` exit non-zero, which is the
 * success case as far as this is concerned.
 *
 * @param {string} occ  How to invoke occ.
 */
Cypress.Commands.add( 'fciasEnsureUsers', ( occ ) => {
	for ( const user of [ 'alice', 'bob' ] )
	{
		cy.exec(
			`OC_PASS=SecretPass123! ${ occ } user:add --password-from-env ${ user }`,
			{ timeout: FCIAS_EXEC_TIMEOUT, failOnNonZeroExit: false },
		)
	}
} )

/**
 * One authenticated call to this app's API.
 *
 * Three things about that API are worth having in one place rather than in
 * every spec, because each was established by probing rather than by reading:
 *
 * - the endpoints answer under `/ocs/v2.php` **only**; `/index.php/apps/…`
 *   and `/apps/…` are 404;
 * - the body is plain JSON with no `{ocs:{…}}` envelope, because the
 *   controllers extend `ApiController` rather than `OCSController` — assert
 *   on `body.rules`, never `body.ocs.data`;
 * - `OCS-APIRequest` is not required with basic auth, but is with a cookie
 *   session, so it goes on every call for uniformity.
 *
 * `failOnStatusCode` defaults to false so that an unexpected status is an
 * assertion failure in the test that asked for it, rather than an abort with
 * no expected-versus-actual to read.
 *
 * @param {object} options  method, url (below the app's API root), body,
 *                          user, password, failOnStatusCode.
 */
Cypress.Commands.add( 'ocs', ( {
	method = 'GET',
	url,
	body,
	user,
	password,
	failOnStatusCode = false,
} ) => cy.request( {
	method,
	url: `/ocs/v2.php/apps/file_checksum_search${ url }`,
	body,
	auth: { user, pass: password },
	headers: {
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	},
	failOnStatusCode,
} ) )
