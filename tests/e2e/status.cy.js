/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E tests for the admin status panel.
 *
 * The page an operator looks at when they want to know whether this app
 * is doing anything, so the assertions are that its numbers are the
 * instance's numbers rather than that its rows render. Each is checked
 * against the same figure read another way — the API, occ, or a count
 * this spec put there itself.
 */

const appId = 'file_checksum_search'

// Default to the CI layout: Cypress runs in the repo root and the
// Nextcloud checkout lives in ./nextcloud. Override via CYPRESS_occ
// for local/ddev runs (e.g. CYPRESS_occ="php /var/www/html/occ").
let occ = 'php nextcloud/occ'
let adminUser = 'admin'
let adminPassword = 'admin'

const FIND_TIMEOUT = 60000

const ADMIN_URL = '/index.php/settings/admin/file_checksum_search'

// The status table is a tab of its own; the hash opens it directly.
const STATUS_URL = `${ ADMIN_URL }#status`

// The three files fixtures/duplicates.json states hashes for, recreated
// here so this spec does not depend on the duplicates spec having run.
const stateDir = 'fcias-e2e-duplicates'
const stateFiles = [
	{ name: 'a.txt', content: 'foo' },
	{ name: 'b.txt', content: 'foo' },
	{ name: 'c.txt', content: 'bar' },
]

const webdavUrl = ( path ) => `/remote.php/dav/files/${ adminUser }${ path }`

// Put the instance back to exactly three stored hashes, and prove it.
//
// Per test rather than once per spec, because this spec resets and
// disowns on purpose: a count established once at the top is not still
// true by the test after the one that clears everything. Building the
// state next to the assertion that needs it is what lets a number in
// this file mean something.
const givenThreeHashes = () => {
	cy.resetFciasState( occ )
	cy.importFciasFixture( occ, 'duplicates', adminUser )
	cy.exec( `${ occ } file-checksum-search:status -o json`, { timeout: 300000 } )
		.then( ( { stdout } ) => {
			expect( JSON.parse( stdout ).untrusted_total, 'nothing disowned yet' ).to.eq( 0 )
		} )

	// The count itself, from the endpoint the panel reads.
	statusFromApi().its( 'rowCount' ).should( 'eq', 3 )
}

// The status payload, read without disturbing the browser session. A
// plain cy.request rather than cy.ocs(): everything in this spec is the
// administrator, so there is no identity to keep honest, and cy.ocs()
// clears the cookies the next cy.visit() needs.
const statusFromApi = () => cy.request( {
	url: '/ocs/v2.php/apps/file_checksum_search/settings/status',
	auth: { user: adminUser, pass: adminPassword },
	headers: { 'OCS-APIRequest': 'true' },
} ).its( 'body' )

// The same figures read from the command line. Deliberately occ rather
// than cy.ocs(): this spec drives the page, and cy.ocs() clears cookies
// to keep an API caller's identity honest — which ends the session, and
// a status panel with no session renders every cell as an em dash. Every
// failure in this spec's first two runs was that one mistake.
const statusFromOcc = () => cy.exec( `${ occ } file-checksum-search:status -o json`, {
	timeout: 300000,
} ).then( ( { stdout } ) => JSON.parse( stdout ) )

// The page's own text for a cell, whitespace flattened — these cells are
// numbers and timestamps rather than translatable prose, which is what
// makes reading them legitimate.
//
// Built from chained invoke() and no then(). A then() resolves once and
// hands on a plain string, so the assertion after it stops retrying the
// DOM — and every cell here renders a placeholder first and its value
// when the status request answers. Written with then(), this read the
// placeholder and reported the panel as broken.
const cellText = ( id ) => cy.get( id, { timeout: FIND_TIMEOUT } )
	.invoke( 'text' )
	.invoke( 'replace', /\s+/g, ' ' )
	.invoke( 'trim' )

