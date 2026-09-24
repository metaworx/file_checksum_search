const { defineConfig } = require( 'cypress' );
const fs = require( 'fs' );
const path = require( 'path' );

// Where the screenshot set lives. tests/e2e/screenshots.cy.js names each
// capture after the file it replaces, and the hook below moves it there.
const SCREENSHOT_SET = path.join( __dirname, 'docs', 'Screenshots' );

module.exports = defineConfig( {
	allowCypressEnv: false,

	e2e: {
		// Prefer the CI-provided URL, fall back to DDEV for local development
		baseUrl: process.env.CYPRESS_baseUrl || process.env.DDEV_PRIMARY_URL || 'https://nextcloud-34.ddev.site',

		specPattern: 'tests/e2e/**/*.cy.js',
		supportFile: 'tests/e2e/support/e2e.js',

		video: false,
		defaultCommandTimeout: 15000,

		setupNodeEvents( on, config ) {
			// cy.log() never reaches stdout in `cypress run`, so the failure
			// diagnostic in support/e2e.js hands its payload to Node instead.
			// This is what makes the dump readable in CI.
			on( 'task', {
				fciasDiag( payload ) {
					console.log( '\n=== FCIAS failure diagnostic ===' );
					console.log( JSON.stringify( payload, null, 2 ) );
					console.log( '=== end FCIAS failure diagnostic ===\n' );
					return null;
				},
			} );

			// A capture from the screenshot spec lands in docs/Screenshots/
			// under its own name; a failure shot, from any spec, stays where
			// Cypress put it.
			on( 'after:screenshot', ( details ) => {
				const fromTheSet = details.specName === 'screenshots.cy.js' && ! details.testFailure;
				if ( ! fromTheSet ) {
					return details;
				}
				const target = path.join( SCREENSHOT_SET, `${ details.name }.png` );
				fs.mkdirSync( SCREENSHOT_SET, { recursive: true } );
				fs.renameSync( details.path, target );
				return { path: target };
			} );

			on( 'before:browser:launch', ( browser = {}, launchOptions ) => {
				if ( browser.name === 'chrome' && browser.isHeadless ) {
					launchOptions.args.push( '--no-sandbox' );
					launchOptions.args.push( '--disable-dev-shm-usage' );
					// Force English UI so assertions on labels ("Enable"/"Disable")
					// are independent of the host/browser locale.
					launchOptions.args.push( '--lang=en-US' );
				}
				// A window the screenshot set's 1600x900 viewport fits in: a
				// viewport wider than the headless window is scaled down to
				// fit, and every capture with it. Harmless to the tests.
				if ( browser.name === 'electron' ) {
					launchOptions.preferences.width = 1600;
					launchOptions.preferences.height = 1000;
				} else {
					launchOptions.args.push( '--window-size=1600,1000' );
				}
				return launchOptions;
			} );
		},
	},
} );
