/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E tests for the sudo-token pages: the personal section where
 * an app password is granted the cross-account routes, and the admin tab
 * where every grant on the instance is listed and can be revoked.
 *
 * Live, from a browser session, because that is precisely what had no
 * coverage: both listings answered 200 to curl with Basic auth while every
 * browser was refused — the requests carried no request token, and core's
 * CSRF check turned them away before they reached the app. The admin tab
 * blamed the token table for it; the personal section said "No app
 * passwords yet" to a user who had one.
 *
 * Selectors are ids and data-* attributes, never the app's own visible
 * strings, which are not translated yet and will move when they are. The
 * one exception is the app password's name, which this spec chose itself.
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
const PERSONAL_URL = '/index.php/settings/user/file_checksum_search_personal'

// Made in before(), deleted in after(); the account takes its tokens and
// its grants with it, so nothing here needs sweeping by hand.
let holder = {}

// The app password occ mints for the holder. Named by this spec so the
// row can be found by it; the id is read back from occ once it exists.
const APP_PASSWORD_NAME = 'fcias-e2e-sudo'
let appPasswordId = null

// The switch NcCheckboxRadioSwitch renders: a checkbox inside a label,
// and the label is what takes the click.
const switchIn = ( row ) => cy.get( `${ row } .checkbox-radio-switch input[type="checkbox"]`, { timeout: FIND_TIMEOUT } )

describe( 'FCIAS sudo tokens', () => {
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

			cy.fciasMakeAccount( { user: adminUser, password: adminPassword }, 'sudo' ).then( ( account ) => {
				holder = account
			} )
		} ).then( () => {
			// Non-interactive: occ then mints an app password without the
			// login password, which is all a listing needs. The token's
			// secret is printed and ignored; the page never sees secrets.
			cy.exec( `${ occ } user:auth-tokens:add ${ holder.user } --name=${ APP_PASSWORD_NAME } -n` )
			cy.exec( `${ occ } user:auth-tokens:list ${ holder.user } --output=json` ).then( ( { stdout } ) => {
				const token = JSON.parse( stdout ).find( ( t ) => t.name === APP_PASSWORD_NAME )
				expect( token, 'the app password occ minted' ).to.not.eq( undefined )
				appPasswordId = token.id
			} )
		} )
	} )

	after( () => {
		if ( holder.user ) {
			cy.fciasDeleteAccount( { user: adminUser, password: adminPassword }, holder.user )
		}
	} )

	it( 'the admin tab loads its listing', () => {
		cy.login( adminUser, adminPassword )
		cy.visit( `${ ADMIN_URL }#tokens` )

		// Either answer is fine — an instance may carry real grants — but
		// the error line is not: that was the symptom.
		cy.get( '[data-testid="fcias-sudo-grants-empty"], [data-testid="fcias-sudo-grants"]', { timeout: FIND_TIMEOUT } )
			.should( 'exist' )
		cy.get( '[data-testid="fcias-sudo-grants-error"]' ).should( 'not.exist' )
	} )

	it( 'a user sees their app password and grants it', () => {
		cy.login( holder.user, holder.password )
		cy.visit( PERSONAL_URL )

		const row = `#fcias-personal-sudo-tokens [data-token-id="${ appPasswordId }"]`

		cy.get( row, { timeout: FIND_TIMEOUT } ).should( 'contain', APP_PASSWORD_NAME )
		cy.get( '[data-testid="fcias-sudo-tokens-error"]' ).should( 'not.exist' )

		// Within thirty minutes of a login core's confirmation dialog does
		// not appear — @nextcloud/password-confirmation reads the page's
		// last-login stamp — and a Cypress session is always that fresh.
		switchIn( row ).should( 'not.be.checked' )
		switchIn( row ).click( { force: true } )
		switchIn( row ).should( 'be.checked' )
	} )

	it( 'the admin tab lists the grant and revokes it', () => {
		cy.login( adminUser, adminPassword )
		cy.visit( `${ ADMIN_URL }#tokens` )

		const row = `[data-grant="${ holder.user }/${ appPasswordId }"]`

		cy.get( row, { timeout: FIND_TIMEOUT } ).should( 'contain', APP_PASSWORD_NAME )
		cy.get( `${ row } button` ).click()
		cy.get( row, { timeout: FIND_TIMEOUT } ).should( 'not.exist' )
		cy.get( '[data-testid="fcias-sudo-grants-error"]' ).should( 'not.exist' )
	} )
} )
