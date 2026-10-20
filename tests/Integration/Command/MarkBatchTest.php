<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Command;

use OCA\FileChecksumSearch\Command\HashFiles;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `fcias:hash --mark` queues the files that need hashing: a run with
 * `--batch-size` leaves those queued already, so the next starts where the
 * last stopped, and under `missing` it leaves a file whose checksums are
 * current. The files are written before any rule governs them, so that
 * nothing queues them on its own.
 */
class MarkBatchTest
    extends
    DatabaseTestCase
{

//  private properties

	private static string $uid;

	private RuleService $ruleService;


//  static methods

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		[ self::$uid ] = self::makeAccount( 'fcias_mark' );
	}


//  getters / setters / is* / has*

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->ruleService = Server::get( RuleService::class );

		$this->preserveStoredRules();

		foreach ( $this->ruleService->loadRules() as $rule )
		{
			$this->ruleService->ruleDelete( (string) $rule['id'] );
		}

		self::actAs( self::$uid );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		self::actAs( null );

		parent::tearDown();
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testABatchStartsWhereTheLastStopped(): void
	{
		$ids = $this->files( 'Batch', 5 );
		$this->rule( 'Batch/**' );

		foreach ( [ 2, 4, 5, 5 ] as $queued )
		{
			$this->mark( [ '--path' => 'Batch/*', '--batch-size' => '2' ] );

			$this->assertCount( $queued, Server::get( MetadataService::class )->queuedAmong( $ids ) );
		}
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileWhoseChecksumsAreCurrentIsLeftUnderMissing(): void
	{
		[ $fresh, $stale ] = $this->files( 'Fresh', 2 );
		$this->rule( 'Fresh/**' );
		Server::get( HashCalculationService::class )->recalcHashes( $fresh, [ 'sha1' ] );

		$this->mark( [ '--path' => 'Fresh/*' ] );

		$this->assertSame( [ $stale => true ], Server::get( MetadataService::class )->queuedAmong( [ $fresh, $stale ] ) );
	}

	/**
	 * $count files in a folder of the account's, written while no rule
	 * governs them.
	 *
	 * @return list<int>  their ids
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function files(
		string $folderName,
		int    $count,
	): array
	{
		$folder = self::folderAt( Server::get( IRootFolder::class )->getUserFolder( self::$uid ), $folderName );
		$ids    = [];

		for ( $i = 0; $i < $count; $i ++ )
		{
			$file = $folder->newFile( $i . '.txt', $folderName . ' ' . $i );
			$this->assertInstanceOf( File::class, $file );
			$ids[] = $file->getId();
		}

		$this->assertSame( [], Server::get( MetadataService::class )->queuedAmong( $ids ), 'nothing queued them' );

		return $ids;
	}

	/**
	 * An enabled include rule over the account's $path, sha1.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function rule( string $path ): void
	{
		$this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_INCLUDE,
				'path'     => $path,
				'mode'     => MetadataService::PENDING_MODE_AUTO,
				'selector' => 'home:' . self::$uid,
				'algos'    => [ 'sha1' ],
			],
		);
	}

	/**
	 * Run `fcias:hash --mark` over the account.
	 *
	 * @param  array<string, mixed>  $options
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function mark( array $options ): void
	{
		$tester = new CommandTester( Server::get( HashFiles::class ) );

		$this->assertSame(
			Command::SUCCESS,
			$tester->execute(
				[
					'--user' => self::$uid,
					'--mark' => true,
				] + $options,
			),
			$tester->getDisplay(),
		);
	}
}
