<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\Server;
use Throwable;

/**
 * `stat`: each file's `etag`, valid for its hashes or null with them, and
 * its present `size`, `mtime` and `storageMtime`; a stale file listed, and
 * given by its hashes' route, only then.
 *
 * Through `ChecksumApi`, against real files; a file is made stale by moving
 * the filecache's ETag on by hand, as a write the app missed moves it.
 */
class FileStateTest
    extends
    DatabaseTestCase
{

//  private properties

	private ChecksumApi            $api;

	private HashCalculationService $hashCalc;

	private FilecacheService       $filecacheService;

	private RuleService            $ruleService;

	/** @var list<File> */
	private array $cleanupFiles = [];

	/** @var list<int> */
	private array $cleanupFileIds = [];


//  getters / setters / is* / has*

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->api              = Server::get( ChecksumApi::class );
		$this->hashCalc         = Server::get( HashCalculationService::class );
		$this->filecacheService = Server::get( FilecacheService::class );
		$this->ruleService      = Server::get( RuleService::class );

		$this->preserveStoredRules();

		foreach ( $this->ruleService->loadRules() as $rule )
		{
			$this->ruleService->ruleDelete( (string) $rule['id'] );
		}
	}


//  other non-static methods

	protected function tearDown(): void
	{
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

		foreach ( $this->cleanupFileIds as $fileId )
		{
			foreach ( [ 'files_metadata_index', 'files_metadata' ] as $table )
			{
				try
				{
					$this->getRawConnection()
					     ->executeStatement( "DELETE FROM `*PREFIX*$table` WHERE `file_id` = ?", [ $fileId ] )
					;
				}
				catch ( Throwable )
				{
				}
			}
		}

		parent::tearDown();
	}

	/**
	 * A current file under `stat`: the ETag it was read against, its size
	 * and its mtimes, on every route; nothing of it without.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testACurrentFilesStateOnEveryRoute(): void
	{
		$file  = $this->hashed( 'fcias_state_current', 'twelve bytes' );
		$state = $this->filecacheService->stateOf( $file->getId() );
		$want  = [
			'etag'         => $state['etag'],
			'size'         => 12,
			'mtime'        => date( 'c', $state['mtime'] ),
			'storageMtime' => date( 'c', $state['storageMtime'] ),
		];

		$this->assertSame( $want, $this->stateIn( $this->listed( $file, true ) ) );
		$this->assertSame( $want, $this->stateIn( $this->api->getHashesByFileId( $file->getId(), null, withStat: true ) ) );
		$this->assertSame( $want, $this->stateIn( $this->found( $file ) ) );

		$this->assertArrayNotHasKey( 'etag', $this->listed( $file, false ) );
		$this->assertArrayNotHasKey( 'etag', $this->api->getHashesByFileId( $file->getId(), null ) );
	}

	/**
	 * A stale file: under `stat`, listed and given with its hashes and ETag
	 * null and its present state; without, not listed, and no hashes.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAStaleFileIsGivenWithItsEvidenceNullOnlyUnderStat(): void
	{
		$file = $this->hashed( 'fcias_state_stale', 'twelve bytes' );
		$this->etagMovesOn( $file );

		$listed = $this->listed( $file, true );
		$this->assertNotNull( $listed, 'Listed under stat.' );
		$this->assertNull( $listed['hashes'] );
		$this->assertNull( $listed['etag'] );
		$this->assertSame( 12, $listed['size'] );

		$perFile = $this->api->getHashesByFileId( $file->getId(), null, withStat: true );
		$this->assertNull( $perFile['hashes'] );
		$this->assertNull( $perFile['etag'] );
		$this->assertSame( 12, $perFile['size'] );

		$this->assertNull( $listed['updated_at'], 'The stamp of stale hashes goes with them.' );
		$this->assertNull( $perFile['updated_at'] );

		$this->assertNull( $this->listed( $file, false ), 'Not listed without stat.' );
		$this->assertSame( [], $this->api->getHashesByFileId( $file->getId(), null )['hashes'] );
	}

	/**
	 * Hashes a reset disowned: under `stat` null, as the listing gives
	 * them; without, shown as stored, as before `stat` existed.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testADisownedFilesHashesAreNullOnlyUnderStat(): void
	{
		$file = $this->hashed( 'fcias_state_disowned', 'twelve bytes' );
		Server::get( MetadataService::class )->markPending( $file->getId(), MetadataService::STATE_RESET );

		$perFile = $this->api->getHashesByFileId( $file->getId(), null, withStat: true );
		$this->assertNull( $perFile['hashes'] );
		$this->assertNull( $perFile['etag'] );

		$listed = $this->listed( $file, true );
		$this->assertNotNull( $listed, 'Listed under stat.' );
		$this->assertNull( $listed['hashes'], 'As the listing gives it.' );

		$this->assertSame( sha1( 'twelve bytes' ), $this->api->getHashesByFileId( $file->getId(), null )['hashes']['sha1']['hash'] ?? null );
	}

	/**
	 * A file never hashed has no hashes, `{}`: not null, which says stale.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileWithoutHashesHasNoneUnderStat(): void
	{
		$file = $this->createTestFile( 'fcias_state_none', 'twelve bytes' );

		$perFile = $this->api->getHashesByFileId( $file->getId(), null, withStat: true );

		$this->assertSame( [], $perFile['hashes'] );
		$this->assertNull( $perFile['etag'] );
		$this->assertSame( 12, $perFile['size'] );
	}

	/**
	 * A file whose ETag moved on leaves the duplicates: its group, and the
	 * duplicates of its own old hashes.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileWhoseEtagMovedOnLeavesTheDuplicates(): void
	{
		$content  = 'duplicate ' . bin2hex( random_bytes( 8 ) );
		$moved    = $this->hashed( 'fcias_state_dup_moved', $content );
		$kept     = $this->hashed( 'fcias_state_dup_kept', $content );
		$metadata = Server::get( MetadataService::class );

		$this->assertCount( 1, $metadata->queryDuplicates( 'sha1', 2, 50, 0, sha1( $content ) ) );
		$this->assertContains( $kept->getId(), $this->sameAs( $moved ) );

		$this->etagMovesOn( $moved );

		$this->assertSame( [], $metadata->queryDuplicates( 'sha1', 2, 50, 0, sha1( $content ) ), 'No group of one.' );
		$this->assertSame( [], $this->sameAs( $moved ), 'Not searched by its old hashes.' );
		$this->assertNotContains( $moved->getId(), $this->sameAs( $kept ) );
	}

	/**
	 * Hashes stamped before the app kept the ETag, vouched for by their
	 * stamp, give the ETag the filecache holds.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testALegacyStampGivesTheFilecachesEtag(): void
	{
		$file = $this->hashed( 'fcias_state_legacy', 'twelve bytes' );

		$manager  = Server::get( IFilesMetadataManager::class );
		$document = $manager->getMetadata( $file->getId(), true );
		$stamp    = $document->getInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT );
		$document->unset( MetadataService::KEY_FILE_CHECKSUM_ETAG );
		$document->setInt( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $stamp, true );
		$manager->saveMetadata( $document );

		$this->assertSame( $this->filecacheService->etagOf( $file->getId() ), $this->listed( $file, true )['etag'] ?? null );
	}

	/**
	 * A recalculation gives the state it left: the ETag it read against.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testARecalculationGivesTheEtagItReadAgainst(): void
	{
		$file = $this->createTestFile( 'fcias_state_recalc', 'twelve bytes' );

		$one  = $this->api->recalcHash( $file->getId(), null, 'sha1', withStat: true );
		$many = $this->api->recalcMany( [ $file->getId() ], null, 'md5', withStat: true )['results'][0];
		$etag = $this->filecacheService->etagOf( $file->getId() );

		$this->assertTrue( $one['success'] );
		$this->assertSame( $etag, $one['etag'] );
		$this->assertSame( 12, $one['size'] );
		$this->assertTrue( $many['success'] );
		$this->assertSame( $etag, $many['etag'] );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function createTestFile(
		string $base,
		string $content,
	): File
	{
		$file = Server::get( IRootFolder::class )
		              ->getUserFolder( 'admin' )
		              ->newFile( $base . '_' . bin2hex( random_bytes( 4 ) ) . '.dat', $content )
		;

		$this->cleanupFiles[]   = $file;
		$this->cleanupFileIds[] = $file->getId();

		return $file;
	}

	/**
	 * A file hashed in sha1 on demand, which stamps it with the ETag read.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function hashed(
		string $base,
		string $content,
	): File
	{
		$file = $this->createTestFile( $base, $content );

		$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha1' )['success'] );

		return $file;
	}

	/**
	 * The file's listing entry, or null where the listing does not give it.
	 *
	 * @return array<string, mixed>|null
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function listed(
		File $file,
		bool $withStat,
	): ?array
	{
		foreach ( $this->api->iterateHashes( [ 'admin' ], null, null, false, $file->getId() - 1, withStat: $withStat ) as $fileId => $entry )
		{
			return $fileId === $file->getId()
				? $entry
				: null;
		}

		return null;
	}

	/**
	 * The file's row in a lookup by its sha1.
	 *
	 * @return array<string, mixed>|null
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function found( File $file ): ?array
	{
		foreach ( $this->api->findByHash( sha1( (string) $file->getContent() ), null, 'sha1', withStat: true )['results'] as $row )
		{
			if ( (int) $row['fileid'] === $file->getId() )
			{
				return $row;
			}
		}

		return null;
	}

	/**
	 * The ids of the files `/file/{id}/duplicates` gives for $file.
	 *
	 * @return list<int>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function sameAs( File $file ): array
	{
		$ids = [];

		foreach ( $this->api->findSameHash( $file->getId(), null )['duplicates'] as $group )
		{
			foreach ( $group['files'] as $entry )
			{
				$ids[] = (int) $entry['fileid'];
			}
		}

		return $ids;
	}

	/**
	 * @param  array<string, mixed>|null  $entry
	 *
	 * @return array<string, mixed>
	 */
	private function stateIn( ?array $entry ): array
	{
		$this->assertNotNull( $entry );

		return array_intersect_key( $entry, array_flip( [ 'etag', 'size', 'mtime', 'storageMtime' ] ) );
	}
}
