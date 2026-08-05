<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCA\FileChecksumSearch\Service\DuplicateService;
use OCA\FileChecksumSearch\Service\FileOperationService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\PendingQueueService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HashIndexServiceTest
	extends
	TestCase
{

	private MockObject|IDBConnection          $db;

	private MockObject|TableNameService       $tables;

	private MockObject|LifecycleHandler       $lifecycleHandler;

	private MockObject|HashCalculationService $hashCalc;

	private MockObject|PendingQueueService    $pendingQueue;

	private MockObject|DuplicateService       $duplicates;

	private MockObject|FileOperationService   $fileOps;

	private MockObject|IUserManager           $userManager;

	private MockObject|LoggerInterface        $logger;

	private HashIndexService                  $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->db               = $this->createMock( IDBConnection::class );
		$this->tables           = $this->createMock( TableNameService::class );
		$this->lifecycleHandler = $this->createMock( LifecycleHandler::class );
		$this->hashCalc         = $this->createMock( HashCalculationService::class );
		$this->pendingQueue     = $this->createMock( PendingQueueService::class );
		$this->duplicates       = $this->createMock( DuplicateService::class );
		$this->fileOps          = $this->createMock( FileOperationService::class );
		$this->userManager      = $this->createMock( IUserManager::class );
		$this->logger           = $this->createMock( LoggerInterface::class );

		$this->tables->method( 'getHashTableName' )
		             ->willReturn( 'oc_file_checksum_search_hashes' )
		;
		$this->tables->method( 'getPendingTableName' )
		             ->willReturn( 'oc_file_checksum_search_pending' )
		;

		$this->service = new HashIndexService(
			$this->db,
			$this->tables,
			$this->lifecycleHandler,
			$this->hashCalc,
			$this->pendingQueue,
			$this->duplicates,
			$this->fileOps,
			$this->userManager,
			$this->logger,
		);
	}


	public function testGetDefaultAlgoReturnsSha1(): void
	{

		$this->assertSame( 'sha1', HashIndexService::getDefaultAlgo() );
	}


	public function testSupportedAlgosContainsExpectedValues(): void
	{

		$this->assertContains( 'sha1', HashIndexService::SUPPORTED_ALGOS );
		$this->assertContains( 'sha256', HashIndexService::SUPPORTED_ALGOS );
		$this->assertContains( 'sha512', HashIndexService::SUPPORTED_ALGOS );
		$this->assertContains( 'md5', HashIndexService::SUPPORTED_ALGOS );
	}


	public function testRecalcHashDelegatesToHashCalc(): void
	{

		$expected = [
			'success' => false,
			'algo'    => 'sha1',
			'hash'    => '',
			'existed' => false,
			'error'   => 'File not found.',
		];

		$this->hashCalc->expects( $this->once() )
		               ->method( 'recalcHash' )
		               ->with( 42, 'sha1', true )
		               ->willReturn( $expected )
		;

		$result = $this->service->recalcHash( 42, 'sha1' );

		$this->assertSame( $expected, $result );
	}


	public function testFindByHashDelegatesToDuplicates(): void
	{

		$rows = [
			[
				'fileid'     => '42',
				'algo'       => 'sha1',
				'hash_value' => 'abc',
				'path'       => 'Docs',
				'name'       => 'f.pdf',
			],
		];

		$this->duplicates->expects( $this->once() )
		                 ->method( 'findByHash' )
		                 ->with( 'abc', null, 100 )
		                 ->willReturn( $rows )
		;

		$result = $this->service->findByHash( 'abc' );

		$this->assertCount( 1, $result );
		$this->assertSame( '42', $result[0]['fileid'] );
	}


	public function testFindByHashWithAlgoPassesFilter(): void
	{

		$this->duplicates->expects( $this->once() )
		                 ->method( 'findByHash' )
		                 ->with( 'abc', 'md5', 100 )
		                 ->willReturn( [] )
		;

		$result = $this->service->findByHash( 'abc', 'md5' );

		$this->assertEmpty( $result );
	}


	public function testBatchLookupFilecachePathsDelegates(): void
	{

		$expected = [
			42 => [
				'path'       => 'files/photo.jpg',
				'name'       => 'photo.jpg',
				'storage_id' => 'home::bob',
				'user'       => 'bob',
			],
		];

		$this->duplicates->expects( $this->once() )
		                 ->method( 'batchLookupFilecachePaths' )
		                 ->with( [ 42 ], 'bob' )
		                 ->willReturn( $expected )
		;

		$result = $this->service->batchLookupFilecachePaths( [ 42 ], 'bob' );

		$this->assertCount( 1, $result );
	}


	public function testDrainPendingOrchestratesQueueAndRecalc(): void
	{

		$pendingRows = [
			[ 'fileid' => 42, 'event_type' => 'write' ],
			[ 'fileid' => 108, 'event_type' => 'create' ],
		];

		$this->pendingQueue->expects( $this->once() )
		                   ->method( 'fetchPending' )
		                   ->with( 50 )
		                   ->willReturn( $pendingRows )
		;

		$this->hashCalc->expects( $this->exactly( 2 ) )
		               ->method( 'recalcHash' )
		               ->willReturn( [ 'success' => true ] )
		;

		$this->pendingQueue->expects( $this->once() )
		                   ->method( 'deletePending' )
		                   ->with( [ 42, 108 ] )
		                   ->willReturn( 2 )
		;

		$result = $this->service->drainPending( 50 );

		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 2, $result['deleted'] );
	}


	public function testFindAllDuplicatesDelegates(): void
	{

		$groups = [
			[
				'algo'       => 'sha1',
				'hash_value' => 'abc',
				'file_count' => 2,
				'fileids'    => [ 42, 108 ],
			],
		];

		$this->duplicates->expects( $this->once() )
		                 ->method( 'findAllDuplicates' )
		                 ->with( 'sha1', 2, 50, 0 )
		                 ->willReturn( $groups )
		;

		$result = $this->service->findAllDuplicates( 'sha1' );

		$this->assertCount( 1, $result );
	}


	public function testDeleteHashesDelegatesToFileOps(): void
	{

		$this->fileOps->expects( $this->once() )
		              ->method( 'deleteHashes' )
		              ->with( 42 )
		              ->willReturn( 3 )
		;

		$result = $this->service->deleteHashes( 42 );

		$this->assertSame( 3, $result );
	}


	public function testCountHashesDelegatesToFileOps(): void
	{

		$this->fileOps->expects( $this->once() )
		              ->method( 'countHashes' )
		              ->with( 42 )
		              ->willReturn( 5 )
		;

		$result = $this->service->countHashes( 42 );

		$this->assertSame( 5, $result );
	}


	public function testAddPendingDelegatesToPendingQueue(): void
	{

		$this->pendingQueue->expects( $this->once() )
		                   ->method( 'addPending' )
		                   ->with( 42, HashIndexService::EVENT_TYPE_WRITE )
		;

		$this->service->addPending( 42, HashIndexService::EVENT_TYPE_WRITE );
	}


	public function testCopyFilecacheChecksumDelegatesToFileOps(): void
	{

		$source = $this->createMock( File::class );
		$target = $this->createMock( File::class );

		$this->fileOps->expects( $this->once() )
		              ->method( 'copyFilecacheChecksum' )
		              ->with( $source, $target )
		;

		$this->service->copyFilecacheChecksum( $source, $target );
	}

}
