<?php
/** @noinspection SqlNoDataSourceInspection */

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Search;

use DateTime;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\FileChecksumSearch\Search\HashSearchProvider;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Search\ISearchQuery;
use OCP\Server;
use Throwable;

/**
 * Integration tests for HashSearchProvider against a real database.
 *
 * Verifies that search returns results for known hashes stored in
 * oc_files_metadata / oc_files_metadata_index, returns empty for
 * unknown hashes, and respects user access boundaries.
 */
class HashSearchProviderTest
	extends
	DatabaseTestCase
{

	private HashSearchProvider $provider;

	private IUser              $adminUser;

	private string             $fcTable;

	/** @var File[] */
	private array $cleanupFiles = [];

	/** @var list<int> */
	private array $cleanupFileIds = [];

	/** File ID for the inaccessible‑file test (set in setUp). */
	private int $inaccessibleFileId;


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function setUp(): void
	{

		parent::setUp();

		$this->provider  = Server::get( HashSearchProvider::class );
		$this->adminUser = Server::get( IUserManager::class )
		                         ->get( 'admin' )
		;
		$this->fcTable   = $this->getFilecacheTableName();

		// Use a high ID with time‑based suffix to avoid collisions.
		$this->inaccessibleFileId = 99999000 + ( time() % 1000 );

		// Clean any leftovers from a previous aborted run.
		$this->cleanupLeftovers();
	}


	protected function tearDown(): void
	{

		$this->cleanupLeftovers();

		foreach ( $this->cleanupFiles as $file )
		{
			try
			{
				$file->delete();
			}
			catch ( Throwable )
			{
			}
		}

		parent::tearDown();
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testSearchReturnsResultsForKnownHash(): void
	{

		$userFolder           = Server::get( IRootFolder::class )
		                              ->getUserFolder( 'admin' )
		;
		$file                 = $userFolder->newFile( 'fcias_search_test_' . time() . '.dat', 'test content' );
		$this->cleanupFiles[] = $file;

		$fileId                 = $file->getId();
		$this->cleanupFileIds[] = $fileId;

		$testHash = 'abc123def456abc123def456abc123def4567890';

		$this->insertHashMetadata( $fileId, [ 'sha1' => $testHash ] );

		$query = $this->createSearchQuery( $testHash );

		$result = $this->provider->search( $this->adminUser, $query );

		$data = $result->jsonSerialize();

		$this->assertNotEmpty(
			$data['entries'],
			'Search should return entries for a known hash with an accessible file.',
		);
		$entryData = $data['entries'][0]->jsonSerialize();

		$this->assertStringContainsString(
			$testHash,
			$entryData['subline'],
			'Result subline should contain the searched hash.',
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testSearchReturnsResultsForAlgoColonHashFormat(): void
	{

		$userFolder           = Server::get( IRootFolder::class )
		                              ->getUserFolder( 'admin' )
		;
		$file                 = $userFolder->newFile( 'fcias_search_algo_' . time() . '.dat', 'algo test' );
		$this->cleanupFiles[] = $file;

		$fileId                 = $file->getId();
		$this->cleanupFileIds[] = $fileId;

		$testHash = 'def789abc012def789abc012def789abc012def789a';

		$this->insertHashMetadata( $fileId, [ 'sha256' => $testHash ] );

		// Search using "sha256:hash" format
		$query = $this->createSearchQuery( 'sha256:' . $testHash );

		$result = $this->provider->search( $this->adminUser, $query );

		$data = $result->jsonSerialize();

		$this->assertNotEmpty(
			$data['entries'],
			'Search should return entries for algo:hash format.',
		);
	}


	public function testSearchReturnsEmptyForUnknownHash(): void
	{

		$query = $this->createSearchQuery( 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef' );

		$result = $this->provider->search( $this->adminUser, $query );

		$data = $result->jsonSerialize();

		$this->assertEmpty(
			$data['entries'],
			'Search should return empty for a hash not in the database.',
		);
	}


	public function testSearchReturnsEmptyForEmptyTerm(): void
	{

		$query = $this->createSearchQuery( '' );

		$result = $this->provider->search( $this->adminUser, $query );

		$data = $result->jsonSerialize();

		$this->assertEmpty(
			$data['entries'],
			'Search should return empty for an empty search term.',
		);
	}


	public function testSearchReturnsEmptyForNonHexTerm(): void
	{

		$query = $this->createSearchQuery( 'not-a-valid-hex-hash-value!' );

		$result = $this->provider->search( $this->adminUser, $query );

		$data = $result->jsonSerialize();

		$this->assertEmpty(
			$data['entries'],
			'Search should return empty for non-hex input.',
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testSearchExcludesInaccessibleFiles(): void
	{

		$fileId                 = $this->inaccessibleFileId;
		$this->cleanupFileIds[] = $fileId;

		// Insert a filecache row with a non‑existent storage ID.
		// getById() won't resolve this, so the search must filter it out.
		// `UNIX_TIMESTAMP()` is MySQL's; PHP's own time() is every
		// backend's, and the value is the same thing.
		$now    = time();
		$insert = $this->db->getQueryBuilder();
		$insert->insert( 'filecache' )
		       ->values( [
			       'fileid'           => $insert->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ),
			       'storage'          => $insert->createNamedParameter( 99999, IQueryBuilder::PARAM_INT ),
			       'path'             => $insert->createNamedParameter(
				       'files/inaccessible_' . $fileId . '.dat',
			       ),
			       'path_hash'        => $insert->createNamedParameter( md5( 'inaccessible_' . $fileId ) ),
			       'parent'           => $insert->createNamedParameter( -1, IQueryBuilder::PARAM_INT ),
			       'name'             => $insert->createNamedParameter( 'inaccessible.dat' ),
			       'mimetype'         => $insert->createNamedParameter( 0, IQueryBuilder::PARAM_INT ),
			       'mimepart'         => $insert->createNamedParameter( 0, IQueryBuilder::PARAM_INT ),
			       'size'             => $insert->createNamedParameter( 0, IQueryBuilder::PARAM_INT ),
			       'mtime'            => $insert->createNamedParameter( $now, IQueryBuilder::PARAM_INT ),
			       'storage_mtime'    => $insert->createNamedParameter( $now, IQueryBuilder::PARAM_INT ),
			       'encrypted'        => $insert->createNamedParameter( 0, IQueryBuilder::PARAM_INT ),
			       'unencrypted_size' => $insert->createNamedParameter( 0, IQueryBuilder::PARAM_INT ),
			       'etag'             => $insert->createNamedParameter( md5( 'etag_inaccessible_' . $fileId ) ),
			       'checksum'         => $insert->createNamedParameter( '' ),
		       ] )
		;
		$insert->executeStatement();

		$testHash = 'cafebabecafebabecafebabecafebabecafebabe';

		$this->insertHashMetadata( $fileId, [ 'sha256' => $testHash ] );

		$query = $this->createSearchQuery( $testHash );

		$result = $this->provider->search( $this->adminUser, $query );

		$data = $result->jsonSerialize();

		$this->assertEmpty(
			$data['entries'],
			'Inaccessible files (non‑existent storage) should be excluded from search results.',
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testSearchFiltersByAlgoInColonFormat(): void
	{

		$userFolder           = Server::get( IRootFolder::class )
		                              ->getUserFolder( 'admin' )
		;
		$file                 = $userFolder->newFile( 'fcias_algo_filter_' . time() . '.dat', 'algo filter test' );
		$this->cleanupFiles[] = $file;

		$fileId                 = $file->getId();
		$this->cleanupFileIds[] = $fileId;

		$sha1Hash   = '1111111111111111111111111111111111111111';
		$sha256Hash = '2222222222222222222222222222222222222222';

		// Insert two index rows for the same file — different algos
		$this->insertHashMetadata( $fileId, [
			'sha1'   => $sha1Hash,
			'sha256' => $sha256Hash,
		] );

		// Search with sha256: prefix — only the sha256 row should match
		$query = $this->createSearchQuery( 'sha256:' . $sha256Hash );

		$result = $this->provider->search( $this->adminUser, $query );

		$data = $result->jsonSerialize();

		$this->assertNotEmpty( $data['entries'], 'sha256:hash search should return results.' );

		$subline = $data['entries'][0]->jsonSerialize()['subline'];

		$this->assertStringContainsString( 'sha256', $subline, 'Result should reference sha256 algo.' );
		$this->assertStringNotContainsString( 'sha1', $subline, 'Result should not reference sha1 algo.' );
	}


	// ─── helpers ─────────────────────────────────────────────────────

	/**
	 * @noinspection PhpSameParameterValueInspection
	 */
	private function createSearchQuery(
		string $term,
		int    $limit = 100,
	): ISearchQuery {

		$query = $this->createMock( ISearchQuery::class );

		$query->method( 'getTerm' )
		      ->willReturn( $term )
		;

		$query->method( 'getLimit' )
		      ->willReturn( $limit )
		;

		return $query;
	}


	/**
	 * Insert hash metadata into oc_files_metadata (JSON) and
	 * oc_files_metadata_index (index row) for use by queryByHash().
	 *
	 * JSON format matches NC MetadataValueWrapper serialization:
	 * {"key": {"value": ..., "type": "string", "etag": "", "indexed": true, "editPermission": 0}}
	 *
	 * Index hashes are truncated to 63 chars (VARCHAR limit).
	 * Full hash is preserved in JSON.
	 *
	 * @param array<string, string> $hashes  algo => hex-hash pairs
	 * @noinspection PhpUnhandledExceptionInspection
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	private function insertHashMetadata(
		int   $fileId,
		array $hashes,
	): void {

		$json = [];

		foreach ( $hashes as $algo => $hash )
		{
			$json[ MetadataService::getHashKey( $algo ) ] = [
				'value'          => $hash,
				'type'           => 'string',
				'etag'           => '',
				'indexed'        => true,
				'editPermission' => 0,
			];
		}

		$jsonPayload = json_encode( $json );

		// Through the query builder, and delete-then-insert rather than an
		// upsert. `ON DUPLICATE KEY UPDATE` and `NOW()` are MySQL's alone,
		// and this app supports every database Nextcloud does — a fixture
		// that only seeds on MariaDB makes the suite untestable on the
		// others the day anyone tries.
		//
		// `sync_token` and `last_update` are NOT NULL with no defaults, so
		// both are given values here rather than left out.
		$delete = $this->db->getQueryBuilder();
		$delete->delete( 'files_metadata' )
		       ->where(
			       $delete->expr()
			              ->eq( 'file_id', $delete->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ) ),
		       )
		;
		$delete->executeStatement();

		$insert = $this->db->getQueryBuilder();
		$insert->insert( 'files_metadata' )
		       ->values( [
			       'file_id'     => $insert->createNamedParameter( $fileId, IQueryBuilder::PARAM_INT ),
			       'json'        => $insert->createNamedParameter( $jsonPayload ),
			       'sync_token'  => $insert->createNamedParameter( '' ),
			       'last_update' => $insert->createNamedParameter(
				       ( new DateTime() )->format( 'Y-m-d H:i:s' ),
			       ),
		       ] )
		;
		$insert->executeStatement();

		// Insert index rows (one per algo), truncating hash to 63 chars
		foreach ( $hashes as $algo => $hash )
		{
			$this->getRawConnection()
			     ->executeStatement(
				     'INSERT INTO `*PREFIX*files_metadata_index` (`file_id`, `meta_key`, `meta_value_string`, `meta_value_int`) VALUES (?, ?, ?, ?)',
				     [ $fileId, MetadataService::getHashKey( $algo ), substr( $hash, 0, 63 ), 0 ],
			     )
			;
		}
	}


	private function cleanupLeftovers(): void
	{

		$ids = array_merge( $this->cleanupFileIds, [ $this->inaccessibleFileId ] );

		if ( empty( $ids ) )
		{
			return;
		}

		$inPlaceholders = implode( ',', array_fill( 0, count( $ids ), '?' ) );

		try
		{
			$this->getRawConnection()
			     ->executeStatement(
				     "DELETE FROM `*PREFIX*files_metadata_index` WHERE `file_id` IN ($inPlaceholders)",
				     $ids,
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
				     $ids,
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
				     "DELETE FROM `$this->fcTable` WHERE `fileid` IN ($inPlaceholders)",
				     $ids,
			     )
			;
		}
		catch ( Throwable )
		{
		}
	}

}
