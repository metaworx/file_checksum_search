Cypress.on( 'uncaught:exception', ( err ) => {
	// ResizeObserver loop errors are harmless browser warnings
	if ( err.message.includes( 'ResizeObserver' ) ) {
		return false
	}
	return true
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
