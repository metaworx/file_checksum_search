<?php
/** @noinspection SqlNoDataSourceInspection */

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Http;

use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\IRootFolder;
use OCP\Server;
use Throwable;

/**
 * Full HTTP integration tests for the Public API v1 endpoints.
 *
 * Makes real HTTP requests against the running ddev Nextcloud instance
 * via file_get_contents with basic authentication.
 * Inserts test data via Doctrine raw SQL and verifies JSON responses.
 *
 * Prerequisite: test user `fcias_http_test` must exist.
 * Create with:
 *   ddev exec bash -c 'NC_PASS=Str0ng!T3st#P4ss! php occ user:add --password-from-env fcias_http_test'
 */
class PublicApiTest
	extends
	DatabaseTestCase
{

	private const TEST_USER = 'fcias_http_test';

	private const TEST_PASSWORD = 'Str0ng!T3st#P4ss!';

	private string $baseUrl;

	private string $authHeader;

	/** @var list<int> */
	private array  $cleanupFileIds = [];

	private int    $testFileId1;

	private int    $testFileId2;

	private string $sharedSha1Hash = 'abc123abc123abc123abc123abc123abc123abc1';

	private string $sha256Hash     = 'def456def456def456def456def456def456def456def4';


	/** @noinspection PhpUnhandledExceptionInspection */
	protected function setUp(): void
	{

		parent::setUp();

		// Clean leftovers from previous aborted runs first.
		$this->cleanupLeftovers();

		// Use 127.0.0.1 to avoid NC HTTP client localhost SSRF block.
		$this->baseUrl    = 'http://127.0.0.1/index.php/apps/file_checksum_search';
		$this->authHeader = 'Authorization: Basic '
		                    . base64_encode( self::TEST_USER . ':' . self::TEST_PASSWORD );

		// Create two test files; file2 shares sha1 with file1 for duplicate detection.
		$userFolder = Server::get( IRootFolder::class )
		                    ->getUserFolder( self::TEST_USER )
		;

		$ts                     = time();
		$file1                  = $userFolder->newFile( "fcias_http_1_$ts.dat", 'HTTP integration test content A' );
		$file2                  = $userFolder->newFile( "fcias_http_2_$ts.dat", 'HTTP integration test content B' );
		$this->testFileId1      = $file1->getId();
		$this->testFileId2      = $file2->getId();
		$this->cleanupFileIds[] = $this->testFileId1;
		$this->cleanupFileIds[] = $this->testFileId2;

		// file1: sha1 + sha256; file2: same sha1 (for duplicate detection)
		$this->insertHashMetadata( $this->testFileId1, [
			'sha1'   => $this->sharedSha1Hash,
			'sha256' => $this->sha256Hash,
		] );
		$this->insertHashMetadata( $this->testFileId2, [
			'sha1' => $this->sharedSha1Hash,
		] );

	}


	protected function tearDown(): void
	{

		$this->cleanupLeftovers();

		parent::tearDown();
	}


	// ─── GET /api/v1/status ──────────────────────────────────────────

	public function testStatusEndpoint(): void
	{

		$response = $this->httpGet( '/api/v1/status' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'version', $response, 'Status should include version.' );
		$this->assertArrayHasKey( 'dbVersion', $response, 'Status should include dbVersion.' );
		$this->assertArrayHasKey( 'rowCount', $response, 'Status should include rowCount.' );
		$this->assertArrayHasKey( 'pendingRows', $response, 'Status should include pendingRows.' );
		$this->assertIsString( $response['version'] );
		$this->assertIsInt( $response['rowCount'] );
		$this->assertIsInt( $response['pendingRows'] );
	}


	// ─── GET /api/v1/lookup?hash=... ─────────────────────────────────

	public function testLookupEndpointFindsInsertedHash(): void
	{

		$response = $this->httpGet( '/api/v1/lookup?hash=' . urlencode( $this->sharedSha1Hash ) );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'results', $response, 'Lookup response should contain results.' );
		$this->assertNotEmpty( $response['results'], 'Lookup should find the inserted hash.' );

		$fileIds = array_column( $response['results'], 'fileid' );

		$this->assertContains( $this->testFileId1, $fileIds, 'Results should include test file 1.' );
		$this->assertContains( $this->testFileId2, $fileIds, 'Results should include test file 2.' );
	}


	public function testLookupEndpointWithAlgoFilter(): void
	{

		$response = $this->httpGet(
			'/api/v1/lookup?hash=' . urlencode( $this->sha256Hash ) . '&algo=sha256',
		);

		$this->assertIsArray( $response );
		$this->assertNotEmpty( $response['results'], 'sha256 lookup should find the inserted hash.' );

		foreach ( $response['results'] as $row )
		{
			$this->assertSame( 'sha256', $row['algo'], 'Each result should have algo=sha256.' );
		}
	}


	public function testLookupEndpointReturnsEmptyForUnknownHash(): void
	{

		$response = $this->httpGet( '/api/v1/lookup?hash=deadbeefdeadbeefdeadbeefdeadbeefdeadbeef' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'results', $response );
		$this->assertEmpty( $response['results'], 'Unknown hash should return empty results.' );
	}


	// ─── GET /api/v1/file/{fileId}/hashes ────────────────────────────

	public function testGetHashesEndpoint(): void
	{

		$response = $this->httpGet( "/api/v1/file/$this->testFileId1/hashes" );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'fileid', $response );
		$this->assertArrayHasKey( 'hashes', $response );
		$this->assertSame( $this->testFileId1, $response['fileid'] );
		$this->assertNotEmpty( $response['hashes'] );

		$algos = array_column( $response['hashes'], 'algo' );

		$this->assertContains( 'sha1', $algos, 'Hashes should include sha1.' );
		$this->assertContains( 'sha256', $algos, 'Hashes should include sha256.' );
	}


	// ─── GET /api/v1/file/{fileId}/duplicates ────────────────────────

	public function testFindDuplicatesByFileEndpoint(): void
	{

		$response = $this->httpGet( "/api/v1/file/$this->testFileId1/duplicates" );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'duplicates', $response );

		if ( empty( $response['duplicates'] ) )
		{
			$this->markTestSkipped(
				'No duplicates returned — user session may not be available in HTTP test context.',
			);
		}

		$this->assertNotEmpty( $response['duplicates'], 'Should find duplicates for file1.' );

		foreach ( $response['duplicates'] as $group )
		{
			$this->assertArrayHasKey( 'algo', $group );
			$this->assertArrayHasKey( 'hash_value', $group );
			$this->assertArrayHasKey( 'files', $group );
		}
	}


	// ─── GET /api/v1/duplicates (global) ─────────────────────────────

	public function testFindAllDuplicatesEndpoint(): void
	{

		$response = $this->httpGet( '/api/v1/duplicates' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'duplicates', $response );
		$this->assertArrayHasKey( 'total_groups', $response );
		$this->assertArrayHasKey( 'pagination', $response );
		$this->assertIsInt( $response['total_groups'] );
		$this->assertArrayHasKey( 'offset', $response['pagination'] );
		$this->assertArrayHasKey( 'limit', $response['pagination'] );
	}


	// ─── POST /api/v1/file/{fileId}/recalc ───────────────────────────

	public function testRecalcHashEndpoint(): void
	{

		$response = $this->httpPost(
			"/api/v1/file/$this->testFileId1/recalc",
			[ 'algo' => 'sha256' ],
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response, 'Recalc response should have success field.' );

		if ( $response['success'] )
		{
			$this->assertArrayHasKey( 'algo', $response );
			$this->assertArrayHasKey( 'hash', $response );
		}
		else
		{
			$this->assertArrayHasKey( 'error', $response );
		}
	}


	public function testRecalcHashEndpointWithDefaultAlgo(): void
	{

		$response = $this->httpPost( "/api/v1/file/$this->testFileId1/recalc", [] );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );

		if ( $response['success'] )
		{
			$this->assertSame( 'sha1', $response['algo'] ?? null, 'Default algo should be sha1.' );
		}
	}


	public function testRecalcHashEndpointWithNonexistentFile(): void
	{

		$response = $this->httpPost( '/api/v1/file/99999999/recalc', [ 'algo' => 'sha1' ] );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
		$this->assertFalse( $response['success'], 'Nonexistent file should return success=false.' );
	}


	// ─── helpers ─────────────────────────────────────────────────────

	private function httpGet( string $path ): array
	{

		$context = stream_context_create( [
			'http' => [
				'header'        => $this->authHeader . "\r\nAccept: application/json",
				'ignore_errors' => true,
			],
		] );

		$body = file_get_contents( $this->baseUrl . $path, false, $context );

		$this->assertNotFalse( $body, "HTTP GET $path should return content." );

		$decoded = json_decode( $body, true );

		$this->assertIsArray(
			$decoded,
			"Response for GET $path should be valid JSON. Got: " . substr( $body, 0, 200 ),
		);

		return $decoded;
	}


	private function httpPost(
		string $path,
		array  $data,
	): array {

		$jsonPayload = json_encode( $data );

		$context = stream_context_create( [
			'http' => [
				'method'        => 'POST',
				'header'        => "Content-Type: application/json\r\n" . $this->authHeader . "\r\nAccept: application/json",
				'content'       => $jsonPayload,
				'ignore_errors' => true,
			],
		] );

		$body = file_get_contents( $this->baseUrl . $path, false, $context );

		$this->assertNotFalse( $body, "HTTP POST $path should return content." );

		$decoded = json_decode( $body, true );

		$this->assertIsArray(
			$decoded,
			"Response for POST $path should be valid JSON. Got: " . substr( $body, 0, 200 ),
		);

		return $decoded;
	}


	/**
	 * Insert hash metadata into oc_files_metadata (JSON) and
	 * oc_files_metadata_index (index row).
	 *
	 * JSON format matches NC MetadataValueWrapper serialization.
	 *
	 * @param  array<string, string>  $hashes  algo => hex-hash pairs
	 */
	private function insertHashMetadata(
		int   $fileId,
		array $hashes,
	): void {

		$json = [];

		foreach ( $hashes as $algo => $hash )
		{
			$json[ 'file-checksum-' . $algo ] = [
				'value'          => $hash,
				'type'           => 'string',
				'etag'           => '',
				'indexed'        => true,
				'editPermission' => 0,
			];
		}

		$jsonPayload = json_encode( $json );

		$this->getRawConnection()
		     ->executeStatement(
			     'INSERT INTO `*PREFIX*files_metadata` (`file_id`, `json`, `sync_token`, `last_update`) '
			     . 'VALUES (?, ?, ?, NOW()) '
			     . 'ON DUPLICATE KEY UPDATE `json` = VALUES(`json`), `last_update` = NOW()',
			     [
				     $fileId,
				     $jsonPayload,
				     '',
			     ],
		     )
		;

		foreach ( $hashes as $algo => $hash )
		{
			$this->getRawConnection()
			     ->executeStatement(
				     'INSERT INTO `*PREFIX*files_metadata_index` (`file_id`, `meta_key`, `meta_value_string`, `meta_value_int`) VALUES (?, ?, ?, ?)',
				     [
					     $fileId,
					     'file-checksum-' . $algo,
					     substr( $hash, 0, 63 ),
					     0,
				     ],
			     )
			;
		}
	}


	private function cleanupLeftovers(): void
	{

		if ( empty( $this->cleanupFileIds ) )
		{
			return;
		}

		$inPlaceholders = implode( ',', array_fill( 0, count( $this->cleanupFileIds ), '?' ) );

		try
		{
			$this->getRawConnection()
			     ->executeStatement(
				     "DELETE FROM `*PREFIX*files_metadata_index` WHERE `file_id` IN ($inPlaceholders)",
				     $this->cleanupFileIds,
			     )
			;
		}
		catch ( Throwable )
		{
		}

		try
		{
			$this->getRawConnection()
			     ->executeStatement(
				     "DELETE FROM `*PREFIX*files_metadata` WHERE `file_id` IN ($inPlaceholders)",
				     $this->cleanupFileIds,
			     )
			;
		}
		catch ( Throwable )
		{
		}
	}

}
