/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E test for the German translation as a server delivers it: an
 * account set to German opens its settings, and the page speaks German.
 *
 * What the unit spec cannot say: that Nextcloud loads this app's l10n/
 * bundle for the page at all, and picks de or de_DE by the account's
 * language. The two registers differ in how they address the reader, so
 * the heading that says "your files" tells them apart.
 */

const appId = 'file_checksum_search'

// Default to the CI layout: Cypress runs in the repo root and the
// Nextcloud checkout lives in ./nextcloud. Override via CYPRESS_occ
// for local/ddev runs (e.g. CYPRESS_occ="php /var/www/html/occ").
let occ = 'php nextcloud/occ'
let adminUser = 'admin'
let adminPassword = 'admin'

const FIND_TIMEOUT = 60000

const PERSONAL_URL = '/index.php/settings/user/file_checksum_search_personal'

// One account per register, made in before() and deleted in after().
const accounts = {
	de: { heading: 'Regeln für deine Dateien' },
	de_DE: { heading: 'Regeln für Ihre Dateien' },
}

const admin = () => ( { user: adminUser, password: adminPassword } )

// CI and the harness set force_language=en, which outranks an account's own
// language, so this spec lifts it for its run and puts back what it found.
// default_language stays en: an account with no language of its own, every
// other spec's, still gets English.
let forcedLanguageWas = ''

// Through the provisioning API, as the administrator: what the personal
// settings' language picker sets, without driving the picker.
const setLanguage = ( user, language ) => {
	cy.clearCookies()

	return cy.request( {
		method: 'PUT',
		url: `/ocs/v2.php/cloud/users/${ user }?format=json`,
		auth: { user: adminUser, pass: adminPassword },
		headers: {
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		},
		body: { key: 'language', value: language },
	} ).its( 'status' ).should( 'eq', 200 )
}

describe( 'FCIAS in German', () => {
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

			cy.exec( `${ occ } config:system:get force_language`, { failOnNonZeroExit: false } )
				.then( ( { stdout } ) => {
					forcedLanguageWas = stdout.trim()
				} )
			cy.exec( `${ occ } config:system:delete force_language` )

			// The two shipped defaults, at 7.1 and 8.1: every account's page
			// lists them, so each has a row whose band text to read.
			cy.fciasResetRules( occ )

			for ( const [ language, account ] of Object.entries( accounts ) ) {
				cy.fciasMakeAccount( admin(), `german_${ language }` ).then( ( made ) => {
					Object.assign( account, made )
					setLanguage( made.user, language )
				} )
			}
		} )
	} )

	after( () => {
		for ( const account of Object.values( accounts ) ) {
			cy.fciasDeleteAccount( admin(), account.user )
		}

		if ( forcedLanguageWas ) {
			cy.exec( `${ occ } config:system:set force_language --value=${ forcedLanguageWas }` )
		}
	} )

	for ( const [ language, account ] of Object.entries( accounts ) ) {
		it( `speaks ${ language } to an account set to it`, () => {
			cy.login( account.user, account.password )
			cy.visit( PERSONAL_URL )

			cy.contains( '#fcias-tab-panel-rules h4', account.heading, { timeout: FIND_TIMEOUT } )
				.should( 'be.visible' )

			// A text with placeholders, filled after the lookup: the home
			// folders' default sits first in band 7.
			cy.get( '#fcias-personal-rules tr[data-band="7"] .fcias-priority-cell' )
				.first()
				.should( 'have.attr', 'title', 'Segment 7, Position 1' )
		} )
	}
} )
