/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * MIME types detected from the content, end to end: the sidebar, the
 * duplicates page and the admin section that switches, refreshes and
 * deletes them.
 *
 * Detection is switched on before the spec and off after it, its types
 * deleted, so every other spec runs with it off, as an install does. The
 * files are hashed over the API, which reads them and so detects their
 * type; no rule is enabled, which is the quiet start every spec keeps.
 */

const appId = 'file_checksum_search'

// Default to the CI layout: Cypress runs in the repo root and the
// Nextcloud checkout lives in ./nextcloud. Override via CYPRESS_occ
// for local/ddev runs (e.g. CYPRESS_occ="php /var/www/html/occ").
let occ = 'php nextcloud/occ'
let adminUser = 'admin'
let adminPassword = 'admin'

const FIND_TIMEOUT = 60000

const ADMIN_URL = '/index.php/settings/admin/file_checksum_search#advanced'

// A fixed directory, overwritten by the next run rather than added to.
const dir = 'fcias-e2e-mimetypes'

/** The least of a PDF libmagic names as one. */
const PDF = '%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n'
/** sha1(PDF): the duplicate group the PDFs form. */
const PDF_SHA1 = '31f67d101286a07e1ff72bcd7ed4f051233db7c3'

const files = {
	// PDF content under a picture's name: Nextcloud calls it image/jpeg.
	named: { name: 'report.jpg', content: PDF },
	// The same content under its own name: one group with the first.
	copy: { name: 'copy.pdf', content: PDF },
	// Plain text named as such: both say text/plain.
	text: { name: 'notes.txt', content: 'FCIAS e2e MIME types\n' },
	// Hashed while detection is off, so a refresh has a file to queue.
	untyped: { name: 'untyped.pdf', content: PDF },
}

const fileIds = {}

const webdavUrl = ( path ) => `/remote.php/dav/files/${ adminUser }${ path }`

const propfindBody = [
	'<?xml version="1.0"?>',
	'<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">',
	'<d:prop><oc:fileid/></d:prop>',
	'</d:propfind>',
].join( '' )

/** Write a file into the spec's directory and keep its id. */
const put = ( key ) => {
	const { name, content } = files[ key ]
	cy.request( {
		method: 'PUT',
		url: webdavUrl( `/${ dir }/${ name }` ),
		auth: { user: adminUser, pass: adminPassword },
		body: content,
	} )
	cy.request( {
		method: 'PROPFIND',
		url: webdavUrl( `/${ dir }/${ name }` ),
		auth: { user: adminUser, pass: adminPassword },
		headers: { Depth: '0', 'Content-Type': 'application/xml' },
		body: propfindBody,
	} ).then( ( res ) => {
		const match = String( res.body ).match( /<(?:[a-zA-Z0-9]+:)?fileid>\s*(\d+)\s*</ )
		fileIds[ key ] = match ? Number( match[ 1 ] ) : null
		expect( fileIds[ key ], `${ name } should have an id` ).to.be.a( 'number' )
	} )
}

/** Hash a file over the API, which reads it, and so detects its type while detection is on. */
const hash = ( key ) => cy.then( () => cy.request( {
	method: 'POST',
	url: `/ocs/v2.php/apps/file_checksum_search/api/v1/file/${ fileIds[ key ] }/recalc?algo=sha1`,
	auth: { user: adminUser, pass: adminPassword },
	headers: { 'OCS-APIRequest': 'true' },
} ).its( 'body.success' ).should( 'eq', true ) )

/** The admin status's counts, counted again, read without touching the browser's session. */
const counts = () => cy.request( {
	url: '/ocs/v2.php/apps/file_checksum_search/settings/status/counts?recount=1',
	auth: { user: adminUser, pass: adminPassword },
	headers: { 'OCS-APIRequest': 'true' },
} ).its( 'body' )

