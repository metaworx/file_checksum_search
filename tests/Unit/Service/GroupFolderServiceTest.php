<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\GroupFolderService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The soft-dependency contract: nothing here may fail — or even touch the
 * database — when the groupfolders app is absent; and where its table
 * cannot say which folders have advanced permissions, that is said as
 * unknown, never as none.
 */
class GroupFolderServiceTest
    extends
    TestCase
{

//  private properties

	private MockObject|IAppManager     $appManager;

	private MockObject|IDBConnection   $db;

	private MockObject|LoggerInterface $logger;

	private GroupFolderService         $service;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->appManager = $this->createMock( IAppManager::class );
		$this->db         = $this->createMock( IDBConnection::class );
		$this->logger     = $this->createMock( LoggerInterface::class );

		// The groupfolders app's own catalogue, answering in English unless
		// a test says otherwise.
		$groupFoldersL10n = $this->createMock( IL10N::class );
		$groupFoldersL10n->method( 'getLanguageCode' )
		                 ->willReturn( 'en' )
		;
		$groupFoldersL10n->method( 't' )
		                 ->willReturnArgument( 0 )
		;

		$l10nFactory = $this->createMock( IFactory::class );
		$l10nFactory->method( 'get' )
		            ->with( 'groupfolders' )
		            ->willReturn( $groupFoldersL10n )
		;

		$this->service = new GroupFolderService(
			$this->appManager,
			$this->db,
			$this->logger,
			$l10nFactory,
		);
	}


