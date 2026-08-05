describe('FCIAS Smoke Test', () => {
  beforeEach(() => {
    cy.visit('/index.php/login');

    cy.get('#user').type(Cypress.env('NC_ADMIN_USER') || 'admin');
    cy.get('#password').type(Cypress.env('NC_ADMIN_PASSWORD') || 'admin');
    cy.get('#submit-form').click();

    cy.url().should('include', '/apps/files');
  });

  it('loads the Files dashboard after login', () => {
    cy.contains('Files').should('be.visible');
  });
});
