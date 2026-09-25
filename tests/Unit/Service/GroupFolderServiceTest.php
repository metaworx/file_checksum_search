<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\GroupFolderService;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The soft-dependency contract: nothing here may fail — or even touch the
 * database — when the groupfolders app is absent.
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

		$this->service = new GroupFolderService(
			$this->appManager,
			$this->db,
			$this->logger,
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
}