//  other non-static methods

	public function testUnavailableWhenTheAppIsDisabled(): void
	{
		$this->appManager->method( 'isEnabledForUser' )
		                 ->with( 'groupfolders' )
		                 ->willReturn( false )
		;

		// The database is not even consulted — the app's absence answers.
		$this->db->expects( $this->never() )
		         ->method( 'getQueryBuilder' )
		;

		$this->assertFalse( $this->service->isAvailable() );
		$this->assertSame( [], $this->service->listFolders() );
	}

	public function testUnavailableWhenTheAppManagerItselfFails(): void
	{
		$this->appManager->method( 'isEnabledForUser' )
		                 ->willThrowException( new \RuntimeException( 'no app manager today' ) )
		;

		$this->assertFalse( $this->service->isAvailable() );
	}

	public function testAppNameComesFromTheAppItself(): void
	{
		$this->appManager->method( 'isEnabledForUser' )
		                 ->willReturn( true )
		;
		$this->appManager->method( 'getAppInfo' )
		                 ->with( 'groupfolders' )
		                 ->willReturn( [ 'name' => 'Team Folders' ] )
		;

		$this->assertSame( 'Team Folders', $this->service->appName() );
	}

	/**
	 * The reader's language, from the groupfolders app itself: its manifest
	 * asked in that language, its name through its own catalogue, as core's
	 * navigation names an app.
	 */
	public function testAppNameIsTranslatedByTheAppItself(): void
	{
		$groupFoldersL10n = $this->createMock( IL10N::class );
		$groupFoldersL10n->method( 'getLanguageCode' )
		                 ->willReturn( 'de' )
		;
		$groupFoldersL10n->method( 't' )
		                 ->willReturnMap( [ [ 'Team Folders', [], 'Team-Ordner' ] ] )
		;

		$l10nFactory = $this->createMock( IFactory::class );
		$l10nFactory->method( 'get' )
		            ->with( 'groupfolders' )
		            ->willReturn( $groupFoldersL10n )
		;

		$this->appManager->method( 'isEnabledForUser' )
		                 ->willReturn( true )
		;
		$this->appManager->expects( $this->once() )
		                 ->method( 'getAppInfo' )
		                 ->with( 'groupfolders', false, 'de' )
		                 ->willReturn( [ 'name' => 'Team Folders' ] )
		;

		$service = new GroupFolderService( $this->appManager, $this->db, $this->logger, $l10nFactory );

		$this->assertSame( 'Team-Ordner', $service->appName() );
	}

	public function testAppNameIsNullWhenTheAppIsAbsent(): void
	{
		$this->appManager->method( 'isEnabledForUser' )
		                 ->willReturn( false )
		;

		// There is no app to ask — the caller decides how to name what is
		// missing.
		$this->assertNull( $this->service->appName() );
	}

	public function testASchemaSurpriseYieldsAnEmptyListNotAnError(): void
	{
		$this->appManager->method( 'isEnabledForUser' )
		                 ->willReturn( true )
		;

		// An enabled app whose tables are not what this release expects —
		// a picker with nothing in it, never an exception.
		$this->db->method( 'getQueryBuilder' )
		         ->willThrowException( new \RuntimeException( 'schema mismatch' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'debug' )
		;

		$this->assertSame( [], $this->service->listFolders() );
	}

	// ─── aclRoots: where the folders with advanced permissions are ───
	/**
	 * Without the app there are no such folders, and the database is not
	 * asked.
	 */
	public function testNoAclRootsWithoutTheApp(): void
	{
		$this->appManager->method( 'isEnabledForUser' )
		                 ->willReturn( false )
		;

		$this->db->expects( $this->never() )
		         ->method( 'getQueryBuilder' )
		;

		$this->assertSame( [], $this->service->aclRoots() );
	}

	/**
	 * Each folder's root as a mount has it, its slashes trimmed; a root the
	 * filecache does not hold has nothing below it, and is left out.
	 */
	public function testAclRootsAreEachFoldersRootAsStorageAndPath(): void
	{
		$this->appManager->method( 'isEnabledForUser' )
		                 ->willReturn( true )
		;
		$this->rowsOf( [
			[ 'root_id' => 500, 'storage' => 3, 'path' => '__groupfolders/5' ],
			[ 'root_id' => 600, 'storage' => 7, 'path' => '/files/' ],
			[ 'root_id' => 700, 'storage' => null, 'path' => null ],
		] );

		$this->assertSame(
			[
				[ 'storage' => 3, 'root' => '__groupfolders/5' ],
				[ 'storage' => 7, 'root' => 'files' ],
			],
			$this->service->aclRoots(),
		);
	}

	/**
	 * A folder named by no root, or a table that cannot be read: where the
	 * folders are is unknown, which is null and a warning — never an empty
	 * list, which would say there are none.
	 */
	public function testAclRootsAreUnknownWhereTheTableCannotTellThem(): void
	{
		$this->appManager->method( 'isEnabledForUser' )
		                 ->willReturn( true )
		;
		$this->rowsOf( [
			[ 'root_id' => 500, 'storage' => 3, 'path' => '__groupfolders/5' ],
			[ 'root_id' => null, 'storage' => null, 'path' => null ],
		] );
		$this->logger->expects( $this->once() )
		             ->method( 'warning' )
		;

		$this->assertNull( $this->service->aclRoots() );
	}

	public function testAclRootsAreUnknownWhereTheQueryFails(): void
	{
		$this->appManager->method( 'isEnabledForUser' )
		                 ->willReturn( true )
		;
		$this->db->method( 'getQueryBuilder' )
		         ->willThrowException( new \RuntimeException( 'schema mismatch' ) )
		;
		$this->logger->expects( $this->once() )
		             ->method( 'warning' )
		;

		$this->assertNull( $this->service->aclRoots() );
	}

	/**
	 * The database answering with $rows, to any query.
	 *
	 * @param  list<array<string, mixed>>  $rows
	 */
	private function rowsOf( array $rows ): void
	{
		$result = $this->createMock( IResult::class );
		$result->method( 'fetchAssociative' )
		       ->willReturnOnConsecutiveCalls( ...[ ...$rows, false ] )
		;

		$qb = $this->createMock( IQueryBuilder::class );
		$qb->method( 'select' )->willReturnSelf();
		$qb->method( 'from' )->willReturnSelf();
		$qb->method( 'leftJoin' )->willReturnSelf();
		$qb->method( 'where' )->willReturnSelf();
		$qb->method( 'expr' )->willReturn( $this->createMock( IExpressionBuilder::class ) );
		$qb->method( 'createNamedParameter' )->willReturn( ':acl' );
		$qb->method( 'executeQuery' )->willReturn( $result );

		$this->db->method( 'getQueryBuilder' )
		         ->willReturn( $qb )
		;
	}
}
