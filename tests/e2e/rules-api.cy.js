/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Cypress E2E tests for the rules API's permission matrix.
 *
 * The first cross-user coverage this app has anywhere. Everything else
 * runs as admin, and the unit tests assert 403s and 404s against mocked
 * services — which cannot tell you whether the real middleware, the real
 * session and the real permission model agree with them.
 *
 * No browser: every test is one `cy.ocs()` call and its status code.
 * `failOnStatusCode` is false throughout, so a wrong code is an
 * assertion failure naming both numbers rather than an abort.
 *
 * Every expected code below was measured against a live instance before
 * it was written down.
 */

const appId = 'file_checksum_search'

// Default to the CI layout: Cypress runs in the repo root and the
// Nextcloud checkout lives in ./nextcloud. Override via CYPRESS_occ
// for local/ddev runs (e.g. CYPRESS_occ="php /var/www/html/occ").
let occ = 'php nextcloud/occ'
let adminUser = 'admin'
let adminPassword = 'admin'

const alice = { user: 'alice', password: 'SecretPass123!' }
const bob = { user: 'bob', password: 'SecretPass123!' }

// Created by this spec rather than assumed from the instance skeleton.
const aliceFolders = [ 'Documents', 'Photos' ]

// Ids resolved in before(), so no test depends on another's ordering.
let homeAllRuleId = null
let universalRuleId = null
let aliceRuleId = null

const asAdmin = () => ( { user: adminUser, password: adminPassword } )

