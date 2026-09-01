/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E tests for the admin rules page: the banded table, the idle
 * banner, the rule dialog, and the row action menu.
 *
 * Live, with no stubs. The spec it replaces stubbed four endpoints that
 * had since been removed, so it kept passing its own fiction until the
 * day someone ran it against a server.
 *
 * Selectors are ids, data-* attributes and API payload fields — never the
 * app's own visible strings, which are not translated yet and will move
 * when they are.
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

const ADMIN_URL = '/index.php/settings/admin/file_checksum_search'

// A group folder id nothing owns, so the rule naming it is inert by
// construction — which is what the "provider missing" badge is for.
const GONE_GROUP_FOLDER = 99

// The rules the admin page sees. `scope=all` rather than the default
// `own`: the page asks for that view, and it is the difference between
// canEdit true and false on a rule whose selector is not this admin's
// own home — which decides whether the row has an action menu at all.
const rules = () => cy.ocs( {
	url: '/api/v1/rules?scope=all',
	user: adminUser,
	password: adminPassword,
} ).then( ( { status, body } ) => {
	expect( status ).to.eq( 200 )

	return body.rules
} )

// Open a row's action menu and click one of its items. The menu is an
// NcActions popover: the trigger lives inside the <tr>, and the items it
// opens render outside it, so the item lookup cannot be scoped to the row.
const rowAction = ( row, action ) => {
	cy.get( `${ row } .action-item__menutoggle`, { timeout: FIND_TIMEOUT } )
		.first()
		.click()
	cy.get( `.action-item__popper [data-action="${ action }"]`, { timeout: FIND_TIMEOUT } )
		.click()
}

