// This app's own name, as it appears in bundle paths and therefore in the
// stack of anything it throws.
const APP_ID = 'file_checksum_search'

Cypress.on( 'uncaught:exception', ( err ) => {
	// Nextcloud core apps (Photos, Recommendations, User Status, …) throw
	// benign unhandled rejections while the dashboard loads on a fresh
	// install (missing upload folder, 404s on their OCS endpoints). Those
	// are not this suite's business.
	//
	// Anything thrown by this app is. Ignoring every exception is how
	// nineteen dead OC.Notification calls went unnoticed for as long as they
	// existed — each one a TypeError at the end of a request that had
	// already succeeded, so nothing else in the DOM ever looked wrong.
	const ours = `${ err.message }\n${ err.stack || '' }`.includes( APP_ID )

	console.error(
		`${ ours ? 'Failing on' : 'Ignoring' } uncaught exception:`,
		err.message,
	)

	// Returning false is what tells Cypress not to fail the test.
	return ours
} )

// Every account this spec logs in as. A form login mints a browser token in
// oc_authtoken, and nothing in a test run ever ends that session: the suite
// had left 918 of them on the administrator before anyone looked. Throwaway
// accounts take their tokens with them when they are deleted; the
// administrator does not.
const loggedInAs = new Set()

Cypress.Commands.add( 'login', ( user = 'admin', password = 'admin' ) => {
	loggedInAs.add( user )
	cy.session( [ user, password ], () => {
		cy.visit( '/login' )
		cy.get( 'input[name="user"]' ).type( user )
		cy.get( 'input[name="password"]' ).type( `${ password }{enter}` )
		// Wait until we leave the login page
		cy.url().should( 'not.include', '/login' )
	} )
} )

// Delete the tokens this run's logins minted. Not by logging out: a session
// cannot be restored inside an after() hook — Cypress replays the login form
// instead, which mints one more token and fails outright for an account the
// spec has just deleted. By name, through occ: every token the test browser
// makes carries "Cypress/" in its user agent, and no real browser's does, so
// the sweep never reaches a person's own session. An account that no longer
// exists makes occ fail, which is the expected answer and is ignored.
// The sweep itself, for the accounts named. Runs twice per spec: at the
// end, for what the spec minted, and at the start, for what the previous
// spec minted after its own sweep had run — a spec's own after() hooks run
// later than this file's, and the API calls they make (cy.ocs re-authenticates
// with Basic auth, which mints a session token) land after the sweep.
const sweepCypressTokens = ( users ) => {
	// cy.env(), not Cypress.env(): this suite runs with allowCypressEnv off,
	// and the specs read occ the same way.
	cy.env( [ 'occ' ] ).then( ( { occ } ) => {
		if ( ! occ ) {
			return
		}
		for ( const user of users ) {
			cy.exec( `${ occ } user:auth-tokens:list ${ user } --output=json`, { failOnNonZeroExit: false } )
				// No exit-code check: the result's `code` is undefined here,
				// and a non-JSON answer (an account that is already gone)
				// fails the parse below, which is the same outcome.
				.then( ( { stdout } ) => {
					let tokens = []
					try {
						tokens = JSON.parse( stdout )
					} catch ( e ) {
						return
					}
					for ( const token of tokens ) {
						if ( String( token.name ).includes( 'Cypress/' ) ) {
							cy.exec( `${ occ } user:auth-tokens:delete ${ user } ${ token.id }`, { failOnNonZeroExit: false } )
						}
					}
				} )
		}
	} )
}

