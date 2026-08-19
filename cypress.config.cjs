const { defineConfig } = require( 'cypress' );

module.exports = defineConfig( {
	allowCypressEnv: false,

	e2e: {
		// Prefer the CI-provided URL, fall back to DDEV for local development
		baseUrl: process.env.CYPRESS_baseUrl || process.env.DDEV_PRIMARY_URL || 'https://helioscloud.ddev.site',

		specPattern: 'tests/e2e/**/*.cy.js',
		supportFile: 'tests/e2e/support/e2e.js',

		video: false,
		defaultCommandTimeout: 15000,

		setupNodeEvents( on, config ) {
			on( 'before:browser:launch', ( browser = {}, launchOptions ) => {
				if ( browser.name === 'chrome' && browser.isHeadless ) {
					launchOptions.args.push( '--no-sandbox' );
					launchOptions.args.push( '--disable-dev-shm-usage' );
					// Force English UI so assertions on labels ("Enable"/"Disable")
					// are independent of the host/browser locale.
					launchOptions.args.push( '--lang=en-US' );
				}
				return launchOptions;
			} );
		},
	},
} );