describe( 'FCIAS admin rules', () => {
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
			cy.fciasResetRules( occ )
		} )
	} )

	beforeEach( () => {
		cy.login( adminUser, adminPassword )
	} )

	after( () => {
		// This spec enables a rule and creates two, and the specs after it
		// assume the quiet-start state as much as this one does.
		cy.fciasResetRules( occ )
	} )

	it( 'quiet start: two disabled defaults and the idle banner', () => {
		cy.visit( ADMIN_URL )

		cy.get( '#fcias-idle-banner', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '#fcias-cron-list tr[data-band="7"]' ).should( 'have.length', 1 )
		cy.get( '#fcias-cron-list tr[data-band="8"]' ).should( 'have.length', 1 )

		// Both shipped disabled, which is the promise the banner is about:
		// the app computes nothing until an administrator says so.
		cy.get( '#fcias-cron-list tr[data-band] .fcias-compat-fail' ).should( 'have.length', 2 )
		cy.get( '#fcias-cron-list tr[data-band] .fcias-compat-pass' ).should( 'not.exist' )

		rules().then( ( list ) => {
			expect( list ).to.have.length( 2 )
			expect( list.every( ( r ) => r.isDefault && ! r.enabled ) ).to.eq( true )
		} )
	} )

	it( 'banner: Close hides it for the view, Acknowledged persists', () => {
		cy.visit( ADMIN_URL )

		cy.get( '#fcias-idle-banner [data-action="banner-close"]', { timeout: FIND_TIMEOUT } ).click()
		cy.get( '#fcias-idle-banner' ).should( 'not.exist' )

		// Closing is for this view only: the banner is back on reload.
		cy.reload()
		cy.get( '#fcias-idle-banner', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		cy.get( '#fcias-idle-banner [data-action="banner-ack"]' ).click()
		cy.reload()
		cy.get( '#fcias-cron-list', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '#fcias-idle-banner' ).should( 'not.exist' )

		// Acknowledged is stored, not merely remembered by the page.
		cy.exec( `${ occ } config:app:get ${ appId } idle_banner_ack`, {
			failOnNonZeroExit: false,
		} ).its( 'stdout' ).should( 'not.be.empty' )
	} )

	it( 'enabling an include rule clears the acknowledgement', () => {
		cy.visit( ADMIN_URL )
		cy.get( '#fcias-cron-list tr[data-band="7"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		rowAction( '#fcias-cron-list tr[data-band="7"]', 'toggle' )

		cy.get( '#fcias-cron-list tr[data-band="7"] .fcias-compat-pass', { timeout: FIND_TIMEOUT } )
			.should( 'exist' )
		cy.get( '#fcias-idle-banner' ).should( 'not.exist' )

		// The ack expires by itself once the state it acknowledged is gone,
		// so a later return to idle shows the banner afresh rather than
		// staying silent about it.
		cy.exec( `${ occ } config:app:get ${ appId } idle_banner_ack`, {
			failOnNonZeroExit: false,
		} ).its( 'stdout' ).should( 'be.empty' )
	} )

	it( 'creates a rule through the dialog; it lands above its segment default', () => {
		cy.visit( ADMIN_URL )
		cy.get( '#fcias-btn-add-definition', { timeout: FIND_TIMEOUT } ).click()
		cy.get( '#fcias-cron-form' ).should( 'be.visible' )

		// All home folders, which is the segment the shipped band-7 default
		// sits in — so "above the default" is a question with an answer.
		// A named path rather than **, which is what keeps it out of the
		// defaults partition.
		cy.get( '#fcias-cron-userscope' ).select( 'homeAll' )
		cy.get( '#fcias-cron-path' ).clear().type( '/Photos/**' )
		cy.get( '#fcias-btn-save-definition' ).click()

		cy.get( '#fcias-cron-form' ).should( 'not.exist' )

		rules().then( ( list ) => {
			const band7 = list.filter( ( r ) => r.band === 7 )

			expect( band7, 'the new rule joins the shipped default' ).to.have.length( 2 )

			const created = band7.find( ( r ) => r.path === '/Photos/**' )

			expect( created, 'the rule the dialog saved' ).to.exist
			expect( created.selector ).to.eq( 'home:*' )
			expect( created.isDefault ).to.eq( false )

			// The derived defaults partition: a rule whose path is ** trails
			// its segment, and a newly created one can never be dragged
			// behind it. Position is 1-based within the segment.
			const shipped = band7.find( ( r ) => r.isDefault )

			expect( created.position ).to.be.lessThan( shipped.position )
		} )
	} )

	it( 'a placeholder row seeds the dialog with that namespace', () => {
		cy.visit( ADMIN_URL )
		cy.get( '#fcias-cron-list tr[data-placeholder]', { timeout: FIND_TIMEOUT } )
			.should( 'have.length.at.least', 1 )

		cy.get( '#fcias-cron-list tr[data-placeholder] [data-action="create"]' ).first().click()

		cy.get( '#fcias-cron-form', { timeout: FIND_TIMEOUT } ).should( 'be.visible' )
		cy.get( '#fcias-cron-path' ).should( 'have.value', '**' )
	} )

	it( 'refuses a bad rule inside the dialog rather than behind it', () => {
		cy.visit( ADMIN_URL )
		cy.get( '#fcias-btn-add-definition', { timeout: FIND_TIMEOUT } ).click()
		cy.get( '#fcias-cron-form' ).should( 'be.visible' )

		// A storage selector with no storage named: the server refuses it,
		// and the error belongs on the form still on screen rather than on
		// the page behind it.
		cy.get( '#fcias-cron-userscope' ).select( 'storage' )
		cy.get( '#fcias-cron-scope-target' ).clear()
		cy.get( '#fcias-cron-path' ).clear().type( '**' )
		cy.get( '#fcias-btn-save-definition' ).click()

		cy.get( '#fcias-cron-form .fcias-form-error', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '#fcias-cron-form' ).should( 'be.visible' )
	} )

	it( 'badges a rule whose provider is gone', () => {
		cy.exec(
			`${ occ } fcias:rules:add --selector groupfolder:${ GONE_GROUP_FOLDER }`
			+ ' --path \'**\' --type include -a sha1',
		)

		cy.visit( ADMIN_URL )
		cy.get( '#fcias-cron-list', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		// Inert by construction: nothing can match a folder that is not
		// there, and silence about that reads as a bug in the app.
		cy.get( '.fcias-provider-missing', { timeout: FIND_TIMEOUT } ).should( 'exist' )
	} )

	it( 'offers Re-apply only where it can succeed', () => {
		cy.visit( ADMIN_URL )
		cy.get( '#fcias-cron-list tr[data-band="7"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		// Enabled include rule: re-applying queues the same uncapped pass
		// `occ fcias:rules:apply` runs.
		cy.get( '#fcias-cron-list tr[data-band="7"] .action-item__menutoggle' ).first().click()
		cy.get( '.action-item__popper [data-action="apply"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		// A reload rather than dismissing the popover: NcActions leaves the
		// popper it opened in the DOM, so asserting that the *next* menu has
		// no Re-apply would keep finding the previous one's.
		cy.reload()
		cy.get( '#fcias-cron-list tr[data-band="8"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		// Disabled one: the server refuses it at submission, so the UI does
		// not offer it.
		cy.get( '#fcias-cron-list tr[data-band="8"] .action-item__menutoggle' ).first().click()
		cy.get( '.action-item__popper [data-action="toggle"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '.action-item__popper [data-action="apply"]' ).should( 'not.exist' )
	} )
} )