// Accounts an earlier run left behind. Every account this suite makes is
// `fcias_e2e_<base>_<hex>`, and the spec that made it deletes it — in its
// after() or at the end of the one test that needed it. A run that fails or
// is interrupted before that point leaves the account standing, and the
// instance had collected thirty-nine of them before anyone counted. The
// prefix is the suite's by construction, so nothing a person made is ever in
// this list; through occ, so no administrator password is needed; and before
// a spec mints anything, so a run never collects its own.
const reapE2eAccounts = () => {
	cy.env( [ 'occ' ] ).then( ( { occ } ) => {
		if ( ! occ ) {
			return
		}
		// --limit 0: every account, not the first 500. A parse failure or a
		// failed delete is logged rather than swallowed; neither fails the
		// run, since the accounts are inert and the next run tries again.
		cy.exec( `${ occ } user:list --output=json --limit 0`, { failOnNonZeroExit: false } )
			.then( ( { stdout } ) => {
				let users = {}
				try {
					users = JSON.parse( stdout )
				} catch ( e ) {
					cy.log( `reaper: could not read the account list: ${ String( stdout ).slice( 0, 200 ) }` )
					return
				}
				for ( const user of Object.keys( users ) ) {
					if ( user.startsWith( 'fcias_e2e_' ) ) {
						cy.exec( `${ occ } user:delete '${ user }'`, { failOnNonZeroExit: false } )
							.then( ( { code, stderr } ) => {
								if ( code !== 0 ) {
									cy.log( `reaper: could not delete ${ user }: ${ stderr }` )
								}
							} )
					}
				}
			} )
	} )
}

before( () => {
	// What an earlier run left: its accounts, and its tokens on the account
	// every spec uses.
	reapE2eAccounts()
	sweepCypressTokens( [ 'admin' ] )
} )

after( () => {
	sweepCypressTokens( loggedInAs )
	loggedInAs.clear()
} )

// ---------------------------------------------------------------------------
// Failure diagnostic
//
// Runs only for a test that has already failed, so a green run pays nothing
// beyond a string test per console call. The payload goes through cy.task()
// because cy.log() does not reach stdout under `cypress run`, which is
// exactly where the context is needed. tests/e2e/README.md says how to read it.
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
		const rulesList = doc.querySelector( '#fcias-rules-list' );

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
 * A repair run is slow for its own reasons.
 */
const FCIAS_EXEC_TIMEOUT = 300000

/**
 * Take the instance back to no hashes and no queue, synchronously.
 *
 * `--now` rather than the default: the deferred reset leaves the clearing to
 * the background job, and a spec cannot wait for a job it does not control.
 *
 * Two named repair steps afterwards, not a whole repair. The point of running
 * one at all is to put back what the app seeds on install — its rule defaults
 * and its metadata key declarations — and those are the two steps that do it.
 *
 * A whole repair would undo the reset it follows. `rebuild-from-filecache`
 * copies checksums out of `oc_filecache.checksum`, a column this app writes
 * but does not own and a reset therefore leaves alone: measured on the
 * developer instance, a reset cleared 309 files and the repair immediately
 * copied 891 checksums for 303 of them straight back. A spec starting from
 * that is starting from the state of whatever ran last, which is the thing
 * this command exists to prevent.
 *
 * @param {string} occ  How to invoke occ, e.g. `php nextcloud/occ`.
 */
