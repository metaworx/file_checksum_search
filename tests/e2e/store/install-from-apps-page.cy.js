/**
 * Installs the app from the app store through the Apps page, the way an
 * administrator does: the Files category's list, this app's row, Download
 * and enable in its sidebar, the password confirmation, and the wait while
 * the server downloads, verifies and installs it — which is where the app's
 * own install steps run, inside that one request.
 *
 * Not part of the suite: it runs only in the install check, with
 * `CYPRESS_storeInstall` set to the version under test, on a server pointed
 * at a store that lists it — the fake one before the upload, the real one
 * after. Without it, it skips itself.
 */

const APP_ID = 'file_checksum_search'
const APP_NAME = 'File Checksum Index & Search'

// Nextcloud 33 and 34 both label the action so for an app from the store.
const INSTALL = 'Download and enable'

// Long enough for the store download, the signature check, the install
// steps and the enable, on a runner.
const ENABLE_WAIT_TRIES = 60
const ENABLE_WAIT_MS = 5000

let occ = 'php nextcloud/occ'
let version = null

/** An app action by its label, in any of the forms the two versions render. */
const action = ( label ) => [
	`input[type="button"][value="${ label }"]`,
	`button[aria-label="${ label }"]`,
	`button:contains("${ label }")`,
].join( ', ' )

/**
 * Ask the server, not the page, until the app runs at the version the store
 * published: the page's spinner says nothing about why it stops.
 */
const waitUntilEnabled = ( tries ) => cy.exec( `${ occ } app:list --output=json`, { log: false } ).then( ( { stdout } ) => {
	const enabled = JSON.parse( stdout ).enabled[ APP_ID ] ?? null

	if ( enabled === version ) {
		return
	}

	expect( tries, `the app enabled at ${ version } (now: ${ enabled ?? 'not enabled' })` ).to.be.greaterThan( 0 )
	cy.wait( ENABLE_WAIT_MS, { log: false } )
	waitUntilEnabled( tries - 1 )
} )

describe( 'FCIAS from the app store', () => {
	before( function () {
		const suite = this
		cy.env( [ 'storeInstall', 'occ' ] ).then( ( env ) => {
			if ( ! env.storeInstall ) {
				suite.skip()
			}
			version = String( env.storeInstall )
			if ( env.occ ) {
				occ = env.occ
			}
		} )
	} )

	it( 'installs and enables through the Apps page', () => {
		// The page carries this app's id in its address, so every error the
		// Apps page throws would read as ours to the support file's handler.
		// Ours is what comes from this app's scripts.
		cy.on( 'uncaught:exception', ( err ) => /\/apps\/file_checksum_search\/|file_checksum_search-/.test( err.stack || '' ) )

		cy.login( 'admin', 'admin' )

		// The way an administrator goes: the Files category, the long list
		// every server shows, scrolled to this app's row, which opens the
		// app's sidebar.
		cy.visit( '/index.php/settings/apps/files' )
		cy.contains( APP_NAME, { timeout: 60000 } )
			.scrollIntoView()
			.click()
		cy.location( 'pathname', { timeout: 30000 } ).should( 'include', `/settings/apps/files/${ APP_ID }` )

		// The sidebar offers the release under test, not an older one.
		cy.get( '.app-sidebar', { timeout: 60000 } ).should( 'contain', `Version ${ version }` )

		// In the app's sidebar: the list beside it carries the same action for
		// every app. Nextcloud 33 renders it as an input button with the
		// label as its value, 34 as an icon button labelled for assistive
		// technology, or as a button with the label as text.
		cy.get( '.app-sidebar', { timeout: 60000 } )
			.find( action( INSTALL ), { timeout: 60000 } )
			.first()
			.click()

		// Enabling asks for the password again, unless the login was recent
		// enough; either the dialog or the finished install comes next.
		cy.get( 'body', { timeout: 60000 } ).should( ( $body ) => {
			const dialog = $body.find( '[role="dialog"] input[type="password"]' ).length
			const done = $body.find( '.app-sidebar' ).find( action( 'Disable' ) ).length
			expect( dialog + done, 'the password dialog or the finished install' ).to.be.greaterThan( 0 )
		} ).then( ( $body ) => {
			if ( $body.find( '[role="dialog"] input[type="password"]' ).length ) {
				cy.get( '[role="dialog"] input[type="password"]' ).type( 'admin{enter}' )
			}
		} )

		waitUntilEnabled( ENABLE_WAIT_TRIES )
	} )
} )