describe( 'FCIAS status panel', () => {
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
				url: webdavUrl( `/${ stateDir }` ),
				auth: { user: adminUser, pass: adminPassword },
				failOnStatusCode: false,
			} )

			for ( const { name, content } of stateFiles ) {
				cy.request( {
					method: 'PUT',
					url: webdavUrl( `/${ stateDir }/${ name }` ),
					auth: { user: adminUser, pass: adminPassword },
					headers: { 'Content-Type': 'text/plain' },
					body: content,
				} )
			}

			givenThreeHashes()
		} )
	} )

	beforeEach( () => {
		cy.login( adminUser, adminPassword )
	} )

	after( () => {
		// This spec disowns and clears every hash on the instance, which is
		// the point of it — put the shipped state back for whatever runs
		// next, here or on a developer's machine.
		cy.resetFciasState( occ )
	} )

	it( 'counts the hashes the instance actually holds', () => {
		givenThreeHashes()
		cy.visit( STATUS_URL )

		// Three files, one algorithm each: the fixture's own arithmetic, so
		// a page that reported a plausible-looking number from somewhere
		// else would fail here.
		cellText( '#fcias-status-rowcount' ).should( 'eq', '3' )
	} )

	it( 'names the app and database versions it is running on', () => {
		cy.visit( STATUS_URL )

		statusFromOcc().then( ( fromOcc ) => {
			cellText( '#fcias-status-version' ).should( 'eq', fromOcc.app_version )
		} )

		// No second source for the database version, so the assertion is the
		// one that matters anyway: an em dash here means the status request
		// failed, which is what every other cell would be hiding too.
		cellText( '#fcias-status-dbversion' ).should( 'not.eq', '—' ).and( 'not.be.empty' )
	} )

	it( 'reports an empty queue as empty rather than as nothing', () => {
		givenThreeHashes()
		cy.visit( STATUS_URL )

		// A blank cell and a zero look the same to a reader who does not
		// already know which they are looking at, so the page says Total: 0.
		cellText( '#fcias-status-pending' ).should( 'contain', 'Total: 0' )
		cellText( '#fcias-status-untrusted' ).should( 'contain', 'Total: 0' )

		// Both cells render "Total: 0" when the stats are empty *and* when
		// the status request failed outright, so on their own they are also
		// what a broken panel looks like. This is the cell that tells the
		// two apart: it shows a number the page could not have invented.
		cellText( '#fcias-status-rowcount' ).should( 'eq', '3' )
	} )

	it( 'counts what a reset disowned, and what finishing it leaves', () => {
		givenThreeHashes()

		// Erosion and reset are the two ways hashes stop being trusted, and
		// this is the one an operator causes on purpose. Marked rather than
		// cleared, so the count exists before the background job runs.
		cy.exec( `${ occ } fcias:reset --hashes --force`, { timeout: 300000 } )

		statusFromOcc().then( ( fromOcc ) => {
			expect( fromOcc.untrusted_total, 'the disowned files' ).to.eq( 3 )
			expect( Object.keys( fromOcc.untrusted_by_reason ).join( ',' ) )
				.to.contain( 'stale:reset' )
		} )

		// The page says the same, refreshed through its own button rather
		// than by revisiting the URL it is already on.
		cy.visit( STATUS_URL )
		cy.get( '#fcias-btn-refresh-status', { timeout: FIND_TIMEOUT } ).click()
		cellText( '#fcias-status-untrusted' ).should( 'contain', 'Total: 3' )

		// Finish what the reset deferred. Clearing a disowned file's hashes
		// reads no file content, which is what makes it a repair step rather
		// than work for the hashing job.
		cy.exec( `${ occ } fcias:repair --step clear-disowned`, { timeout: 300000 } )

		statusFromOcc().then( ( fromOcc ) => {
			expect( fromOcc.untrusted_total, 'nothing left disowned' ).to.eq( 0 )
		} )

		cy.get( '#fcias-btn-refresh-status' ).click()
		cellText( '#fcias-status-untrusted' ).should( 'contain', 'Total: 0' )
		cellText( '#fcias-status-rowcount' ).should( 'eq', '0' )
	} )

	it( 'moves a background job\'s clock when that job runs', () => {
		// The point of the row is the timestamp, not the counts: a job that
		// stopped running is invisible until somebody notices its clock has
		// not moved. So the assertion is that the clock moved, which means
		// reading it before and after.
		//
		// Through the job rather than through `fcias:queue:drain`: the
		// command does the same work but books nothing, because the
		// heartbeat answers "is the scheduler alive", and a person running
		// a command by hand is not evidence that it is. Asserting after the
		// command instead would have passed on a two-day-old timestamp,
		// which is exactly the state the row exists to make visible.
		statusFromApi().then( ( before ) => {
			cy.exec(
				`${ occ } background-job:list --class`
				+ ' \'OCA\\FileChecksumSearch\\BackgroundJob\\ProcessPendingUpdates\''
				+ ' --output=json',
				{ timeout: 300000 },
			).then( ( { stdout } ) => {
				const job = JSON.parse( stdout )[ 0 ]

				expect( job, 'the drain is scheduled at all' ).to.exist

				cy.exec( `${ occ } background-job:execute ${ job.id } --force-execute`, {
					timeout: 300000,
				} )
			} )

			cy.visit( STATUS_URL )
			cy.get( '#fcias-status-jobs .fcias-job-grid', { timeout: FIND_TIMEOUT } ).should( 'exist' )
			cy.get( '#fcias-status-jobs .fcias-job-time' ).should( 'have.length.at.least', 1 )

			statusFromApi().then( ( after ) => {
				expect(
					after.jobs.pending_drain.lastRun,
					'the drain booked the run it just made',
				).to.be.greaterThan( before.jobs.pending_drain?.lastRun ?? 0 )
			} )
		} )
	} )

	it( 'refreshes without a page load', () => {
		givenThreeHashes()
		cy.visit( STATUS_URL )
		cellText( '#fcias-status-rowcount' ).should( 'eq', '3' )

		// Change something behind the page's back, then ask it to look
		// again. Asserting that the timestamp is not an em dash proved
		// nothing: it is filled on mount, so a Refresh button wired to
		// nothing passed.
		cy.exec( `${ occ } fcias:reset --hashes --status --force --now`, { timeout: 300000 } )

		cy.get( '#fcias-btn-refresh-status' ).click()
		cellText( '#fcias-status-rowcount' ).should( 'eq', '0' )
	} )
} )