/** Run every queued job of one of the app's classes now, as cron would. */
const runJobs = ( className ) => cy.exec(
	`${ occ } background-job:list --class 'OCA\\FileChecksumSearch\\BackgroundJob\\${ className }' --output=json`,
	{ timeout: 300000 },
).then( ( { stdout } ) => {
	const jobs = JSON.parse( stdout )

	expect( jobs, `a ${ className } job is queued` ).to.have.length.at.least( 1 )
	for ( const job of jobs ) {
		cy.exec( `${ occ } background-job:execute ${ job.id } --force-execute`, { timeout: 300000 } )
	}
} )

/** Back to an install's state: off, nothing stored, nothing requested. */
const detectionAsInstalled = () => {
	cy.exec( `${ occ } fcias:mimetype:disable`, { failOnNonZeroExit: false } )
	cy.exec( `${ occ } fcias:mimetype:delete`, { failOnNonZeroExit: false, timeout: 300000 } )
	cy.exec( `${ occ } config:app:delete ${ appId } mimetype_detection`, { failOnNonZeroExit: false } )
}

const openChecksumsTab = ( key ) => {
	cy.visit( `/index.php/apps/files/files/${ fileIds[ key ] }?dir=${ encodeURIComponent( '/' + dir ) }&opendetails=true` )
	cy.get( '.app-sidebar', { timeout: FIND_TIMEOUT } ).should( 'be.visible' )
	cy.get( '#tab-button-file_checksum_search-checksums', { timeout: FIND_TIMEOUT } ).click()
}

// NcCheckboxRadioSwitch renders a checkbox inside a label, and the label
// takes the click.
const detectionSwitch = () => cy.get( '#fcias-mimetypes .checkbox-radio-switch input[type="checkbox"]', { timeout: FIND_TIMEOUT } )

// The dialog's buttons by their caption: NcDialog takes no id for them.
// The administrator's language is the server's default, English, in CI.
const dialogButton = ( caption ) => cy.get( '[role="dialog"]', { timeout: FIND_TIMEOUT } ).contains( 'button', caption )

