/**
 * Installs the app from the app store through the Apps page, the way an
 * administrator does: the app's page, Download and enable, the password
 * confirmation, and the wait while the server downloads, verifies and
 * installs it — which is where the app's own install steps run, inside
 * that one request.
 *
 * Not part of the suite: it runs only in the post-publish workflow, with
 * `CYPRESS_storeInstall` set to the version the store published, on a
 * server that may reach the store. Without it, it skips itself.
 */

const APP_ID = 'file_checksum_search'

// Nextcloud 33 and 34 both label the action so for an app from the store.
const INSTALL = 'Download and enable'

// Long enough for the store download, the signature check, the install
// steps and the enable, on a runner.
const ENABLE_WAIT_TRIES = 60
const ENABLE_WAIT_MS = 5000

let occ = 'php nextcloud/occ'
let version = null

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
		cy.visit( `/index.php/settings/apps/files/${ APP_ID }` )

		// Nextcloud 33 renders the action as an input button, 34 as a button.
		cy.get( `input[type="button"][value="${ INSTALL }"], button:contains("${ INSTALL }")`, { timeout: 60000 } )
			.first()
			.click()

		// Enabling asks for the password again, unless the login was recent
		// enough; either the dialog or the finished install comes next.
		cy.get( 'body', { timeout: 60000 } ).should( ( $body ) => {
			const dialog = $body.find( '[role="dialog"] input[type="password"]' ).length
			const done = $body.find( 'input[type="button"][value="Disable"], button:contains("Disable")' ).length
			expect( dialog + done, 'the password dialog or the finished install' ).to.be.greaterThan( 0 )
		} ).then( ( $body ) => {
			if ( $body.find( '[role="dialog"] input[type="password"]' ).length ) {
				cy.get( '[role="dialog"] input[type="password"]' ).type( 'admin{enter}' )
			}
		} )

		waitUntilEnabled( ENABLE_WAIT_TRIES )
	} )
} )
