<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\ResolveDuplicates;
use OCA\FileChecksumSearch\Service\DuplicateActionService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\PathPattern;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command over mocked services: what it refuses to run, which rows it
 * hands the service, as a dry run or to act on, when it asks, and what it
 * reports.
 */
class ResolveDuplicatesTest
    extends
    TestCase
{

//  private properties

	private HashIndexService&MockObject       $hashIndexService;

	private DuplicateActionService&MockObject $duplicateActions;

	private IUserManager&MockObject           $userManager;

	private CommandTester                     $tester;

	/**
	 * The groups the listing holds, per page.
	 *
	 * @var list<list<array<string, mixed>>>
	 */
	private array $pages = [];

	/**
	 * The rows handed to the service as a dry run, per group key.
	 *
	 * @var array<string, list<int>>
	 */
	private array $deleted = [];

	/**
	 * The rows handed to the service to act on, per group key.
	 *
	 * @var array<string, list<int>>
	 */
	private array $acted = [];

	/**
	 * Each call to the service: whether for good, and the copy taking the
	 * versions.
	 *
	 * @var list<array{forGood: bool, mergeInto: ?int}>
	 */
	private array $calls = [];

	/**
	 * The rows the service refuses, with the reason.
	 *
	 * @var array<int, string>
	 */
	private array $refusals = [];

	/**
	 * The rows that fail when acted on.
	 *
	 * @var list<int>
	 */
	private array $failures = [];


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->hashIndexService = $this->createMock( HashIndexService::class );
		$this->duplicateActions = $this->createMock( DuplicateActionService::class );
		$this->userManager      = $this->createMock( IUserManager::class );

		$this->userManager->method( 'get' )
		                  ->willReturnCallback( fn ( string $uid ): ?IUser => $uid === 'nobody' ? null : $this->user( $uid ) )
		;
		$this->hashIndexService->method( 'listDuplicatesForUser' )
		                       ->willReturnCallback(
			                       fn ( $uid, $algo, $minCount, $limit, $offset ): array => [
				                       'duplicates'   => $this->pages[ intdiv( $offset, $limit ) ] ?? [],
				                       'total_groups' => 0,
				                       'pagination'   => [
					                       'offset'      => $offset,
					                       'limit'       => $limit,
					                       'next_offset' => isset( $this->pages[ intdiv( $offset, $limit ) + 1 ] ) ? $offset + $limit : null,
				                       ],
			                       ],
		                       )
		;
		// Every row the account's to delete, both ways, and a file one that
		// may take versions.
		$this->duplicateActions->method( 'withActions' )
		                       ->willReturnCallback(
			                       static fn ( IUser $user, array $groups ): array => array_map(
				                       static fn ( array $group ): array => [
					                       'files' => array_map( static fn ( array $file ): array => $file + [ 'actions' => [ 'trash', 'delete', 'mergeInto' ] ], $group['files'] ),
				                       ] + $group,
				                       $groups,
			                       ),
		                       )
		;
		$this->duplicateActions->method( 'delete' )
		                       ->willReturnCallback(
			                       function( IUser $user, array $fileIds, string $algo, string $hash, bool $forGood, bool $dryRun, ?int $mergeInto ): array
			                       {
				                       $this->calls[] = [ 'forGood' => $forGood, 'mergeInto' => $mergeInto ];

				                       if ( $dryRun )
				                       {
					                       $this->deleted[ "$algo:$hash" ] = [ ...$this->deleted[ "$algo:$hash" ] ?? [], ...$fileIds ];
				                       }
				                       else
				                       {
					                       $this->acted[ "$algo:$hash" ] = [ ...$this->acted[ "$algo:$hash" ] ?? [], ...$fileIds ];
				                       }

				                       return array_map(
					                       fn ( int $fileId ): array => [ 'fileid' => $fileId, 'kind' => 'file' ] + match ( true )
					                       {
						                       isset( $this->refusals[ $fileId ] )                  => [ 'status' => 'refused', 'reason' => $this->refusals[ $fileId ] ],
						                       $dryRun                                              => [ 'status' => 'ready', 'action' => $forGood ? 'delete' : 'trash' ],
						                       in_array( $fileId, $this->failures, true )           => [ 'action' => 'trash', 'status' => 'failed', 'reason' => 'The storage is read-only.' ],
						                       default                                              => [ 'action' => $forGood ? 'delete' : 'trash', 'status' => $forGood ? 'deleted' : 'trashed' ],
					                       },
					                       $fileIds,
				                       );
			                       },
		                       )
		;

		// In an application, for the question's helper.
		$command = new ResolveDuplicates(
			$this->hashIndexService,
			$this->duplicateActions,
			$this->userManager,
			$this->createMock( LoggerInterface::class ),
		);
		( new Application() )->add( $command );

		$this->tester = new CommandTester( $command );
	}


