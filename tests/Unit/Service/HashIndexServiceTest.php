<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\DuplicateService;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class HashIndexServiceTest
	extends
	FciasUnitTestCase
{

	private MockObject|HashCalculationService $hashCalc;

	private MockObject|DuplicateService       $duplicates;

	private MockObject|MetadataService        $metadataService;

	private MockObject|FilecacheService       $filecacheService;

	private HashIndexService                  $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->hashCalc         = $this->createMock( HashCalculationService::class );
		$this->duplicates       = $this->createMock( DuplicateService::class );
		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->filecacheService = $this->createMock( FilecacheService::class );

		$this->service = new HashIndexService(
			$this->hashCalc,
			$this->duplicates,
			$this->metadataService,
			$this->filecacheService,
		);
	}


	public function testGetDefaultAlgoReturnsSha1(): void
	{

		$this->assertSame( 'sha1', HashCalculationService::getDefaultAlgo() );
	}


	public function testSupportedAlgosContainsExpectedValues(): void
	{

		$algos = HashCalculationService::SUPPORTED_ALGOS;

		$this->assertContains( 'sha1', $algos );
		$this->assertContains( 'sha256', $algos );
		$this->assertContains( 'sha512', $algos );
		$this->assertContains( 'adler32', $algos );
	}


	public function testRecalcHashDelegatesToHashCalc(): void
	{

		$this->hashCalc->expects( $this->once() )
		               ->method( 'recalcHash' )
		               ->with( 42, 'sha256', true )
		               ->willReturn(
			               [
				               'success' => true,
				               'algo'    => 'sha256',
				               'hash'    => 'abc',
				               'existed' => false,
			               ],
		               )
		;

		$result = $this->service->recalcHash( 42, 'sha256' );

		$this->assertTrue( $result['success'] );
	}


	public function testFindByHashDelegatesToDuplicates(): void
	{

		$this->duplicates->expects( $this->once() )
		                 ->method( 'findByHash' )
		                 ->with( 'abc123', null, 100 )
		                 ->willReturn( [] )
		;

		$result = $this->service->findByHash( 'abc123' );

		$this->assertIsArray( $result );
	}


	public function testFindByHashWithAlgoPassesFilter(): void
	{

		$this->duplicates->expects( $this->once() )
		                 ->method( 'findByHash' )
		                 ->with( 'abc123', 'sha256', 50 )
		                 ->willReturn( [] )
		;

		$result = $this->service->findByHash( 'abc123', 'sha256', 50 );

		$this->assertIsArray( $result );
	}


	public function testFindAllDuplicatesDelegates(): void
	{

		$groups = [
			[
				'algo'       => 'sha1',
				'hash_value' => 'abc',
				'file_count' => 2,
				'fileids'    => [
					42,
					108,
				],
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


	public function testCountHashesDelegatesToMetadata(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'countByFileId' )
		                      ->with( 42 )
		                      ->willReturn( 5 )
		;

		$result = $this->service->countHashes( 42 );

		$this->assertSame( 5, $result );
	}


	public function testDeleteHashesClearsMetadata(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearMetadata' )
		                      ->with( 42 )
		;

		$result = $this->service->deleteHashes( 42 );

		$this->assertSame( 1, $result );
	}


	public function testGenerateMissingHashesWithPathPattern(): void
	{

		$this->hashCalc->expects( $this->once() )
		               ->method( 'generateMissingHashes' )
		               ->with( 'alice', 'sha256', '**/*.jpg', 200, null )
		               ->willReturn(
			               [
				               'processed' => 5,
				               'skipped'   => 2,
				               'errors'    => 0,
			               ],
		               )
		;

		$result = $this->service->generateMissingHashes(
			'alice',
			'sha256',
			'**/*.jpg',
			200,
		);

		$this->assertSame(
			[
				'processed' => 5,
				'skipped'   => 2,
				'errors'    => 0,
			],
			$result,
		);
	}

}
