<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Server;
use Throwable;

/**
 * The files in reach without a hash: the governed ones by default, the
 * unmatched, ignored and excluded ones on asking; and with the listing
 * under `stat`, each file of the files area once.
 *
 * Through `ChecksumApi`, against real files under rules made for each.
 */
class MissingHashesTest
    extends
    DatabaseTestCase
{

//  private properties

	private ChecksumApi            $api;

	private HashCalculationService $hashCalc;

	private RuleService            $ruleService;

	/** @var array<string, File>  by what the file stands for */
	private array $files = [];

	/** @var list<File> */
	private array $cleanupFiles = [];


//  getters / setters / is* / has*

	/**
	 * Five files: governed by an include rule, ignored, excluded, matched
	 * by no rule, and a governed one hashed.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->api         = Server::get( ChecksumApi::class );
		$this->hashCalc    = Server::get( HashCalculationService::class );
		$this->ruleService = Server::get( RuleService::class );

		$this->preserveStoredRules();

		foreach ( $this->ruleService->loadRules() as $rule )
		{
			$this->ruleService->ruleDelete( (string) $rule['id'] );
		}

		foreach ( [ 'governed', 'ignored', 'excluded', 'unmatched', 'hashed' ] as $kind )
		{
			$this->files[ $kind ] = $this->createTestFile( 'fcias_missing_' . $kind, 'content of ' . $kind );
		}

		$this->ruleFor( $this->files['governed'], RuleService::TYPE_INCLUDE );
		$this->ruleFor( $this->files['hashed'], RuleService::TYPE_INCLUDE );
		$this->ruleFor( $this->files['ignored'], RuleService::TYPE_IGNORE );
		$this->ruleFor( $this->files['excluded'], RuleService::TYPE_EXCLUDE );

		$this->assertTrue( $this->hashCalc->recalcFileHash( $this->files['hashed'], 'sha1' )['success'] );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		foreach ( $this->cleanupFiles as $file )
		{
			$fileId = $file->getId();

			try
			{
				$file->delete();
			}
			catch ( Throwable )
			{
			}

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
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheGovernedFilesByDefaultTheOthersOnAsking(): void
	{
		$this->assertSame( [ 'governed' ], $this->missing() );
		$this->assertSame( [ 'governed', 'unmatched' ], $this->missing( unmatched: true ) );
		$this->assertSame( [ 'governed', 'ignored' ], $this->missing( withIgnored: true ) );
		$this->assertSame( [ 'governed', 'excluded' ], $this->missing( withExcluded: true ) );
		$this->assertSame(
			[ 'governed', 'ignored', 'excluded', 'unmatched' ],
			$this->missing( unmatched: true, withIgnored: true, withExcluded: true ),
		);
	}

	/**
	 * With an algorithm, the files without a hash in it, whatever others
	 * they hold.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnAlgorithmNamesTheFilesWithoutIt(): void
	{
		$this->assertSame( [ 'governed' ], $this->missing( algo: 'sha1' ) );
		$this->assertSame( [ 'governed', 'hashed' ], $this->missing( algo: 'md5' ) );
	}

	/**
	 * A page holds at most its limit and says where the next one starts;
	 * every page carries `now`; and a file under `stat` its present state,
	 * with no ETag.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAPageIsLimitedAndCarriesNextNowAndTheState(): void
	{
		$first = $this->api->listMissing( [ 'admin' ], null, 1, $this->before(), true, true, true, true );

		$this->assertCount( 1, $first['files'] );
		$this->assertSame( $first['files'][0]['fileid'], $first['next'] );
		$this->assertIsInt( $first['now'] );
		$this->assertNull( $first['files'][0]['etag'] );
		$this->assertIsInt( $first['files'][0]['size'] );
		$this->assertArrayHasKey( 'storageMtime', $first['files'][0] );

		$this->assertArrayNotHasKey( 'etag', $this->api->listMissing( [ 'admin' ], null, 1, $this->before() )['files'][0] ?? [] );
	}

	/**
	 * The listing under `stat` and the missing files with every flag name
	 * each of the files made here once.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testWithTheListingUnderStatEachFileComesOnce(): void
	{
		$ids  = array_map( static fn( File $file ): int => $file->getId(), $this->files );
		$seen = [];

		foreach ( $this->api->iterateHashes( [ 'admin' ], null, null, false, $this->before(), withStat: true ) as $fileId => $entry )
		{
			$seen[] = $fileId;
		}

		foreach ( $this->allMissing( unmatched: true, withIgnored: true, withExcluded: true ) as $fileId )
		{
			$seen[] = $fileId;
		}

		$seen = array_values( array_intersect( $seen, $ids ) );
		sort( $seen );
		$want = array_values( $ids );
		sort( $want );

		$this->assertSame( $want, $seen );
	}

	/**
	 * Nobody's files but the reach's.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnotherReachHoldsNoneOfThem(): void
	{
		$this->assertSame( [], $this->api->listMissing( [ 'fcias-nobody' ], null, 1000, $this->before(), true, true, true )['files'] );
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

		$this->cleanupFiles[] = $file;

		return $file;
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function ruleFor(
		File   $file,
		string $type,
	): void
	{
		$this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => $type,
				'path'     => $file->getName(),
				'mode'     => MetadataService::PENDING_MODE_AUTO,
				'selector' => '*',
				'algos'    => [ 'sha1' ],
			],
		);
	}

	/** The file id before the first file made here, where a walk starts. */
	private function before(): int
	{
		return min( array_map( static fn( File $file ): int => $file->getId(), $this->files ) ) - 1;
	}

	/**
	 * What the files made here stand for, among the missing ones.
	 *
	 * @return list<string>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function missing(
		?string $algo = null,
		bool    $unmatched = false,
		bool    $withIgnored = false,
		bool    $withExcluded = false,
	): array
	{
		$kinds = array_flip( array_map( static fn( File $file ): int => $file->getId(), $this->files ) );
		$found = [];

		foreach ( $this->allMissing( $algo, $unmatched, $withIgnored, $withExcluded ) as $fileId )
		{
			if ( isset( $kinds[ $fileId ] ) )
			{
				$found[ $fileId ] = $kinds[ $fileId ];
			}
		}

		ksort( $found );

		return array_values( $found );
	}

	/**
	 * Every page from before the files made here to the end.
	 *
	 * @return list<int>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function allMissing(
		?string $algo = null,
		bool    $unmatched = false,
		bool    $withIgnored = false,
		bool    $withExcluded = false,
	): array
	{
		$ids   = [];
		$after = $this->before();

		do
		{
			$page  = $this->api->listMissing( [ 'admin' ], $algo, 1000, $after, $unmatched, $withIgnored, $withExcluded );
			$ids   = array_merge( $ids, array_column( $page['files'], 'fileid' ) );
			$after = $page['next'];
		}
		while ( $after !== null );

		return $ids;
	}
}