describe( 'FCIAS rules API', () => {
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
			cy.fciasEnsureUsers( occ )
			cy.fciasResetRules( occ )

			// The folders the rules below name. A rule whose path cannot
			// reach anything in the caller's own tree is refused, and
			// whether a freshly created user has a Documents folder is a
			// question about the instance's skeleton, not about this app.
			for ( const folder of aliceFolders ) {
				cy.request( {
					method: 'MKCOL',
					url: `/remote.php/dav/files/${ alice.user }/${ folder }`,
					auth: { user: alice.user, pass: alice.password },
					failOnStatusCode: false,
				} )
			}

			// The two shipped defaults, by selector rather than by position.
			cy.ocs( { url: '/api/v1/rules?scope=all', ...asAdmin() } )
				.then( ( { body } ) => {
					homeAllRuleId = body.rules.find( ( r ) => r.selector === 'home:*' ).id
					universalRuleId = body.rules.find( ( r ) => r.selector === '*' ).id
				} )

			// One rule alice owns, for the rows about somebody else's rule.
			// `**` rather than a named path: a rule whose path cannot reach
			// the user's own tree is refused, which the matrix asserts below.
			cy.ocs( {
				method: 'POST',
				url: '/api/v1/rules',
				body: {
					selector: `home:${ alice.user }`,
					path: '**',
					type: 'include',
					algos: [ 'sha1' ],
					mode: 'auto',
				},
				...alice,
			} ).then( ( { status, body } ) => {
				expect( status, 'alice may write a rule for her own files' ).to.eq( 200 )
				aliceRuleId = body.id ?? body.rule?.id
			} )

			cy.ocs( { url: '/api/v1/rules', ...alice } ).then( ( { body } ) => {
				aliceRuleId = body.rules.find( ( r ) => r.selector === 'home:alice' ).id
			} )
		} )
	} )

	after( () => {
		cy.fciasResetRules( occ )
	} )

	describe( 'reading', () => {
		it( 'refuses an anonymous caller', () => {
			// Explicitly cookieless: cy.request() shares the browser's jar,
			// and a session left by any earlier call would make this request
			// somebody, which is the one thing it must not be.
			cy.clearCookies()
			cy.request( {
				url: '/ocs/v2.php/apps/file_checksum_search/api/v1/rules',
				headers: { 'OCS-APIRequest': 'true' },
				failOnStatusCode: false,
			} ).its( 'status' ).should( 'eq', 401 )
		} )

		it( 'lets a user list the rules that concern them', () => {
			cy.ocs( { url: '/api/v1/rules', ...alice } ).then( ( { status, body } ) => {
				expect( status ).to.eq( 200 )
				expect( body.rules ).to.be.an( 'array' )

				// Her own and the two that cover every home, and nothing of
				// bob's: another user's rules cannot affect her, so listing
				// them would only show her gaps she cannot act on.
				expect( body.rules.every(
					( r ) => [ 'home:alice', 'home:*', '*' ].includes( r.selector ),
				) ).to.eq( true )
			} )
		} )

		it( 'reserves the everyone view for administrators', () => {
			cy.ocs( { url: '/api/v1/rules?scope=all', ...alice } )
				.its( 'status' ).should( 'eq', 403 )
			cy.ocs( { url: '/api/v1/rules?scope=all', ...asAdmin() } )
				.its( 'status' ).should( 'eq', 200 )
		} )

		it( 'refuses a scope it does not have', () => {
			cy.ocs( { url: '/api/v1/rules?scope=everything', ...asAdmin() } )
				.its( 'status' ).should( 'eq', 400 )
		} )
	} )

	describe( 'creating', () => {
		it( 'refuses a path that cannot reach the caller\'s own files', () => {
			// Scope answers "does this rule cover me"; this answers "could it
			// ever touch a file I can see". A rule on a folder that is not in
			// her tree is one that can never match anything.
			cy.ocs( {
				method: 'POST',
				url: '/api/v1/rules',
				body: {
					selector: `home:${ alice.user }`,
					path: '/NoSuchFolder/**',
					type: 'include',
					algos: [ 'sha1' ],
					mode: 'auto',
				},
				...alice,
			} ).its( 'status' ).should( 'eq', 403 )
		} )

		// A non-administrator's selector is not validated and refused — it is
		// *replaced* with their own home, whatever they asked for. So the
		// assertion that matters is not a status code but what got stored,
		// and these two would both pass on a 200 alone while a rule over
		// somebody else's files sat in the database.
		const neutralised = ( asked, path ) => {
			cy.ocs( {
				method: 'POST',
				url: '/api/v1/rules',
				body: {
					selector: asked,
					path,
					type: 'include',
					algos: [ 'sha1' ],
					mode: 'auto',
				},
				...alice,
			} ).its( 'status' ).should( 'eq', 200 )

			cy.ocs( { url: '/api/v1/rules?scope=all', ...asAdmin() } ).then( ( { body } ) => {
				const written = body.rules.find( ( r ) => r.path === path )

				expect( written, `the rule alice asked for on ${ path }` ).to.exist
				expect( written.selector, `asked for ${ asked }, stored as her own home` )
					.to.eq( `home:${ alice.user }` )
			} )
		}

		it( 'will not let a user aim a rule at somebody else', () => {
			neutralised( `home:${ bob.user }`, '/Documents/**' )
		} )

		it( 'will not let a user widen a rule to every storage', () => {
			neutralised( '*', '/Photos/**' )
		} )
	} )

	describe( 'mutating somebody else\'s rule', () => {
		it( 'refuses an update', () => {
			cy.ocs( {
				method: 'PUT',
				url: `/api/v1/rules/${ aliceRuleId }`,
				body: { enabled: false },
				...bob,
			} ).its( 'status' ).should( 'eq', 403 )
		} )

		it( 'refuses a delete', () => {
			cy.ocs( {
				method: 'DELETE',
				url: `/api/v1/rules/${ aliceRuleId }`,
				...bob,
			} ).its( 'status' ).should( 'eq', 403 )
		} )

		it( 'refuses a re-apply of an administrator\'s rule', () => {
			cy.ocs( {
				method: 'POST',
				url: `/api/v1/rules/${ homeAllRuleId }/apply`,
				...alice,
			} ).its( 'status' ).should( 'eq', 403 )
		} )

		it( 'allows the owner what it refused the stranger', () => {
			cy.ocs( {
				method: 'PUT',
				url: `/api/v1/rules/${ aliceRuleId }`,
				body: { enabled: false },
				...alice,
			} ).its( 'status' ).should( 'eq', 200 )
		} )
	} )

	describe( 'rules that are not there', () => {
		it( 'reports 404 rather than 403 for an update', () => {
			cy.ocs( {
				method: 'PUT',
				url: '/api/v1/rules/nosuchid',
				body: { enabled: true },
				...asAdmin(),
			} ).its( 'status' ).should( 'eq', 404 )
		} )

		it( 'reports 404 for a delete', () => {
			cy.ocs( {
				method: 'DELETE',
				url: '/api/v1/rules/nosuchid',
				...asAdmin(),
			} ).its( 'status' ).should( 'eq', 404 )
		} )

		it( 'reports 404 for a re-apply', () => {
			cy.ocs( {
				method: 'POST',
				url: '/api/v1/rules/nosuchid/apply',
				...asAdmin(),
			} ).its( 'status' ).should( 'eq', 404 )
		} )
	} )

	describe( 'reordering and re-applying', () => {
		it( 'refuses a reorder that names no segment', () => {
			cy.ocs( {
				method: 'PUT',
				url: '/api/v1/rules/order',
				body: {},
				...asAdmin(),
			} ).its( 'status' ).should( 'eq', 400 )
		} )

		it( 'refuses a reorder with no ids', () => {
			cy.ocs( {
				method: 'PUT',
				url: '/api/v1/rules/order',
				body: { selector: 'home:*' },
				...asAdmin(),
			} ).its( 'status' ).should( 'eq', 400 )
		} )

		it( 'reorders a segment it is given in full', () => {
			cy.ocs( {
				method: 'PUT',
				url: '/api/v1/rules/order',
				body: {
					selector: 'home:*',
					defaults: true,
					orderedIds: [ homeAllRuleId ],
				},
				...asAdmin(),
			} ).its( 'status' ).should( 'eq', 200 )
		} )

		it( 'refuses to re-apply a disabled rule', () => {
			// Re-applying queues an uncapped pass over everything the rule
			// governs. A disabled rule governs nothing, so there is nothing
			// to queue and saying so beats queueing a no-op.
			cy.ocs( {
				method: 'POST',
				url: `/api/v1/rules/${ universalRuleId }/apply`,
				...asAdmin(),
			} ).its( 'status' ).should( 'eq', 400 )
		} )
	} )
} )
