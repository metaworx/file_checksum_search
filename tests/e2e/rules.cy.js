/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E tests for the admin rule definitions (settings page).
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

const ADMIN_SETTINGS_URL = '/index.php/settings/admin/file_checksum_search'

const listRulesUrl = '**/ocs/v2.php/apps/file_checksum_search/settings/cron/definitions*'
const saveRuleUrl = '**/ocs/v2.php/apps/file_checksum_search/settings/cron/save'
const deleteRuleUrl = '**/ocs/v2.php/apps/file_checksum_search/settings/cron/delete'
const toggleRuleUrl = '**/ocs/v2.php/apps/file_checksum_search/settings/cron/toggle'
const statusUrl = '**/ocs/v2.php/apps/file_checksum_search/settings/status*'
const adminOptionsUrl = '**/ocs/v2.php/apps/file_checksum_search/settings/admin-options*'

const supportedAlgos = [ 'sha1', 'md5', 'sha256', 'sha512' ]
const users = [ 'admin' ]

// The first definition is always rendered as the global rule; everything
// after it is shown in the "Additional Rules" table.
const globalRule = {
	id: 1,
	enabled: true,
	mode: 'auto',
	algos: [ 'sha1' ],
	userScope: 'all',
	path: '**',
	admin_enforced: false,
}

const additionalRule = ( id, path, enabled = true ) => ( {
	id,
	enabled,
	mode: 'auto',
	algos: [ 'sha1' ],
	userScope: 'all',
	path,
	admin_enforced: false,
} )

let definitions = []

const stubListRules = () => {
	cy.intercept( 'GET', listRulesUrl, ( req ) => {
		req.reply( { supportedAlgos, users, definitions } )
	} ).as( 'listRules' )
}

const stubAdminPageExtras = () => {
	cy.intercept( 'GET', statusUrl, {
		version: '1.9.2',
		dbVersion: '1',
		rowCount: 0,
		pendingStats: {},
	} ).as( 'status' )
	cy.intercept( 'GET', adminOptionsUrl, {
		success: true,
		allowAllUsers: false,
		groups: [],
		users: [],
		availableUsers: [],
	} ).as( 'adminOptions' )
}

const ruleRow = ( path ) => cy.contains( '#fcias-cron-list td', path ).closest( 'tr' )

describe( 'FCIAS admin rules', () => {
	before( () => {
		occ = Cypress.env( 'occ' ) || occ
		adminUser = Cypress.env( 'NC_ADMIN_USER' ) || adminUser
		adminPassword = Cypress.env( 'NC_ADMIN_PASSWORD' ) || adminPassword
		cy.exec( `${ occ } app:enable ${ appId }`, { failOnNonZeroExit: false } )
	} )

	beforeEach( () => {
		cy.login( adminUser, adminPassword )
	} )

	it( 'adds a rule and shows it in the list', () => {
		definitions = [ globalRule ]
		stubListRules()
		stubAdminPageExtras()
		cy.intercept( 'POST', saveRuleUrl, ( req ) => {
			definitions.push( { id: 'rule-2', ...req.body } )
			req.reply( { success: true } )
		} ).as( 'saveRule' )

		cy.visit( ADMIN_SETTINGS_URL )
		cy.get( '#fcias-admin-settings', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '#fcias-cron-list', { timeout: FIND_TIMEOUT } ).should( 'contain', 'No additional rules.' )

		cy.get( '#fcias-btn-add-definition' ).click()
		cy.get( '#fcias-cron-form' ).should( 'be.visible' )
		cy.get( '#fcias-cron-path' ).clear().type( '/test-dir' )
		cy.get( '#fcias-btn-save-definition' ).click()
		cy.wait( '@saveRule' )

		cy.get( '#fcias-cron-list', { timeout: FIND_TIMEOUT } ).should( 'contain', '/test-dir' )
	} )

	it( 'edits a rule and shows the updated values', () => {
		definitions = [ globalRule, additionalRule( 2, '/old-path' ) ]
		stubListRules()
		stubAdminPageExtras()
		cy.intercept( 'POST', saveRuleUrl, ( req ) => {
			const index = definitions.findIndex( ( d ) => String( d.id ) === String( req.body.id ) )
			if ( index >= 0 ) {
				definitions[ index ] = { ...definitions[ index ], ...req.body }
			}
			req.reply( { success: true } )
		} ).as( 'saveRule' )

		cy.visit( ADMIN_SETTINGS_URL )
		cy.get( '#fcias-cron-list', { timeout: FIND_TIMEOUT } ).should( 'contain', '/old-path' )

		ruleRow( '/old-path' ).find( 'button[data-action="edit"]' ).click()
		cy.get( '#fcias-cron-form' ).should( 'be.visible' )
		cy.get( '#fcias-cron-path' ).should( 'have.value', '/old-path' ).clear().type( '/new-path' )
		cy.get( '#fcias-btn-save-definition' ).click()
		cy.wait( '@saveRule' )

		cy.get( '#fcias-cron-list', { timeout: FIND_TIMEOUT } ).should( 'contain', '/new-path' )
		cy.get( '#fcias-cron-list' ).should( 'not.contain', '/old-path' )
	} )

	it( 'deletes a rule and removes it from the list', () => {
		definitions = [ globalRule, additionalRule( 3, '/delete-me' ) ]
		stubListRules()
		stubAdminPageExtras()
		cy.intercept( 'POST', deleteRuleUrl, ( req ) => {
			definitions = definitions.filter( ( d ) => String( d.id ) !== String( req.body.id ) )
			req.reply( { success: true } )
		} ).as( 'deleteRule' )

		cy.visit( ADMIN_SETTINGS_URL )
		cy.get( '#fcias-cron-list', { timeout: FIND_TIMEOUT } ).should( 'contain', '/delete-me' )

		// Auto-confirm the OC.dialogs.confirm modal instead of interacting
		// with the browser dialog.
		cy.window().then( ( win ) => {
			win.OC.dialogs.confirm = ( text, title, callback ) => callback( true )
		} )

		ruleRow( '/delete-me' ).find( 'button[data-action="delete"]' ).click()
		cy.wait( '@deleteRule' )

		cy.get( '#fcias-cron-list', { timeout: FIND_TIMEOUT } ).should( 'contain', 'No additional rules.' )
	} )

	it( 'toggles a rule and persists the state', () => {
		definitions = [ globalRule, additionalRule( 4, '/toggle-me', true ) ]
		stubListRules()
		stubAdminPageExtras()
		cy.intercept( 'POST', toggleRuleUrl, ( req ) => {
			const rule = definitions.find( ( d ) => String( d.id ) === String( req.body.id ) )
			if ( rule ) {
				rule.enabled = req.body.enabled
			}
			req.reply( { success: true } )
		} ).as( 'toggleRule' )

		cy.visit( ADMIN_SETTINGS_URL )
		cy.get( '#fcias-cron-list', { timeout: FIND_TIMEOUT } ).should( 'contain', '/toggle-me' )
		cy.get( '#fcias-cron-list' ).should( 'contain', 'Enabled' )

		ruleRow( '/toggle-me' ).find( 'button[data-action="toggle"]' ).click()
		cy.wait( '@toggleRule' )
		cy.get( '#fcias-cron-list', { timeout: FIND_TIMEOUT } ).should( 'contain', 'Disabled' )

		ruleRow( '/toggle-me' ).find( 'button[data-action="toggle"]' ).click()
		cy.wait( '@toggleRule' )
		cy.get( '#fcias-cron-list', { timeout: FIND_TIMEOUT } ).should( 'contain', 'Enabled' )
	} )
} )
