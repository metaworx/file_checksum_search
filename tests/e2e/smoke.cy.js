describe( 'FCIAS Smoke Test', () => {
	beforeEach( () => {
		cy.login()
		cy.visit( '/index.php/apps/files' )
	} )

	it( 'loads the Files dashboard after login', () => {
		cy.contains( 'Files' ).should( 'be.visible' )
	} )
} )