//  static methods

	/**
	 * @param  list<array<string, mixed>>  $files
	 *
	 * @return array<string, mixed>
	 */
	private static function group(
		string $hash,
		array  $files,
		string $algo = 'sha1',
	): array
	{
		return [
			'algo'       => $algo,
			'hash_value' => $hash,
			'file_count' => count( $files ),
			'empty'      => false,
			'files'      => $files,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function file(
		int    $fileId,
		string $path,
		int    $mtime,
	): array
	{
		return [
			'fileid' => $fileId,
			'path'   => $path,
			'name'   => basename( $path ),
			'mtime'  => $mtime,
		];
	}


//  other non-static methods

	private function user( string $uid ): IUser&MockObject
	{
		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( $uid )
		;

		return $user;
	}

	/**
	 * @param  array<string, mixed>  $input
	 */
	private function dryRun( array $input ): int
	{
		return $this->tester->execute( $input + [ '--dry-run' => true ] );
	}

	public function testNeitherAccountsNorAllIsRefused(): void
	{
		$this->assertSame( Command::INVALID, $this->dryRun( [] ) );
		$this->assertStringContainsString( 'Name the accounts with --user, or give --all', $this->tester->getDisplay() );
		$this->assertSame( Command::INVALID, $this->dryRun( [ '--user' => [ 'alice' ], '--all' => true ] ) );
	}

	public function testAnAccountThatDoesNotExistIsRefusedBeforeAnythingIsRead(): void
	{
		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'listDuplicatesForUser' )
		;

		$this->assertSame( Command::INVALID, $this->dryRun( [ '--user' => [ 'alice', 'nobody' ] ] ) );
		$this->assertStringContainsString( 'User "nobody" not found.', $this->tester->getDisplay() );
	}

	public function testKeepingNoCopyAndNamingOneToKeepIsRefused(): void
	{
		$this->assertSame( Command::INVALID, $this->dryRun( [ '--user' => [ 'alice' ], '--remove-last' => true, '--keep' => 'newest' ] ) );
		$this->assertSame( Command::INVALID, $this->dryRun( [ '--user' => [ 'alice' ], '--keep' => 'largest' ] ) );
	}

	public function testAPatternTooLongIsRefused(): void
	{
		$this->assertSame( Command::INVALID, $this->dryRun( [ '--user' => [ 'alice' ], '--path' => [ str_repeat( 'x', PathPattern::MAX_LENGTH + 1 ) ] ] ) );
	}

	/** Every page is read, and each group's every copy but the oldest is handed over as a dry run. */
	public function testEveryCopyButTheOldestWouldGoFromEveryPage(): void
	{
		$this->pages = [
			[ self::group( 'a', [ self::file( 1, '/a.txt', 300 ), self::file( 2, '/Backup/a.txt', 100 ) ] ) ],
			[ self::group( 'b', [ self::file( 3, '/b.txt', 100 ), self::file( 4, '/Backup/b.txt', 200 ), self::file( 5, '/Old/b.txt', 300 ) ] ) ],
		];

		$this->assertSame( Command::SUCCESS, $this->dryRun( [ '--user' => [ 'alice' ] ] ) );
		$this->assertSame( [ 'sha1:a' => [ 1 ], 'sha1:b' => [ 4, 5 ] ], $this->deleted );

		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( 'kept     [2] /Backup/a.txt', $display );
		$this->assertStringContainsString( 'trash    [5] /Old/b.txt', $display );
		$this->assertStringContainsString( 'Would go: 3, in 2 groups of 1 accounts. Refused: 0.', $display );
		$this->assertStringContainsString( 'Nothing was deleted', $display );
	}

	public function testARefusedRowIsReportedAndFailsTheRun(): void
	{
		$this->pages    = [ [ self::group( 'a', [ self::file( 1, '/a.txt', 100 ), self::file( 2, '/Shared/a.txt', 200 ) ] ) ] ];
		$this->refusals = [ 2 => 'The share is read-only.' ];

		$this->assertSame( Command::FAILURE, $this->dryRun( [ '--user' => [ 'alice' ] ] ) );
		$this->assertStringContainsString( 'refused  [2] /Shared/a.txt: The share is read-only.', $this->tester->getDisplay() );
	}

	/** A group listed under two patterns is resolved once. */
	public function testGroupsListedPerPatternAreJoined(): void
	{
		$this->pages = [ [ self::group( 'a', [ self::file( 1, '/Backup/a.txt', 100 ), self::file( 2, '/Old/a.txt', 200 ), self::file( 3, '/Docs/a.txt', 300 ) ] ) ] ];

		$this->hashIndexService->expects( $this->exactly( 2 ) )
		                       ->method( 'listDuplicatesForUser' )
		;

		$this->assertSame( Command::SUCCESS, $this->dryRun( [ '--user' => [ 'alice' ], '--path' => [ '/Backup', '/Old' ] ] ) );
		$this->assertSame( [ 'sha1:a' => [ 1, 2 ] ], $this->deleted, 'outside the paths, /Docs/a.txt stays, so none needs keeping' );
	}

	/** One content under two algorithms: a file goes once, and what one group keeps stays in the other. */
	public function testAFileInTwoGroupsGoesOnceAndStaysWhereEitherKeepsIt(): void
	{
		$this->pages = [
			[
				self::group( 'a', [ self::file( 1, '/a.txt', 100 ), self::file( 2, '/b.txt', 200 ) ] ),
				self::group( 'c', [ self::file( 1, '/a.txt', 100 ), self::file( 2, '/b.txt', 200 ), self::file( 3, '/c.txt', 50 ) ], 'sha256' ),
			],
		];

		$this->dryRun( [ '--user' => [ 'alice' ] ] );

		$this->assertSame( [ 'sha1:a' => [ 2 ] ], $this->deleted, 'the sha1 group kept 1, the sha256 group 3; 2 goes once' );
	}

	public function testWithNoCopyToStayTheGroupsAreCounted(): void
	{
		$this->pages = [ [ self::group( 'a', [ self::file( 1, '/a.txt', 100 ), self::file( 2, '/b.txt', 200 ) ] ) ] ];

		$this->dryRun( [ '--user' => [ 'alice' ], '--remove-last' => true ] );

		$this->assertSame( [ 'sha1:a' => [ 1, 2 ] ], $this->deleted );
		$this->assertStringContainsString( 'Groups keeping no copy: 1.', $this->tester->getDisplay() );
	}

	public function testEveryAccountIsTakenInTurn(): void
	{
		$this->userManager->method( 'callForAllUsers' )
		                  ->willReturnCallback(
			                  function( \Closure $callback ): void
			                  {
				                  $callback( $this->user( 'alice' ) );
				                  $callback( $this->user( 'bob' ) );
			                  },
		                  )
		;

		$listed = [];
		$this->hashIndexService->method( 'listDuplicatesForUser' )
		                       ->willReturnCallback(
			                       static function( $uid ) use ( &$listed ): array
			                       {
				                       $listed[] = $uid;

				                       return [];
			                       },
		                       )
		;

		$this->dryRun( [ '--all' => true ] );

		$this->assertSame( [ 'alice', 'bob' ], $listed );
	}

	public function testTheJsonSaysPerGroupWhatWouldGoAndWhatStays(): void
	{
		$this->pages = [ [ self::group( 'a', [ self::file( 1, '/a.txt', 100 ), self::file( 2, '/b.txt', 200 ) ] ) ] ];

		$this->dryRun( [ '--user' => [ 'alice' ], '--output' => 'json' ] );

		$data  = json_decode( $this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR );
		$group = $data['accounts'][0]['groups'][0];

		$this->assertTrue( $data['dryRun'] );
		$this->assertSame( 'alice', $data['accounts'][0]['user'] );
		$this->assertSame( 1, $group['keeper'] );
		$this->assertSame( 1, $group['mergeInto'] );
		$this->assertSame( [ [ 'fileid' => 1, 'path' => '/a.txt' ] ], $group['stays'] );
		$this->assertSame( [ [ 'fileid' => 2, 'kind' => 'file', 'status' => 'ready', 'action' => 'trash', 'path' => '/b.txt' ] ], $group['results'] );
		$this->assertSame( [ 'accounts' => 1, 'groups' => 1, 'rows' => 1, 'refused' => 0, 'failed' => 0, 'keepsNone' => 0, 'declined' => 0 ], $data['summary'] );
		$this->assertArrayNotHasKey( 'going', $group, 'what goes is in the results' );
	}

	/** D2-A: with -y it acts at once, to the trash, and asks nothing. */
	public function testWithYesItDeletesWithoutAsking(): void
	{
		$this->pages = [ [ self::group( 'a', [ self::file( 1, '/a.txt', 100 ), self::file( 2, '/b.txt', 200 ) ] ) ] ];

		$this->assertSame( Command::SUCCESS, $this->tester->execute( [ '--user' => [ 'alice' ], '--yes' => true ] ) );
		$this->assertSame( [], $this->deleted, 'no dry run first' );
		$this->assertSame( [ 'sha1:a' => [ 2 ] ], $this->acted );

		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( 'trashed  [2] /b.txt', $display );
		$this->assertStringContainsString( 'Gone: 1, in 1 groups of 1 accounts. Refused: 0.', $display );
		$this->assertStringNotContainsString( '[y/N]', $display );
	}

	/** D2-A: on a terminal it shows what would go, asks, and acts on yes. */
	public function testOnATerminalItAsksFirst(): void
	{
		$this->pages = [ [ self::group( 'a', [ self::file( 1, '/a.txt', 100 ), self::file( 2, '/b.txt', 200 ) ] ) ] ];

		$this->tester->setInputs( [ 'y' ] );

		$this->assertSame( Command::SUCCESS, $this->tester->execute( [ '--user' => [ 'alice' ] ] ) );
		$this->assertStringContainsString( 'Delete 1 copy of alice\'s to the trash? [y/N]', $this->tester->getDisplay() );
		$this->assertSame( [ 'sha1:a' => [ 2 ] ], $this->deleted );
		$this->assertSame( [ 'sha1:a' => [ 2 ] ], $this->acted );
	}

	public function testAnsweredNoNothingGoesAndTheRunFails(): void
	{
		$this->pages = [ [ self::group( 'a', [ self::file( 1, '/a.txt', 100 ), self::file( 2, '/b.txt', 200 ) ] ) ] ];

		$this->tester->setInputs( [ 'n' ] );

		$this->assertSame( Command::FAILURE, $this->tester->execute( [ '--user' => [ 'alice' ] ] ) );
		$this->assertSame( [], $this->acted );
		$this->assertStringContainsString( 'Left as they were, the question answered no: 1 accounts.', $this->tester->getDisplay() );
	}

	/** D2-A: without a terminal the question answers no, and nothing goes. */
	public function testWithoutATerminalTheAnswerIsNo(): void
	{
		$this->pages = [ [ self::group( 'a', [ self::file( 1, '/a.txt', 100 ), self::file( 2, '/b.txt', 200 ) ] ) ] ];

		$this->assertSame( Command::FAILURE, $this->tester->execute( [ '--user' => [ 'alice' ] ], [ 'interactive' => false ] ) );
		$this->assertSame( [], $this->acted );

		$display = $this->tester->getDisplay();
		$this->assertStringNotContainsString( '[y/N]', $display );
		$this->assertStringContainsString( '-y deletes without asking', $display );
	}

	/** D3-A: --permanent deletes for good, and the question says so. */
	public function testPermanentDeletesForGood(): void
	{
		$this->pages = [ [ self::group( 'a', [ self::file( 1, '/a.txt', 100 ), self::file( 2, '/b.txt', 200 ) ] ) ] ];

		$this->tester->setInputs( [ 'y' ] );
		$this->tester->execute( [ '--user' => [ 'alice' ], '--permanent' => true ] );

		$this->assertStringContainsString( 'Delete 1 copy of alice\'s for good? [y/N]', $this->tester->getDisplay() );
		$this->assertStringContainsString( 'deleted  [2] /b.txt', $this->tester->getDisplay() );
		$this->assertSame( [ true ], array_values( array_unique( array_column( $this->calls, 'forGood' ) ) ) );
	}

	/** D4-A: the copy that stays takes the versions, the newest where several stay; --no-merge lets them go. */
	public function testTheCopyThatStaysTakesTheVersionsUnlessNoMerge(): void
	{
		$this->pages = [ [ self::group( 'a', [ self::file( 1, '/Docs/a.txt', 100 ), self::file( 2, '/Docs/b.txt', 300 ), self::file( 3, '/Backup/a.txt', 200 ) ] ) ] ];

		$this->tester->execute( [ '--user' => [ 'alice' ], '--path' => [ '/Backup' ], '--yes' => true ] );
		$this->assertSame( [ 2 ], array_column( $this->calls, 'mergeInto' ) );

		$this->calls = [];
		$this->tester->execute( [ '--user' => [ 'alice' ], '--path' => [ '/Backup' ], '--yes' => true, '--no-merge' => true ] );
		$this->assertSame( [ null ], array_column( $this->calls, 'mergeInto' ) );
	}

	public function testAFailedRowFailsTheRun(): void
	{
		$this->pages    = [ [ self::group( 'a', [ self::file( 1, '/a.txt', 100 ), self::file( 2, '/b.txt', 200 ) ] ) ] ];
		$this->failures = [ 2 ];

		$this->assertSame( Command::FAILURE, $this->tester->execute( [ '--user' => [ 'alice' ], '--yes' => true ] ) );
		$this->assertStringContainsString( 'failed   [2] /b.txt: The storage is read-only.', $this->tester->getDisplay() );
		$this->assertStringContainsString( 'Failed: 1.', $this->tester->getDisplay() );
	}

	/** Where the dry run found nothing that may go, there is nothing to ask about. */
	public function testARunWhoseEveryRowIsRefusedAsksNothing(): void
	{
		$this->pages    = [ [ self::group( 'a', [ self::file( 1, '/a.txt', 100 ), self::file( 2, '/b.txt', 200 ) ] ) ] ];
		$this->refusals = [ 2 => 'Its content changed after the list was loaded.' ];

		$this->tester->setInputs( [ 'y' ] );

		$this->assertSame( Command::FAILURE, $this->tester->execute( [ '--user' => [ 'alice' ] ] ) );
		$this->assertSame( [], $this->acted );

		$display = $this->tester->getDisplay();
		$this->assertStringNotContainsString( '[y/N]', $display );
		$this->assertStringNotContainsString( 'Left as they were', $display );
		$this->assertStringContainsString( 'refused  [2] /b.txt: Its content changed after the list was loaded.', $display );
	}

	public function testTheQuestionSaysWhereNoCopyWouldStay(): void
	{
		$this->pages = [ [ self::group( 'a', [ self::file( 1, '/a.txt', 100 ), self::file( 2, '/b.txt', 200 ) ] ) ] ];

		$this->tester->setInputs( [ 'n' ] );
		$this->tester->execute( [ '--user' => [ 'alice' ], '--remove-last' => true ] );

		$this->assertStringContainsString( 'Delete 2 copies of alice\'s to the trash, keeping no copy of 1 group? [y/N]', $this->tester->getDisplay() );
	}
}
