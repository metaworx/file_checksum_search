<?php
/** @noinspection SqlNoDataSourceInspection */

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\ProcessPendingUpdates;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\Server;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * End-to-end verification that ProcessPendingUpdates actually drains
 * pending metadata index entries (pending:new / pending:missing) and
 * computes hashes, and that Application::boot() no longer re-registers
 * background jobs on every request (which reset last_run).
 */
class ProcessPendingUpdatesIntegrationTest
	extends
	DatabaseTestCase
{

	private const RULE_CONFIG_KEY = 'rule_definitions';

	private MetadataService $metadataService;

	private RuleService     $ruleService;

	private IAppConfig      $appConfig;

	private IJobList        $jobList;

	private string          $originalRulesJson = '';

	/** @var File[] */
	private array $cleanupFiles = [];

	/** @var list<int> */
	private array $cleanupFileIds = [];


	protected function setUp(): void
	{

		parent::setUp();

		$this->metadataService = Server::get( MetadataService::class );
		$this->ruleService     = Server::get( RuleService::class );
		$this->appConfig       = Server::get( IAppConfig::class );
		$this->jobList         = Server::get( IJobList::class );

		$this->originalRulesJson = $this->appConfig->getValueString(
			Application::APP_ID,
			self::RULE_CONFIG_KEY,
			'[]',
		);
	}


	protected function tearDown(): void
	{

		// Roll back any open transaction before touching committed state.
		parent::tearDown();

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::RULE_CONFIG_KEY,
			$this->originalRulesJson,
		);

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

		if ( ! empty( $this->cleanupFileIds ) )
		{
			$placeholders = implode( ',', array_fill( 0, count( $this->cleanupFileIds ), '?' ) );

			try
			{
				$this->getRawConnection()
				     ->executeStatement(
					     "DELETE FROM `*PREFIX*filecache` WHERE `fileid` IN ($placeholders)",
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
					     "DELETE FROM `*PREFIX*files_metadata_index` WHERE `file_id` IN ($placeholders)",
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
					     "DELETE FROM `*PREFIX*files_metadata` WHERE `file_id` IN ($placeholders)",
					     $this->cleanupFileIds,
				     )
				;
			}
			catch ( Throwable )
			{
			}
		}
	}


	/**
	 * Cron drains pending entries and computes hashes for both the
	 * pending:new (rule-resolved) and pending:missing (direct) markers.
	 */
	public function testCronDrainsPendingEntriesAndComputesHashes(): void
	{

		// Create files first so NodeCreatedEvent sees no rules.
		$newFile     = $this->createTestFile( 'fcias_cron_new_' . time() . '.dat' );
		$missingFile = $this->createTestFile( 'fcias_cron_missing_' . time() . '.dat' );

		// Catch-all force rule so pending:new resolves to force mode.
		$this->addCatchAllForceRule();

		$newFileId     = $newFile->getId();
		$missingFileId = $missingFile->getId();
		$testFileIds   = [ $newFileId, $missingFileId ];

		$this->beginTransaction();

		$this->insertPendingMarker( $newFileId, MetadataService::PENDING_NEW );
		$this->insertPendingMarker(
			$missingFileId,
			MetadataService::PENDING_PREFIX . 'missing',
		);

		// Isolate: keep only our two pending rows so the cron batch is
		// deterministic regardless of unrelated pending data in the dev DB.
		$this->deleteOtherPendingRows( $testFileIds );

		$this->assertSame(
			2,
			$this->countPendingRows( $testFileIds ),
			'Both test files should be pending before the cron run.',
		);

		$job = $this->buildJob();

		$reflection = new ReflectionMethod( ProcessPendingUpdates::class, 'run' );
		$reflection->invoke( $job, null );

		$this->assertSame(
			0,
			$this->countPendingRows( $testFileIds ),
			'Pending markers should be drained after the cron run.',
		);

		foreach ( $testFileIds as $fileId )
		{
			$hashes = $this->metadataService->getHashes( $fileId );

			$this->assertNotEmpty( $hashes, "Hash entries should exist in oc_files_metadata for fileId $fileId." );
			$this->assertArrayHasKey( 'sha1', $hashes, "sha1 hash should be computed for fileId $fileId." );
			$this->assertNotEmpty( $hashes['sha1'] );
			$this->assertArrayHasKey( 'sha256', $hashes, "sha256 hash should be computed for fileId $fileId." );
			$this->assertNotEmpty( $hashes['sha256'] );
		}
	}


	/**
	 * Running Application::boot() twice must not reset the job's last_run.
	 */
	public function testBootTwiceDoesNotResetJobLastRun(): void
	{

		$jobClass = ProcessPendingUpdates::class;

		$originalRow = $this->fetchJobRow( $jobClass );
		$created     = false;

		if ( $originalRow === null )
		{
			$this->jobList->add( $jobClass );
			$originalRow = $this->fetchJobRow( $jobClass );
			$created     = true;
		}

		$sentinel = 2000000000;

		try
		{
			$this->setJobLastRun( $jobClass, $sentinel );

			$app     = new Application();
			$context = $this->createMock( IBootContext::class );

			$app->boot( $context );
			$app->boot( $context );

			$row = $this->fetchJobRow( $jobClass );
			$this->assertNotNull( $row, 'The ProcessPendingUpdates job row should exist.' );
			$this->assertSame(
				$sentinel,
				(int) $row['last_run'],
				'Application::boot() must not reset last_run.',
			);
		}
		finally
		{
			if ( $created )
			{
				$this->jobList->remove( $jobClass );
			}
			else
			{
				$this->setJobLastRun( $jobClass, (int) ( $originalRow['last_run'] ?? 0 ) );
			}
		}
	}


	// ─── helpers ──────────────────────────────────────────────────────


	private function buildJob(): ProcessPendingUpdates
	{

		return new ProcessPendingUpdates(
			Server::get( ITimeFactory::class ),
			Server::get( HashCalculationService::class ),
			$this->metadataService,
			$this->appConfig,
			$this->jobList,
			Server::get( LoggerInterface::class ),
		);
	}


	/**
	 * Add a catch-all rule with mode=force so pending:new computes hashes.
	 */
	private function addCatchAllForceRule(): void
	{

		// Prepend so it takes precedence over any pre-existing rules
		// (findFirstMatchingRule returns the first path match).
		$rules = $this->ruleService->loadRules();
		array_unshift(
			$rules,
			[
				'id'        => 'fcias_inttest_catchall',
				'enabled'   => true,
				'path'      => '**',
				'mode'      => MetadataService::PENDING_MODE_FORCE,
				'userScope' => 'all',
			],
		);

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::RULE_CONFIG_KEY,
			json_encode( $rules, JSON_THROW_ON_ERROR ),
		);
	}


	/**
	 * Create a real test file in the admin user's storage.
	 */
	private function createTestFile( string $name ): File
	{

		$userFolder = Server::get( IRootFolder::class )
		                    ->getUserFolder( 'admin' )
		;

		$file = $userFolder->newFile( $name, 'FCIAS cron integration — ' . microtime( true ) );

		$this->cleanupFiles[]   = $file;
		$this->cleanupFileIds[] = $file->getId();

		return $file;
	}


	private function insertPendingMarker( int $fileId, string $marker ): void
	{

		$this->getRawConnection()
		     ->executeStatement(
			     'INSERT INTO `*PREFIX*files_metadata_index` (`file_id`, `meta_key`, `meta_value_string`, `meta_value_int`) VALUES (?, ?, ?, 0)',
			     [ $fileId, MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT, $marker ],
		     )
		;
	}


	/**
	 * Remove every pending row except those belonging to $keepFileIds.
	 */
	private function deleteOtherPendingRows( array $keepFileIds ): void
	{

		$placeholders = implode( ',', array_fill( 0, count( $keepFileIds ), '?' ) );

		$this->getRawConnection()
		     ->executeStatement(
			     "DELETE FROM `*PREFIX*files_metadata_index` WHERE `meta_key` = 'file-checksum-updated_at' AND `meta_value_string` LIKE 'pending:%' AND `file_id` NOT IN ($placeholders)",
			     $keepFileIds,
		     )
		;
	}


	private function countPendingRows( array $fileIds ): int
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select(
			$qb->func()
			   ->count( '*', 'cnt' ),
		)
		   ->from( MetadataService::TABLE_FILES_METADATA_INDEX )
		   ->where(
			   $qb->expr()
			      ->eq(
				      MetadataService::FIELD_META_KEY,
				      $qb->createNamedParameter( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT ),
			      ),
			   $qb->expr()
			      ->like(
				      MetadataService::FIELD_META_VALUE_STRING,
				      $qb->createNamedParameter( MetadataService::PENDING_LIKE ),
			      ),
			   $qb->expr()
			      ->in(
				      MetadataService::FIELD_FILE_ID,
				      $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
			      ),
		   )
		;

		return (int) $qb->executeQuery()
		                ->fetchOne()
		;
	}


	/**
	 * @return array{id: int, class: string, last_run: int}|null
	 */
	private function fetchJobRow( string $class ): ?array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'id', 'class', 'last_run' )
		   ->from( 'jobs' )
		   ->where(
			   $qb->expr()
			      ->eq( 'class', $qb->createNamedParameter( $class ) ),
		   )
		;

		$result = $qb->executeQuery();
		$row    = $result->fetch();
		$result->closeCursor();

		return $row === false
			? null
			: [
				'id'       => (int) $row['id'],
				'class'    => (string) $row['class'],
				'last_run' => (int) $row['last_run'],
			];
	}


	private function setJobLastRun( string $class, int $lastRun ): void
	{

		$qb = $this->db->getQueryBuilder();
		$qb->update( 'jobs' )
		   ->set( 'last_run', $qb->createNamedParameter( $lastRun, IQueryBuilder::PARAM_INT ) )
		   ->where(
			   $qb->expr()
			      ->eq( 'class', $qb->createNamedParameter( $class ) ),
		   )
		;

		$qb->executeStatement();
	}

}
