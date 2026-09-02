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

// Open the admin page and wait for it to stop moving.
//
// The page fetches its rules and its status separately, and the table
// re-renders when each answers. Clicking before both have landed fails
// with "the page updated while this command was executing" — about one
// run in three, on whichever click happened to be first.
// Group folder id => the name the server reports for it, filled in before()
// from the same payload the page reads.
const groupFolderNames = {}


const visitAdmin = () => {
	cy.intercept( 'GET', '**/apps/file_checksum_search/api/v1/rules*' ).as( 'rulesLoaded' )
	cy.intercept( 'GET', '**/apps/file_checksum_search/settings/status*' ).as( 'statusLoaded' )

	cy.visit( ADMIN_URL )

	cy.wait( [ '@rulesLoaded', '@statusLoaded' ], { timeout: FIND_TIMEOUT } )
	cy.get( '#fcias-rules-list', { timeout: FIND_TIMEOUT } ).should( 'exist' )
}


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

			// The folder names the page will show, from the payload it
			// reads them from — so the label assertion below compares
			// against the server's answer rather than a name typed here.
			rules().then( ( _list ) => {
				cy.ocs( {
					url: '/api/v1/rules?scope=all',
					user: adminUser,
					password: adminPassword,
				} ).then( ( { body } ) => {
					for ( const folder of body.availableGroupFolders ?? [] )
					{
						groupFolderNames[ String( folder.id ) ] = folder.name
					}
				} )
			} )
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
		visitAdmin()

		cy.get( '#fcias-idle-banner', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '#fcias-rules-list tr[data-band="7"]' ).should( 'have.length', 1 )
		cy.get( '#fcias-rules-list tr[data-band="8"]' ).should( 'have.length', 1 )

		// Both shipped disabled, which is the promise the banner is about:
		// the app computes nothing until an administrator says so.
		cy.get( '#fcias-rules-list tr[data-band] .fcias-compat-fail' ).should( 'have.length', 2 )
		cy.get( '#fcias-rules-list tr[data-band] .fcias-compat-pass' ).should( 'not.exist' )

		rules().then( ( list ) => {
			expect( list ).to.have.length( 2 )
			expect( list.every( ( r ) => r.isDefault && ! r.enabled ) ).to.eq( true )
		} )
	} )

	it( 'banner: Close hides it for the view, Acknowledged persists', () => {
		visitAdmin()

		cy.get( '#fcias-idle-banner [data-action="banner-close"]', { timeout: FIND_TIMEOUT } ).click()
		cy.get( '#fcias-idle-banner' ).should( 'not.exist' )

		// Closing is for this view only: the banner is back on reload.
		cy.reload()
		cy.get( '#fcias-idle-banner', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		cy.get( '#fcias-idle-banner [data-action="banner-ack"]' ).click()
		cy.reload()
		cy.get( '#fcias-rules-list', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '#fcias-idle-banner' ).should( 'not.exist' )

		// Acknowledged is stored, not merely remembered by the page.
		cy.exec( `${ occ } config:app:get ${ appId } idle_banner_ack`, {
			failOnNonZeroExit: false,
		} ).its( 'stdout' ).should( 'not.be.empty' )
	} )

	it( 'enabling an include rule clears the acknowledgement', () => {
		// Set here rather than inherited from the test above: this asserts
		// the ack goes away, which is not an assertion at all if it was
		// never there. Ordering made it true; saying so makes it stay true.
		cy.ocs( {
			method: 'POST',
			url: '/settings/idle-banner/ack',
			user: adminUser,
			password: adminPassword,
		} ).its( 'status' ).should( 'eq', 200 )

		cy.exec( `${ occ } config:app:get ${ appId } idle_banner_ack`, {
			failOnNonZeroExit: false,
		} ).its( 'stdout' ).should( 'not.be.empty' )

		cy.login( adminUser, adminPassword )
		visitAdmin()
		cy.get( '#fcias-rules-list tr[data-band="7"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		rowAction( '#fcias-rules-list tr[data-band="7"]', 'toggle' )

		cy.get( '#fcias-rules-list tr[data-band="7"] .fcias-compat-pass', { timeout: FIND_TIMEOUT } )
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
		visitAdmin()
		cy.get( '#fcias-btn-add-rule', { timeout: FIND_TIMEOUT } ).click()
		cy.get( '#fcias-rule-form' ).should( 'be.visible' )

		// All home folders, which is the segment the shipped band-7 default
		// sits in — so "above the default" is a question with an answer.
		// A named path rather than **, which is what keeps it out of the
		// defaults partition.
		cy.get( '#fcias-cron-userscope' ).select( 'homeAll' )
		cy.get( '#fcias-rule-path' ).clear().type( '/Photos/**' )
		cy.get( '#fcias-btn-save-rule' ).click()

		cy.get( '#fcias-rule-form' ).should( 'not.exist' )

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
		visitAdmin()

		// Which namespaces appear as placeholders is derived from which of
		// them already have a catch-all rule, so the set is only meaningful
		// once the rules have rendered.
		cy.get( '#fcias-rules-list tr[data-band="7"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '#fcias-rules-list tr[data-band="8"]' ).should( 'exist' )

		cy.get( '#fcias-rules-list tr[data-placeholder]', { timeout: FIND_TIMEOUT } )
			.should( 'have.length.at.least', 1 )

		// The namespace the row stands for, read off the row itself — the
		// point of a placeholder is that it seeds the dialog with *that*
		// namespace, and asserting only the path proved nothing about which
		// one was chosen.
		cy.get( '#fcias-rules-list tr[data-placeholder]' ).first()
			.invoke( 'attr', 'data-placeholder' )
			.then( ( selector ) => {
				// Addressed by that value rather than by position a second
				// time: two `.first()` queries against a table that can
				// re-render could pick different rows.
				cy.get(
					`#fcias-rules-list tr[data-placeholder="${ selector }"] [data-action="create"]`,
				).click()

				cy.get( '#fcias-rule-form', { timeout: FIND_TIMEOUT } ).should( 'be.visible' )
				cy.get( '#fcias-rule-path' ).should( 'have.value', '**' )

				// Two of the six selector kinds name no target — they *are*
				// the whole namespace — and the dialog has no target field
				// for them. The rest carry their value beside the kind.
				const kindOf = {
					'home:*': 'homeAll',
					'*': 'universal',
				}

				if ( kindOf[ selector ] !== undefined )
				{
					cy.get( '#fcias-cron-userscope' ).should( 'have.value', kindOf[ selector ] )
					cy.get( '#fcias-cron-scope-target' ).should( 'not.exist' )

					return
				}

				const separator = String( selector ).indexOf( ':' )
				const kind = String( selector ).slice( 0, separator )
				// The remainder verbatim, not split again: a raw storage id
				// may itself contain colons (smb::user@host//share/).
				const target = String( selector ).slice( separator + 1 )

				cy.get( '#fcias-cron-userscope' ).should( 'have.value', kind === 'home'
					? 'user'
					: kind )

				// A storage is a plain text field; the other three kinds are
				// NcSelects, whose input is always empty because it holds
				// what you type rather than what you picked.
				if ( kind === 'storage' )
				{
					cy.get( '#fcias-cron-scope-target' ).should( 'have.value', target )

					return
				}

				// Both readings: the id the rule will be built from, and the
				// label a person sees. The first is the assertion that
				// matters, and it is only askable because the dialog's
				// #selected-option slot puts the id in the page.
				cy.assertNcSelectValue( '#fcias-cron-scope-target', target )

				// And the label, which is the other half of the same
				// question: the id says what the rule will be built from,
				// this says whether a person can tell which folder they
				// picked. Deliberately coupled to how RuleForm composes the
				// label, because that composition *is* what it asserts —
				// which is why it is the id above that carries the weight.
				cy.assertNcSelectDisplayValue(
					'#fcias-cron-scope-target',
					`${ groupFolderNames[ target ] } (#${ target })`,
				)
			} )
	} )

	it( 'refuses a bad rule inside the dialog rather than behind it', () => {
		visitAdmin()
		cy.get( '#fcias-btn-add-rule', { timeout: FIND_TIMEOUT } ).click()
		cy.get( '#fcias-rule-form' ).should( 'be.visible' )

		// A storage selector with no storage named: the server refuses it,
		// and the error belongs on the form still on screen rather than on
		// the page behind it.
		cy.get( '#fcias-cron-userscope' ).select( 'storage' )
		cy.get( '#fcias-cron-scope-target' ).clear()
		cy.get( '#fcias-rule-path' ).clear().type( '**' )
		cy.get( '#fcias-btn-save-rule' ).click()

		cy.get( '#fcias-rule-form .fcias-form-error', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '#fcias-rule-form' ).should( 'be.visible' )
	} )

	it( 'badges a rule whose provider is gone', () => {
		cy.exec(
			`${ occ } fcias:rules:add --selector groupfolder:${ GONE_GROUP_FOLDER }`
			+ ' --path \'**\' --type include -a sha1',
		)

		visitAdmin()
		cy.get( '#fcias-rules-list', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		// Inert by construction: nothing can match a folder that is not
		// there, and silence about that reads as a bug in the app.
		cy.get( '.fcias-provider-missing', { timeout: FIND_TIMEOUT } ).should( 'exist' )
	} )

	it( 'offers Re-apply only where it can succeed', () => {
		visitAdmin()
		cy.get( '#fcias-rules-list tr[data-band="7"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		// Enabled include rule: re-applying queues the same uncapped pass
		// `occ fcias:rules:apply` runs.
		cy.get( '#fcias-rules-list tr[data-band="7"] .action-item__menutoggle' ).first().click()
		cy.get( '.action-item__popper [data-action="apply"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		// A reload rather than dismissing the popover: NcActions leaves the
		// popper it opened in the DOM, so asserting that the *next* menu has
		// no Re-apply would keep finding the previous one's.
		cy.reload()
		cy.get( '#fcias-rules-list tr[data-band="8"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )

		// Disabled one: the server refuses it at submission, so the UI does
		// not offer it.
		cy.get( '#fcias-rules-list tr[data-band="8"] .action-item__menutoggle' ).first().click()
		cy.get( '.action-item__popper [data-action="toggle"]', { timeout: FIND_TIMEOUT } ).should( 'exist' )
		cy.get( '.action-item__popper [data-action="apply"]' ).should( 'not.exist' )
	} )
} )