describe( 'FCIAS MIME types', () => {
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
			detectionAsInstalled()

			cy.request( {
				method: 'MKCOL',
				url: webdavUrl( `/${ dir }` ),
				auth: { user: adminUser, pass: adminPassword },
				failOnStatusCode: false,
			} )

			put( 'untyped' )
			hash( 'untyped' )

			cy.exec( `${ occ } fcias:mimetype:enable` )

			for ( const key of [ 'named', 'copy', 'text' ] ) {
				put( key )
				hash( key )
			}
		} )
	} )

	after( () => {
		detectionAsInstalled()
	} )

	beforeEach( () => {
		cy.login( adminUser, adminPassword )
	} )

	it( 'shows a PDF named .jpg as a PDF, with the type its name gives beside it', () => {
		openChecksumsTab( 'named' )

		cy.get( '[data-testid="fcias-mimetype"] .fcias-mimetype-value', { timeout: FIND_TIMEOUT } )
			.should( 'have.text', 'application/pdf' )
		cy.get( '[data-testid="fcias-mimetype-name"]' ).should( 'contain', 'image/jpeg' )
	} )

	it( 'shows one type for a file whose name and content agree', () => {
		openChecksumsTab( 'text' )

		cy.get( '[data-testid="fcias-mimetype"] .fcias-mimetype-value', { timeout: FIND_TIMEOUT } )
			.should( 'have.text', 'text/plain' )
		cy.get( '[data-testid="fcias-mimetype-name"]' ).should( 'not.exist' )
	} )

	it( 'shows the type once on a duplicate group, for the content its files share', () => {
		cy.visit( `/index.php/apps/file_checksum_search/duplicates#mine?hash=${ PDF_SHA1 }&algo=sha1` )

		const group = () => cy.get( '.db-group', { timeout: FIND_TIMEOUT } )
		group().should( 'have.length', 1 )
		group().find( '.db-hash' ).should( 'contain', PDF_SHA1 )
		group().find( '[data-testid="fcias-group-mimetype"]' ).should( 'have.length', 1 ).and( 'have.text', 'application/pdf' )
	} )

	it( 'refreshes the files without a type from the admin section, and keeps Delete for when it is off', () => {
		cy.visit( ADMIN_URL )

		// Delete waits while detection is on, and says what to do instead.
		cy.get( '#fcias-btn-mimetype-delete', { timeout: FIND_TIMEOUT } ).should( 'be.disabled' )
		cy.get( '#fcias-mimetype-delete-hint' ).should( 'exist' )

		// Refresh asks which files, and queues a job to mark them; run here
		// rather than waiting for cron. Those without a type are the one
		// hashed while detection was off.
		cy.intercept( 'POST', '**/apps/file_checksum_search/settings/mimetypes/refresh' ).as( 'refresh' )
		cy.get( '#fcias-btn-mimetype-refresh' ).click()
		dialogButton( 'Those without one' ).click()
		cy.wait( '@refresh' ).its( 'response.statusCode' ).should( 'eq', 200 )
		runJobs( 'MimeTypeRefresh' )
		counts().its( 'pendingStats' ).should( 'have.property', 'pending:mime' )
		// Not a second cy.visit(): the page is open, and visiting it again
		// changes only its hash, which loads nothing.
		cy.reload()
		cy.get( '#fcias-status-pending', { timeout: FIND_TIMEOUT } ).should( 'contain', 'pending:mime' )

		// The drain takes the queue: no rule is enabled here, so it drops the
		// marks unread, as it does for a file no rule maintains.
		cy.exec( `${ occ } fcias:queue:drain --all`, { timeout: 300000 } )
		counts().its( 'pendingStats' ).should( 'not.have.property', 'pending:mime' )

		counts().its( 'mimeTypes' ).then( ( mimeTypes ) => {
			expect( mimeTypes.state ).to.eq( 'enabled' )
			expect( mimeTypes.rows, 'the three files hashed while on' ).to.be.at.least( 3 )
		} )
		cy.reload()
		// Once the counts are in, not while the cell still spins.
		cy.get( '#fcias-status-pending', { timeout: FIND_TIMEOUT } ).should( 'contain', 'Total:' ).and( 'not.contain', 'pending:mime' )
		cy.get( '#fcias-status-mimetypes' ).should( 'have.attr', 'data-state', 'enabled' )
	} )

	it( 'deletes the types once switched off and confirmed, and stays off until that is done', () => {
		cy.visit( ADMIN_URL )

		detectionSwitch().should( 'be.checked' )
		detectionSwitch().click( { force: true } )
		detectionSwitch().should( 'not.be.checked' )

		cy.intercept( 'POST', '**/apps/file_checksum_search/settings/mimetypes/delete' ).as( 'delete' )
		cy.get( '#fcias-btn-mimetype-delete' ).should( 'not.be.disabled' ).click()
		dialogButton( 'Delete' ).click()
		cy.wait( '@delete' ).its( 'response.statusCode' ).should( 'eq', 200 )

		// Requested and queued; the job has not run. The switch stays off,
		// and the server refuses it too.
		cy.get( '#fcias-status-mimetypes', { timeout: FIND_TIMEOUT } ).should( 'have.attr', 'data-state', 'deleting' )
		detectionSwitch().should( 'be.disabled' )
		cy.get( '#fcias-mimetype-reason' ).should( 'exist' )
		counts().its( 'mimeTypes' ).then( ( mimeTypes ) => {
			expect( mimeTypes.state ).to.eq( 'deleting' )
			expect( mimeTypes.rows, 'the types left to delete' ).to.be.at.least( 3 )
		} )
		cy.exec( `${ occ } fcias:mimetype:enable`, { failOnNonZeroExit: false } ).its( 'exitCode' ).should( 'eq', 1 )

		// The job run, as cron would: off, with nothing left, and the switch
		// free again.
		runJobs( 'MimeTypeDelete' )
		counts().its( 'mimeTypes' ).should( 'deep.equal', { state: 'disabled', rows: 0, deleteRequestedAt: null } )
		cy.reload()
		cy.get( '#fcias-status-mimetypes', { timeout: FIND_TIMEOUT } ).should( 'have.attr', 'data-state', 'disabled' )
		detectionSwitch().should( 'not.be.disabled' )
	} )
} )
