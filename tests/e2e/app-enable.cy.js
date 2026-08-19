const appId = 'file_checksum_search'
const appName = 'File Checksum Index & Search'

// Default to the CI layout: Cypress runs in the repo root and the
// Nextcloud checkout lives in ./nextcloud. Override via CYPRESS_occ
// for local/ddev runs (e.g. CYPRESS_occ="php /var/www/html/occ").
let occ = 'php nextcloud/occ'
let adminUser = 'admin'
let adminPassword = 'admin'

// Allow a generous timeout when locating the app row: the first app-list
// request can be slow on a cold PHP worker.
const FIND_TIMEOUT = 60000

const assertAppEnabledCli = () => {
	cy.exec( `${ occ } app:list --enabled` ).then( ( { stdout } ) => {
		expect( stdout ).to.include( appId )
	} )
}

const assertAppDisabledCli = () => {
	cy.exec( `${ occ } app:list --disabled` ).then( ( { stdout } ) => {
		expect( stdout ).to.include( appId )
	} )
}

describe( 'FCIAS App Enable', () => {
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
		} )
	} )

	beforeEach( () => {
		cy.login( adminUser, adminPassword )
	} )

	it( 'enables the app via the web UI and verifies the enabled state (UI + CLI)', () => {
		// Precondition: start disabled. The app is already installed by CI,
		// so disabling it leaves it visible under "Disabled apps".
		cy.exec( `${ occ } app:disable ${ appId }`, { failOnNonZeroExit: false } )
		assertAppDisabledCli()

		// Navigate to the Apps page, "Disabled apps" category.
		cy.visit( '/index.php/settings/apps/disabled' )

		// Find the FCIAS app entry (its name links to the app details page).
		cy.get( `a[href*="${ appId }"]`, { timeout: FIND_TIMEOUT } )
			.closest( 'tr.app-item' )
			.should( 'exist' )
			.as( 'appRow' )

		// Enable the app via the UI.
		cy.get( '@appRow' ).within( () => {
			cy.contains( 'button', 'Enable' ).click()
		} )

		// Enabling an app requires password confirmation (strict mode).
		cy.get( 'input[type="password"]', { timeout: FIND_TIMEOUT } ).type( adminPassword )
		cy.contains( 'button', 'Confirm' ).click()

		// The app leaves the "Disabled apps" list once it has been enabled.
		cy.get( `a[href*="${ appId }"]` ).should( 'not.exist' )

		// UI: it is now listed under the enabled apps ("Active apps").
		cy.visit( '/index.php/settings/apps/enabled' )
		cy.get( `a[href*="${ appId }"]`, { timeout: FIND_TIMEOUT } )
			.closest( 'tr.app-item' )
			.within( () => {
				cy.contains( 'button', 'Disable' ).should( 'exist' )
			} )

		// CLI: it is now enabled.
		assertAppEnabledCli()

		// Navigate to the app's admin settings section and verify it renders.
		cy.visit( `/index.php/settings/admin/${ appId }` )
		cy.get( '#fcias-admin-settings', { timeout: FIND_TIMEOUT } )
			.should( 'exist' )
			.and( 'contain', appName )
	} )

	it( 'disables the app via the web UI and verifies the disabled state (UI + CLI)', () => {
		// Precondition: start enabled.
		cy.exec( `${ occ } app:enable ${ appId }`, { failOnNonZeroExit: false } )
		assertAppEnabledCli()

		cy.visit( '/index.php/settings/apps/enabled' )

		cy.get( `a[href*="${ appId }"]`, { timeout: FIND_TIMEOUT } )
			.closest( 'tr.app-item' )
			.should( 'exist' )
			.as( 'appRow' )

		// Disable the app via the UI.
		cy.get( '@appRow' ).within( () => {
			cy.contains( 'button', 'Disable' ).click()
		} )

		// The app leaves the "Active apps" list once it has been disabled.
		cy.get( `a[href*="${ appId }"]` ).should( 'not.exist' )

		// UI: it is now listed under "Disabled apps".
		cy.visit( '/index.php/settings/apps/disabled' )
		cy.get( `a[href*="${ appId }"]`, { timeout: FIND_TIMEOUT } )
			.closest( 'tr.app-item' )
			.within( () => {
				cy.contains( 'button', 'Enable' ).should( 'exist' )
			} )

		// CLI: it is now disabled.
		assertAppDisabledCli()

		// Leave the app enabled for any specs that run afterwards.
		cy.exec( `${ occ } app:enable ${ appId }`, { failOnNonZeroExit: false } )
	} )
} )
