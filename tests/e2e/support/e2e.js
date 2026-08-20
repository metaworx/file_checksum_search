Cypress.on( 'uncaught:exception', ( err ) => {
	// Nextcloud core apps (Photos, Recommendations, User Status, …) throw
	// benign unhandled rejections while the dashboard loads on a fresh
	// install (missing upload folder, 404s on their OCS endpoints). The
	// specs assert on the DOM, so ignore these instead of failing.
	console.error( 'Ignoring uncaught exception:', err.message )
	return false
} )

Cypress.Commands.add( 'login', ( user = 'admin', password = 'admin' ) => {
	cy.session( [ user, password ], () => {
		cy.visit( '/login' )
		cy.get( 'input[name="user"]' ).type( user )
		cy.get( 'input[name="password"]' ).type( `${ password }{enter}` )
		// Wait until we leave the login page
		cy.url().should( 'not.include', '/login' )
	} )
} )
