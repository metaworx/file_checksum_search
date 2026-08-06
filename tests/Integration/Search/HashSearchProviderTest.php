<?php
/** @noinspection SqlNoDataSourceInspection */

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Search;

use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCA\FileChecksumSearch\Search\HashSearchProvider;
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
 * Verifies that search returns results for known hashes, returns empty
 * for unknown hashes, and respects user access boundaries.
 */
class HashSearchProviderTest
	extends
	DatabaseTestCase
{

	private HashSearchProvider $provider;

	private IUser              $adminUser;

	private string             $hashTable;

	private string             $fcTable;

	/** @var File[] */
	private array $cleanupFiles = [];

	/** @var list<int> */
	private array $cleanupFileIds = [];

	/** File ID for the inaccessible‑file test (set in setUp). */
	private int $inaccessibleFileId;


	protected function setUp(): void
	{

		parent::setUp();

		$this->provider  = Server::get( HashSearchProvider::class );
		$this->adminUser = Server::get( IUserManager::class )
		                         ->get( 'admin' )
		;
		$this->hashTable = $this->getHashTableName();
		$this->fcTable   = $this->getFilecacheTableName();

		// Use a high ID with time‑based suffix to avoid collisions.
		$this->inaccessibleFileId = 99999000 + ( time() % 1000 );

		Server::get( LifecycleHandler::class )
		      ->createTables()
		;

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

		$this->getRawConnection()
		     ->executeStatement(
			     "INSERT INTO `$this->hashTable` (`fileid`, `algo`, `hash_value`, `updated_at`) VALUES (?, ?, ?, NOW())",
			     [
				     $fileId,
				     'sha1',
				     $testHash,
			     ],
		     )
		;

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

		$this->getRawConnection()
		     ->executeStatement(
			     "INSERT INTO `$this->hashTable` (`fileid`, `algo`, `hash_value`, `updated_at`) VALUES (?, ?, ?, NOW())",
			     [
				     $fileId,
				     'sha256',
				     $testHash,
			     ],
		     )
		;

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


	public function testSearchExcludesInaccessibleFiles(): void
	{

		$fileId                 = $this->inaccessibleFileId;
		$this->cleanupFileIds[] = $fileId;

		// Insert a filecache row with a non‑existent storage ID.
		// getById() won't resolve this, so the search must filter it out.
		$this->getRawConnection()
		     ->executeStatement(
			     <<<SQL
INSERT INTO `$this->fcTable` (`fileid`, `storage`, `path`, `path_hash`, `parent`, `name`, `mimetype`,
                                `mimepart`, `size`, `mtime`, `storage_mtime`, `encrypted`, `unencrypted_size`,
                                `etag`, `checksum`)
VALUES (?, 99999, ?, ?, -1, 'inaccessible.dat', 0, 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 0, ?, '')
SQL,
			     [
				     $fileId,
				     'files/inaccessible_' . $fileId . '.dat',
				     md5( 'inaccessible_' . $fileId ),
				     md5( 'etag_inaccessible_' . $fileId ),
			     ],
		     )
		;

		$testHash = 'cafebabecafebabecafebabecafebabecafebabe';

		$this->getRawConnection()
		     ->executeStatement(
			     "INSERT INTO `$this->hashTable` (`fileid`, `algo`, `hash_value`, `updated_at`) VALUES (?, ?, ?, NOW())",
			     [
				     $fileId,
				     'sha256',
				     $testHash,
			     ],
		     )
		;

		$query = $this->createSearchQuery( $testHash );

		$result = $this->provider->search( $this->adminUser, $query );

		$data = $result->jsonSerialize();

		$this->assertEmpty(
			$data['entries'],
			'Inaccessible files (non‑existent storage) should be excluded from search results.',
		);
	}


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
		$sha256Hash = '2222222222222222222222222222222222222222222222222222222222222222';

		// Insert two rows for the same file — different algos
		$this->getRawConnection()
		     ->executeStatement(
			     "INSERT INTO `$this->hashTable` (`fileid`, `algo`, `hash_value`, `updated_at`) VALUES (?, ?, ?, NOW()), (?, ?, ?, NOW())",
			     [
				     $fileId,
				     'sha1',
				     $sha1Hash,
				     $fileId,
				     'sha256',
				     $sha256Hash,
			     ],
		     )
		;

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
				     "DELETE FROM `$this->hashTable` WHERE `fileid` IN ($inPlaceholders)",
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
