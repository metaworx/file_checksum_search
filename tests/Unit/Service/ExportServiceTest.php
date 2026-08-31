<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\FileChecksumSearch\Service\AppConfigService;
use OCA\FileChecksumSearch\Service\ExportService;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\State\Format\CsvFormat;
use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\JsonFormat;
use OCA\FileChecksumSearch\State\Format\SumFormat;
use OCA\FileChecksumSearch\State\HashRecord;
use OCP\App\IAppManager;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Composing the three slices into one backup document.
 */
class ExportServiceTest
	extends
	TestCase
{

	private AppConfigService&MockObject $appConfigService;

	private MetadataService&MockObject  $metadataService;

	private FilecacheService&MockObject $filecacheService;

	private ExportService               $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->appConfigService = $this->createMock( AppConfigService::class );
		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->filecacheService = $this->createMock( FilecacheService::class );

		$appManager = $this->createMock( IAppManager::class );
		$appManager->method( 'getAppVersion' )
		           ->willReturn( '0.19.0' )
		;

		$config = $this->createMock( IConfig::class );
		$config->method( 'getSystemValueString' )
		       ->willReturn( 'ocinstanceid' )
		;

		$this->service = new ExportService(
			$this->appConfigService,
			$this->metadataService,
			$this->filecacheService,
			$appManager,
			$config,
			$this->createMock( LoggerInterface::class ),
		);
	}


	public function testTheHeaderSaysWhatARestoreNeedsToCheck(): void
	{

		$header = $this->service->header( [ ExportService::SLICE_HASHES ] );

		$this->assertSame( '0.19.0', $header['app_version'] );
		$this->assertSame( 'ocinstanceid', $header['instance_id'] );
		$this->assertSame( [ ExportService::SLICE_HASHES ], $header['slices'] );
		$this->assertIsInt( $header['created_at'] );
	}


	/**
	 * One record per algorithm, each addressed by the storage and path the
	 * filecache knows it by rather than by a file id no other instance can
	 * make sense of.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testEachStoredAlgorithmBecomesItsOwnRecord(): void
	{

		$this->givenHashes(
			[
				[
					'file_id'    => 42,
					'hashes'     => [
						'sha256' => 'aaa',
						'md5'    => 'bbb',
					],
					'updated_at' => 1756400000,
				],
			],
		);
		$this->givenLocations(
			[
				42 => [
					'home::alice',
					'files/Photos/a.jpg',
				],
			],
		);

		$records = iterator_to_array( $this->service->hashRecords(), false );

		$this->assertEquals(
			[
				new HashRecord( 'home::alice', 'files/Photos/a.jpg', 'sha256', 'aaa', 1756400000 ),
				new HashRecord( 'home::alice', 'files/Photos/a.jpg', 'md5', 'bbb', 1756400000 ),
			],
			$records,
		);
	}


	/**
	 * A stored zero is this app's "never stamped": freshness is
	 * `updated_at >= mtime`, which zero can never satisfy. Exporting it as a
	 * number hands a restore a claim about 1970 instead of an absence.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnUnstampedHashExportsWithNoTimestampAtAll(): void
	{

		$this->givenHashes(
			[
				[
					'file_id'    => 1,
					'hashes'     => [ 'sha256' => 'aaa' ],
					'updated_at' => 0,
				],
			],
		);
		$this->givenLocations(
			[
				1 => [
					'home::alice',
					'files/a.txt',
				],
			],
		);

		$records = iterator_to_array( $this->service->hashRecords(), false );

		$this->assertNull( $records[0]->updatedAt );
	}


	/**
	 * Hashes describing a file that is no longer in the filecache describe
	 * nothing, and a record with no path is not importable anywhere.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFileWithNoFilecacheRowIsSkipped(): void
	{

		$this->givenHashes(
			[
				[
					'file_id'    => 1,
					'hashes'     => [ 'sha256' => 'aaa' ],
					'updated_at' => 1,
				],
				[
					'file_id'    => 2,
					'hashes'     => [ 'sha256' => 'bbb' ],
					'updated_at' => 2,
				],
			],
		);
		$this->givenLocations(
			[
				2 => [
					'home::bob',
					'files/b.txt',
				],
			],
		);

		$records = iterator_to_array( $this->service->hashRecords(), false );

		$this->assertCount( 1, $records );
		$this->assertSame( 'files/b.txt', $records[0]->path );
	}


	/**
	 * Identities are resolved a page at a time — one query per file would
	 * make an export of a large instance a few hundred thousand round trips.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testIdentitiesAreResolvedInBatchesNotOneByOne(): void
	{

		$entries   = [];
		$locations = [];

		for ( $fileId = 1; $fileId <= 1200; $fileId ++ )
		{
			$entries[]            = [
				'file_id'    => $fileId,
				'hashes'     => [ 'sha256' => 'h' . $fileId ],
				'updated_at' => null,
			];
			$locations[ $fileId ] = [
				'home::alice',
				'files/f' . $fileId . '.txt',
			];
		}

		$this->givenHashes( $entries );

		$calls = 0;
		$this->filecacheService->method( 'locateAll' )
		                       ->willReturnCallback(
			                       function (
				                       array $fileIds,
			                       ) use
			                       (
				                       $locations,
				                       &
				                       $calls,
			                       ): array
			                       {

				                       $calls ++;

				                       return $this->locationsFor( $fileIds, $locations );
			                       },
		                       )
		;

		$this->assertCount( 1200, iterator_to_array( $this->service->hashRecords(), false ) );
		$this->assertSame( 3, $calls, '1200 files at 500 per page' );
	}


	/** @noinspection PhpUnhandledExceptionInspection */
	public function testStatusRowsCarryTheIdentityAndTheReason(): void
	{

		$this->metadataService->method( 'exportStates' )
		                      ->willReturnCallback(
			                      static function (): \Generator
			                      {

				                      yield [
					                      'file_id' => 7,
					                      'state'   => 'pending:auto',
				                      ];
				                      yield [
					                      'file_id' => 8,
					                      'state'   => MetadataService::STATE_RESET,
				                      ];
			                      },
		                      )
		;
		$this->givenLocations(
			[
				7 => [
					'home::alice',
					'files/a.txt',
				],
				8 => [
					'home::alice',
					'files/b.txt',
				],
			],
		);

		$count = 0;
		$rows  = iterator_to_array( $this->service->statusRows( $count ), false );

		$this->assertSame(
			[
				[
					'storage' => 'home::alice',
					'path'    => 'files/a.txt',
					'state'   => 'pending:auto',
				],
				[
					'storage' => 'home::alice',
					'path'    => 'files/b.txt',
					'state'   => 'stale:reset',
				],
			],
			$rows,
		);
		$this->assertSame( 2, $count );
	}


	/**
	 * A hash table has nowhere to put configuration or queue state, and
	 * writing one that silently dropped a slice the caller asked for would
	 * be the worst of the three outcomes.
	 *
	 * @dataProvider hashOnlyFormats
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAHashOnlyFormatRefusesTheOtherSlices( string $class ): void
	{

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/hashes only/' );

		$stream = fopen( 'php://memory', 'r+' );

		try
		{
			$this->service->export(
				new $class(),
				[
					ExportService::SLICE_CONFIG,
					ExportService::SLICE_HASHES,
				],
				$stream,
				new FormatOptions( algo: 'sha256' ),
			);
		}
		finally
		{
			fclose( $stream );
		}
	}


	/**
	 * @return array<string, array{class-string}>
	 */
	public static function hashOnlyFormats(): array
	{

		return [
			'csv' => [ CsvFormat::class ],
			'sum' => [ SumFormat::class ],
		];
	}


	/** @noinspection PhpUnhandledExceptionInspection */
	public function testAHashOnlyFormatWritesTheHashesItWasAskedFor(): void
	{

		$this->givenHashes(
			[
				[
					'file_id'    => 1,
					'hashes'     => [ 'sha256' => 'aaa' ],
					'updated_at' => 5,
				],
			],
		);
		$this->givenLocations(
			[
				1 => [
					'home::alice',
					'files/a.txt',
				],
			],
		);

		$stream = fopen( 'php://memory', 'r+' );
		$counts = $this->service->export(
			new CsvFormat(),
			[ ExportService::SLICE_HASHES ],
			$stream,
			new FormatOptions(),
		);
		rewind( $stream );
		$text = stream_get_contents( $stream );
		fclose( $stream );

		$this->assertSame( 1, $counts[ ExportService::SLICE_HASHES ] );
		$this->assertStringContainsString( 'home::alice,files/a.txt,sha256,aaa,5', $text );
	}


	/**
	 * The whole backup document, and the counts an operator reads afterwards.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAFullBackupCarriesEverySliceAndCountsIt(): void
	{

		$this->appConfigService->method( 'export' )
		                       ->willReturn(
			                       [
				                       'rule_definitions'         => '[]',
				                       'rule_processing_interval' => '300',
			                       ],
		                       )
		;
		$this->givenHashes(
			[
				[
					'file_id'    => 1,
					'hashes'     => [
						'sha256' => 'aaa',
						'md5'    => 'bbb',
					],
					'updated_at' => 5,
				],
			],
		);
		$this->metadataService->method( 'exportStates' )
		                      ->willReturnCallback(
			                      static function (): \Generator
			                      {

				                      yield [
					                      'file_id' => 1,
					                      'state'   => 'pending:auto',
				                      ];
			                      },
		                      )
		;
		$this->givenLocations(
			[
				1 => [
					'home::alice',
					'files/a.txt',
				],
			],
		);

		$stream = fopen( 'php://memory', 'r+' );
		$counts = $this->service->export(
			new JsonFormat(),
			ExportService::SLICES,
			$stream,
			new FormatOptions(),
		);
		rewind( $stream );
		$decoded = json_decode( stream_get_contents( $stream ), true );
		fclose( $stream );

		$this->assertSame(
			[
				ExportService::SLICE_CONFIG => 2,
				ExportService::SLICE_STATUS => 1,
				ExportService::SLICE_HASHES => 2,
			],
			$counts,
		);
		$this->assertSame( ExportService::SLICES, $decoded['slices'] );
		$this->assertSame( '0.19.0', $decoded['app_version'] );
		$this->assertCount( 2, $decoded['config'] );
		$this->assertSame( 'pending:auto', $decoded['status'][0]['state'] );
		$this->assertCount( 2, $decoded['hashes'] );
	}


	/** @noinspection PhpUnhandledExceptionInspection */
	public function testASliceLeftOutIsAbsentRatherThanEmpty(): void
	{

		$this->givenHashes( [] );

		$stream = fopen( 'php://memory', 'r+' );
		$counts = $this->service->export(
			new JsonFormat(),
			[ ExportService::SLICE_HASHES ],
			$stream,
			new FormatOptions(),
		);
		rewind( $stream );
		$decoded = json_decode( stream_get_contents( $stream ), true );
		fclose( $stream );

		$this->assertArrayNotHasKey( 'config', $decoded );
		$this->assertArrayNotHasKey( 'status', $decoded );
		$this->assertSame( [], $decoded['hashes'] );

		// And the report says nothing about slices nobody asked for — "config
		// 0" reads as an empty backup rather than a narrow one.
		$this->assertSame( [ ExportService::SLICE_HASHES => 0 ], $counts );
	}


	/**
	 * @param  list<array{file_id: int, hashes: array<string, string>, updated_at: ?int}>  $entries
	 */
	private function givenHashes( array $entries ): void
	{

		$this->metadataService->method( 'exportHashes' )
		                      ->willReturnCallback(
			                      static function () use
			                      (
				                      $entries,
			                      ): \Generator
			                      {

				                      yield from $entries;
			                      },
		                      )
		;
	}


	/**
	 * @param  array<int, array{0: string, 1: string}>  $locations
	 */
	private function givenLocations( array $locations ): void
	{

		$this->filecacheService->method( 'locateAll' )
		                       ->willReturnCallback(
			                       fn(
				                       array $fileIds,
			                       ): array => $this->locationsFor( $fileIds, $locations ),
		                       )
		;
	}


	/**
	 * @param  list<int>                                $fileIds
	 * @param  array<int, array{0: string, 1: string}>  $locations
	 *
	 * @return array<int, FileLocation>
	 */
	private function locationsFor(
		array $fileIds,
		array $locations,
	): array {

		$found = [];

		foreach ( $fileIds as $fileId )
		{
			if ( ! isset( $locations[ $fileId ] ) )
			{
				continue;
			}

			$found[ $fileId ] = FileLocation::fromRow(
				$fileId,
				$locations[ $fileId ][0],
				$locations[ $fileId ][1],
				100,
			);
		}

		return $found;
	}

}