Cypress.Commands.add( 'resetFciasState', ( occ ) => {
	cy.exec( `${ occ } fcias:reset --hashes --status --force --now`, {
		timeout: FCIAS_EXEC_TIMEOUT,
	} )
	cy.exec( `${ occ } fcias:repair --step selector-model --step metadata-keys`, {
		timeout: FCIAS_EXEC_TIMEOUT,
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
 * A password no policy will refuse and nobody will guess.
 *
 * One character from each class the common policies ask for, then length
 * from the full alphabet — assembled rather than generated and retried, so
 * a strict policy cannot turn this into a loop. The same composition as
 * the PHPUnit suite's DatabaseTestCase::strongPassword().
 *
 * @param {number} length  Total length, at least 4.
 * @returns {string}
 */
const strongPassword = ( length = 32 ) => {
	const upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
	const lower = 'abcdefghijklmnopqrstuvwxyz'
	const digits = '0123456789'
	const symbols = '!#$%&*+-=?@^_'
	const alphanumeric = upper + lower + digits

	const pick = ( alphabet, count ) => {
		const bytes = new Uint32Array( count )
		crypto.getRandomValues( bytes )
		return Array.from( bytes, ( byte ) => alphabet[ byte % alphabet.length ] ).join( '' )
	}

	return pick( upper, 1 )
		+ pick( lower, 1 )
		+ pick( digits, 1 )
		+ pick( symbols, 1 )
		+ pick( alphanumeric, length - 4 )
}

/**
 * Create an account for this run alone, and say what it is.
 *
 * Nothing in the suite authenticates as a name it did not make. A fixed
 * `alice` with a password written into the repository is standing risk on
 * every instance the suite has ever touched, and it also poses a question
 * teardown cannot answer — whether the account it is about to delete was
 * the suite's or the developer's. A random name answers it by construction.
 *
 * The base rides in the name (`fcias_e2e_alice_9f3a1c04`) so a failing run
 * still says which account was the sharer and which the sharee. What it
 * does not carry is anything an attacker could use: the password is fresh
 * per account and never leaves the run.
 *
 * A run that crashes before its `after()` leaves the account behind, inert
 * and identifiable by the prefix, where `alice` with a published password
 * is neither — and the next run's `before()` collects it.
 *
 * Through the provisioning API rather than `occ user:add`, because the
 * password has to reach the account and `--password-from-env` cannot carry
 * it everywhere this suite runs. `cy.exec`'s `env` sets a variable for the
 * process it starts, and where `occ` is a wrapper that re-enters a
 * container — the ddev form — the variable stops at the wrapper and occ
 * reads an empty `OC_PASS`. It works where `occ` is a plain binary, which
 * is why the old helper's `failOnNonZeroExit: false` hid it: the accounts
 * it was asked to create already existed. HTTP carries the password to the
 * server the same way in both.
 *
 * @param {{user: string, password: string}} admin  An administrator.
 * @param {string} base  What this account is for, e.g. 'alice'.
 * @returns {Cypress.Chainable<{user: string, password: string}>}
 */
Cypress.Commands.add( 'fciasMakeAccount', ( admin, base ) => {
	const suffix = new Uint32Array( 1 )
	crypto.getRandomValues( suffix )

	const account = {
		user: `fcias_e2e_${ base }_${ suffix[ 0 ].toString( 16 ).padStart( 8, '0' ) }`,
		password: strongPassword(),
	}

	// As the administrator named, not as whoever the browser is logged in
	// as: see fciasDeleteAccount().
	cy.clearCookies()

	return cy.request( {
		method: 'POST',
		url: '/ocs/v2.php/cloud/users?format=json',
		auth: { user: admin.user, pass: admin.password },
		headers: {
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		},
		body: {
			userid: account.user,
			password: account.password,
		},
		failOnStatusCode: false,
	} ).then( ( response ) => {
		// Asserted rather than assumed: an account that was not created
		// fails every later request as a bare 401, which reads as a broken
		// endpoint rather than as missing setup.
		expect(
			response.status,
			`creating the test account ${ account.user }`,
		).to.eq( 200 )

		return account
	} )
} )

/**
 * Delete an account this suite made.
 *
 * Tolerant of one that is already gone, because a spec whose setup failed
 * halfway still runs its `after()` and the missing account is the outcome
 * that hook wanted.
 *
 * @param {{user: string, password: string}} admin  An administrator.
 * @param {string} user  The uid, as returned by cy.fciasMakeAccount().
 */
Cypress.Commands.add( 'fciasDeleteAccount', ( admin, user ) => {
	if ( ! user )
	{
		return
	}

	// Without this the request carries the browser's cookies, and a session
	// outranks the Basic auth header. Called right after a test logged in as
	// the account itself, the deletion ran *as that account*, was refused
	// with 403, and the old blanket tolerance swallowed it — which is how
	// thirty-nine `fcias_e2e_nobody_*` accounts came to exist.
	cy.clearCookies()

	cy.request( {
		method: 'DELETE',
		url: `/ocs/v2.php/cloud/users/${ user }?format=json`,
		auth: { user: admin.user, pass: admin.password },
		headers: { 'OCS-APIRequest': 'true' },
		failOnStatusCode: false,
	} ).then( ( response ) => {
		// Gone, or already gone. Anything else — a refusal above all — is a
		// leftover account, and says so here rather than on the next count.
		expect(
			[ 200, 404 ],
			`deleting the test account ${ user } answered ${ response.status }`,
		).to.include( response.status )
	} )
} )


/**
 * Grant or revoke rule editing for every user, and say what it was before.
 *
 * The shipped default is that nobody but an administrator may write a rule
 * ({@see ConfigLexicon}), and a spec that needs a user to write one has to
 * say so rather than inherit it: the developer instance has it switched on
 * and a fresh one does not, which is the difference between a spec that
 * passes locally and one that passes anywhere.
 *
 * Through the endpoint the admin page uses rather than the config key: the
 * key is declared internal, occ refuses it without `--internal`, and a test
 * that reaches around an app's own lever stops testing the lever.
 *
 * Yields the previous value so a spec can put it back exactly, rather than
 * guessing that it was on.
 *
 * @param {object}  admin  { user, password }
 * @param {boolean} allow  Whether every user may edit rules.
 *
 * @returns {Cypress.Chainable<boolean>}  What it was set to before.
 */
Cypress.Commands.add( 'fciasRuleEditing', ( admin, allow ) => cy.ocs( {
	url: '/settings/global',
	user: admin.user,
	password: admin.password,
} ).then( ( { status, body } ) => {
	expect( status, 'reading the rule-editing permission' ).to.eq( 200 )

	const mine = body.permissions?.rule_editing ?? {}
	const previous = mine.allowAll === true

	return cy.ocs( {
		method: 'PUT',
		url: '/settings/global',
		body: {
			permissions: {
				rule_editing: {
					allowAll: allow,
					groups: mine.groups ?? [],
					users: mine.users ?? [],
				},
			},
		},
		user: admin.user,
		password: admin.password,
	} ).then( ( saved ) => {
		expect( saved.status, 'setting the rule-editing permission' ).to.eq( 200 )

		return previous
	} )
} ) )

/**
 * Pin the instance's default hash algorithm for the length of a spec.
 *
 * The sidebar's first quick button is the user's preference, else this — so a
 * spec that clicks `[data-algo="sha1"]` is asserting on instance state an
 * administrator can change from the settings page, and did. Specs that need a
 * particular algorithm say so here and put back what they found.
 *
 * What comes back is the *effective* default, which is the designated one or
 * the first allowed when none is designated; restoring it therefore pins what
 * had merely been implied. On a test instance that is the honest trade for a
 * deterministic run.
 *
 * @param {object} admin  { user, password }
 * @param {string} algo   The algorithm to designate, or '' to clear it.
 *
 * @returns {Cypress.Chainable<string>}  What it was before.
 */
Cypress.Commands.add( 'fciasDefaultAlgorithm', ( admin, algo ) => cy.ocs( {
	url: '/settings/global',
	user: admin.user,
	password: admin.password,
} ).then( ( { status, body } ) => {
	expect( status, 'reading the default algorithm' ).to.eq( 200 )

	const previous = body.defaultAlgorithm || ''

	return cy.ocs( {
		method: 'PUT',
		url: '/settings/global',
		body: { defaultAlgorithm: algo },
		user: admin.user,
		password: admin.password,
	} ).then( ( saved ) => {
		expect( saved.status, 'setting the default algorithm' ).to.eq( 200 )

		return previous
	} )
} ) )

/**
 * Pin one account's preferred algorithm, normally to '' — no preference.
 *
 * The quick button a spec clicks is the acting user's preference *before* it
 * is the instance default, so pinning the default alone is not enough for a
 * spec that runs as a long-lived account such as the administrator. A fresh
 * account made by fciasMakeAccount has no preference and needs neither.
 *
 * Unlike the default, what comes back is the stored value rather than an
 * effective one, so restoring it puts back exactly what was there.
 *
 * @param {object} who   { user, password }
 * @param {string} algo  The algorithm to prefer, or '' for none.
 *
 * @returns {Cypress.Chainable<string>}  What it was before.
 */
Cypress.Commands.add( 'fciasPreferredAlgorithm', ( who, algo ) => cy.ocs( {
	url: '/api/v1/preferences/preferred_algorithm',
	user: who.user,
	password: who.password,
} ).then( ( { status, body } ) => {
	expect( status, 'reading the preferred algorithm' ).to.eq( 200 )

	const previous = body.value || ''

	return cy.ocs( {
		method: 'PUT',
		url: '/api/v1/preferences/preferred_algorithm',
		body: { value: algo },
		user: who.user,
		password: who.password,
	} ).then( ( saved ) => {
		expect( saved.status, 'setting the preferred algorithm' ).to.eq( 200 )

		return previous
	} )
} ) )

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
 * Cookies are cleared first, every time. `cy.request()` shares the browser's
 * cookie jar, and Nextcloud issues a session on the first authenticated call —
 * so without this, the second call is attributed to the *first* caller
 * whatever credentials it carries. That turns a permission matrix into a
 * matrix about one user: measured, an anonymous request came back 200 and
 * alice was allowed to write a rule for bob, both because the session admin
 * had opened was still in the jar.
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
} ) => cy.clearCookies().then( () => cy.request( {
	method,
	url: `/ocs/v2.php/apps/file_checksum_search${ url }`,
	body,
	auth: { user, pass: password },
	headers: {
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	},
	failOnStatusCode,
} ) ) )

// ---------------------------------------------------------------------------
// NcSelect
//
// The component keeps its selection in reactive state and renders only the
// label, so neither the id nor a whole label is where a test would look for
// them: `input.vs__search` is always empty — it holds what you type, not what
// you picked — and the label is split across two spans by the middle-ellipsis.
//
// Two readings, because they answer different questions. What is stored is
// the id, and it is what a rule is built from. What is shown is the label,
// and it is what a person checks. A test that means one should not assert the
// other.
// ---------------------------------------------------------------------------

/**
 * The id behind the current selection.
 *
 * Read from the `data-selected-id` the app's own `#selected-option` slot
 * puts there — NcSelect's default slot drops everything but the label, so
 * without that override this cannot be asked at all. Nextcloud's own suite
 * asserts the label instead, which is why the override is ours to add.
 *
 * @param {string} inputSelector  The select's input, e.g. '#fcias-rule-selector-target'.
 * @param {string} expected       The option id it should hold.
 */
Cypress.Commands.add( 'assertNcSelectValue', ( inputSelector, expected ) => {
	cy.get( `${ inputSelector }` ).should( 'exist' )
	cy.get( `${ inputSelector }` )
		.closest( '.v-select' )
		.find( '.vs__selected [data-selected-id]' )
		.invoke( 'attr', 'data-selected-id' )
		.should( 'eq', expected )
} )

/**
 * The label the current selection shows.
 *
 * From the `title` attribute rather than the text, because the rendered
 * label is cut into `name-parts__first` and `name-parts__last` and neither
 * half is the whole thing. The title carries it intact.
 *
 * @param {string} inputSelector  The select's input.
 * @param {string} expected       The label it should show.
 */
Cypress.Commands.add( 'assertNcSelectDisplayValue', ( inputSelector, expected ) => {
	cy.get( `${ inputSelector }` ).should( 'exist' )
	cy.get( `${ inputSelector }` )
		.closest( '.v-select' )
		.find( '.vs__selected [title]' )
		.invoke( 'attr', 'title' )
		.should( 'eq', expected )
} )
