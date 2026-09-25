<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\AppConfigService;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\ImportService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\State\Format\CsvFormat;
use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\JsonFormat;
use OCA\FileChecksumSearch\State\Format\SumFormat;
use OCA\FileChecksumSearch\State\ImportPolicy;
use OCA\FileChecksumSearch\State\ImportReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reading a backup document is the easy half. The half that matters is
 * deciding what may be written: a hash stamped later than the content it
 * describes is invisible to every correction path this app has, so an
 * import that is too willing does damage nothing later notices.
 */
class ImportServiceTest
    extends
    TestCase
{

//  constants

	/** The file's mtime in every fixture below. */
	private const MTIME = 1_000;


//  private properties

	private AppConfigService&MockObject $appConfigService;

	private MetadataService&MockObject  $metadataService;

	private FilecacheService&MockObject $filecacheService;

	private ImportService               $service;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->appConfigService = $this->createMock( AppConfigService::class );
		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->filecacheService = $this->createMock( FilecacheService::class );

		$this->service = new ImportService(
			$this->appConfigService,
			$this->metadataService,
			$this->filecacheService,
			$this->createMock( LoggerInterface::class ),
		);

		$this->givenTheInstanceHas( [ 'files/a.txt' => 7 ] );
	}


//  other non-static methods

	/**
	 * A backup names one file once per algorithm; writing them separately
	 * would rewrite the same metadata document three times and ask the same
	 * freshness question three times over.
	 */
	public function testEveryAlgorithmOfOneFileIsWrittenInOneGo(): void
	{
		$this->metadataService->expects( $this->once() )
		                      ->method( 'writeHashes' )
		                      ->with(
			                      7,
			                      [
				                      'sha256' => 'aaa',
				                      'md5'    => 'bbb',
			                      ],
			                      2_000,
			                      false,
		                      )
		                      ->willReturn( $this->writeResult( written: 2 ) )
		;

		$report = $this->importJson(
			[
				$this->record( 'files/a.txt', 'sha256', 'aaa', 2_000 ),
				$this->record( 'files/a.txt', 'md5', 'bbb', 2_000 ),
			],
			new ImportPolicy( merge: false ),
		);

		$this->assertSame( 2, $report->written );
	}

	/**
	 * The one that matters. Freshness is `updated_at >= mtime`, so a hash
	 * stamped before the file was last written describes something the file
	 * no longer is — and storing it would make the file permanently
	 * un-correctable, because nothing ever looks at a hash that claims to be
	 * current.
	 */
	public function testAHashOlderThanItsFileIsRefused(): void
	{
		$this->metadataService->expects( $this->never() )
		                      ->method( 'writeHashes' )
		;

		$report = $this->importJson(
			[ $this->record( 'files/a.txt', 'sha256', 'aaa', self::MTIME - 1 ) ],
			new ImportPolicy(),
		);

		$this->assertSame( 1, $report->skippedOutdated );
		$this->assertSame( 0, $report->written );
	}

	public function testAHashAsNewAsItsFileIsAccepted(): void
	{
		$this->metadataService->expects( $this->once() )
		                      ->method( 'writeHashes' )
		                      ->with( 7, $this->anything(), self::MTIME, $this->anything() )
		                      ->willReturn( $this->writeResult( written: 1 ) )
		;

		$this->importJson(
			[ $this->record( 'files/a.txt', 'sha256', 'aaa', self::MTIME ) ],
			new ImportPolicy(),
		);
	}

	/**
	 * At the operator's insistence, and only theirs.
	 */
	public function testAllowStaleTakesWhatSourceWouldRefuse(): void
	{
		$this->metadataService->expects( $this->once() )
		                      ->method( 'writeHashes' )
		                      ->with( 7, $this->anything(), self::MTIME - 1, $this->anything() )
		                      ->willReturn( $this->writeResult( written: 1 ) )
		;

		$report = $this->importJson(
			[ $this->record( 'files/a.txt', 'sha256', 'aaa', self::MTIME - 1 ) ],
			new ImportPolicy( allowOutdated: true ),
		);

		$this->assertSame( 0, $report->skippedOutdated );
	}

	/**
	 * A source that did not say when it hashed has told us nothing to weigh,
	 * so the file's own mtime is the most the import can honestly claim —
	 * the same claim `backfillHashes()` already makes.
	 */
	public function testARecordWithNoStampFallsBackToTheFilesOwnMtime(): void
	{
		$this->metadataService->expects( $this->once() )
		                      ->method( 'writeHashes' )
		                      ->with( 7, $this->anything(), self::MTIME, $this->anything() )
		                      ->willReturn( $this->writeResult( written: 1 ) )
		;

		$this->importJson(
			[ $this->record( 'files/a.txt', 'sha256', 'aaa', null ) ],
			new ImportPolicy(),
		);
	}

	/**
	 * @dataProvider stampPolicies
	 */
	public function testTheStampPolicyDecidesWhatIsStored(
		string $stamp,
		?int   $recorded,
		string $expectation,
	): void
	{
		$stored = null;
		$this->metadataService->method( 'writeHashes' )
		                      ->willReturnCallback(
			                      function(
				                      int   $fileId,
				                      array $hashes,
				                      ?int  $value,
			                      ) use
			                      (
				                      &
				                      $stored,
			                      ): array
			                      {
				                      $stored = $value;

				                      return $this->writeResult( written: 1 );
			                      },
		                      )
		;

		$this->importJson(
			[ $this->record( 'files/a.txt', 'sha256', 'aaa', $recorded ) ],
			new ImportPolicy( stamp: $stamp, allowOutdated: true ),
		);

		match ( $expectation )
		{
			'recorded' => $this->assertSame( $recorded, $stored ),
			'mtime'    => $this->assertSame( self::MTIME, $stored ),
			default    => $this->assertGreaterThan( self::MTIME, $stored ),
		};
	}


//  static methods

	/**
	 * @return array<string, array{string, int|null, string}>
	 */
	public static function stampPolicies(): array
	{
		return [
			'source keeps what the record said' => [
				ImportPolicy::STAMP_SOURCE,
				2_000,
				'recorded',
			],
			'mtime claims the file as it is'    => [
				ImportPolicy::STAMP_MTIME,
				2_000,
				'mtime',
			],
			'now claims this moment'            => [
				ImportPolicy::STAMP_NOW,
				2_000,
				'now',
			],
		];
	}

	/**
	 * An import restores what a file *is*, never that it exists.
	 */
	public function testAPathThisInstanceDoesNotHaveIsCountedAndSkipped(): void
	{
		$this->metadataService->expects( $this->never() )
		                      ->method( 'writeHashes' )
		;

		$report = $this->importJson(
			[ $this->record( 'files/gone.txt', 'sha256', 'aaa', 2_000 ) ],
			new ImportPolicy(),
		);

		$this->assertSame( 1, $report->unknownPath );
	}

	public function testStrictStopsAtTheFirstUnknownPath(): void
	{
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/No such file/' );

		$this->importJson(
			[ $this->record( 'files/gone.txt', 'sha256', 'aaa', 2_000 ) ],
			new ImportPolicy( strict: true ),
		);
	}

	public function testADryRunWritesNothingAndStillCounts(): void
	{
		$this->metadataService->expects( $this->never() )
		                      ->method( 'writeHashes' )
		;
		$this->appConfigService->expects( $this->never() )
		                       ->method( 'import' )
		;

		$report = $this->importJson(
			[ $this->record( 'files/a.txt', 'sha256', 'aaa', 2_000 ) ],
			new ImportPolicy( dryRun: true ),
			[ 'rule_definitions' => '[]' ],
		);

		$this->assertSame( 1, $report->written );
		$this->assertSame( 1, $report->configWritten );
	}

	/** @noinspection PhpUnhandledExceptionInspection */
	public function testAMalformedRecordIsCountedNotWritten(): void
	{
		$stream = $this->streamOf( '{"schema":1,"hashes":[{"storage":"home::alice","path":"files/a.txt"}]}' );

		$report = $this->service->import(
			new JsonFormat(),
			$stream,
			new FormatOptions(),
			new ImportPolicy(),
			false,
			true,
		);
		fclose( $stream );

		$this->assertSame( 1, $report->malformed );
		$this->assertSame( 0, $report->written );
	}

	/**
	 * A backup document from a future version may mean something different
	 * by the same field, and half-applying it is worse than refusing it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testABackupFromAFutureSchemaIsRefused(): void
	{
		$stream = $this->streamOf(
			'{"schema":' . ( JsonFormat::SCHEMA_VERSION + 1 ) . ',"hashes":[]}',
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Upgrade the app first/' );

		try
		{
			$this->service->import(
				new JsonFormat(),
				$stream,
				new FormatOptions(),
				new ImportPolicy(),
				false,
				true,
			);
		}
		finally
		{
			fclose( $stream );
		}
	}

	/**
	 * @dataProvider hashOnlyFormats
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAHashOnlyFormatCannotCarryConfiguration( string $class ): void
	{
		$stream = $this->streamOf( '' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/holds hashes alone/' );

		try
		{
			$this->service->import(
				new $class(),
				$stream,
				new FormatOptions( algo: 'sha256' ),
				new ImportPolicy(),
				true,
				false,
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

	/**
	 * `--replace` is what restoring a backup means, and it is the same word
	 * for the configuration: the result is exactly what the file holds.
	 */
	public function testReplacingConfigRemovesWhatTheBackupDoesNotName(): void
	{
		$this->appConfigService->expects( $this->once() )
		                       ->method( 'import' )
		                       ->with( [ 'rule_definitions' => '[]' ], true )
		                       ->willReturn(
			                       [
				                       'written'      => 1,
				                       'skipped'      => [ 'from_a_newer_version' ],
				                       'not_portable' => [ 'stats_rule_sweep_last_run' ],
			                       ],
		                       )
		;

		$report = $this->importJson(
			[],
			new ImportPolicy( merge: false ),
			[ 'rule_definitions' => '[]' ],
		);

		$this->assertSame( 1, $report->configWritten );
		$this->assertSame( [ 'from_a_newer_version' ], $report->configRefused );
		$this->assertSame( [ 'stats_rule_sweep_last_run' ], $report->configNotPortable );
	}

	public function testWritingHashesClearsAStaleMarker(): void
	{
		$this->metadataService->method( 'writeHashes' )
		                      ->willReturn( $this->writeResult( written: 1, markerCleared: true ) )
		;

		$report = $this->importJson(
			[ $this->record( 'files/a.txt', 'sha256', 'aaa', 2_000 ) ],
			new ImportPolicy(),
		);

		$this->assertSame( 1, $report->markerCleared );
	}

	/**
	 * @param  array<int, array<string, mixed>>  $records
	 * @param  array<string, string>             $config
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function importJson(
		array        $records,
		ImportPolicy $policy,
		array        $config = [],
	): ImportReport
	{
		$document = [
			'schema' => JsonFormat::SCHEMA_VERSION,
		];

		if ( $config !== [] )
		{
			$document['config'] = $config;
		}

		$document['hashes'] = $records;

		$stream = $this->streamOf( json_encode( $document ) );

		$report = $this->service->import(
			new JsonFormat(),
			$stream,
			new FormatOptions(),
			$policy,
			$config !== [],
			true,
		);
		fclose( $stream );

		return $report;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function record(
		string $path,
		string $algo,
		string $hash,
		?int   $updatedAt,
	): array
	{
		return [
			'storage'    => 'home::alice',
			'path'       => $path,
			'algo'       => $algo,
			'hash'       => $hash,
			'updated_at' => $updatedAt,
		];
	}

	/**
	 * @return array{written: int, overwritten: int, skipped: int, markerCleared: bool}
	 */
	private function writeResult(
		int  $written = 0,
		bool $markerCleared = false,
	): array
	{
		return [
			'written'       => $written,
			'overwritten'   => 0,
			'skipped'       => 0,
			'markerCleared' => $markerCleared,
		];
	}

	/**
	 * @param  array<string, int>  $pathToFileId
	 *
	 * @noinspection PhpSameParameterValueInspection
	 */
	private function givenTheInstanceHas( array $pathToFileId ): void
	{
		$this->filecacheService->method( 'locateAllByPath' )
		                       ->willReturnCallback(
			                       static function(
				                       array $pathsByStorage,
			                       ) use
			                       (
				                       $pathToFileId,
			                       ): array
			                       {
				                       $found = [];

				                       foreach ( $pathsByStorage as $storageId => $paths )
				                       {
					                       foreach ( $paths as $path )
					                       {
						                       if ( ! isset( $pathToFileId[ $path ] ) )
						                       {
							                       continue;
						                       }

						                       $found[ FilecacheService::identityKey( (string) $storageId, $path ) ]
							                       = FileLocation::fromRow(
								                       $pathToFileId[ $path ],
								                       (string) $storageId,
								                       $path,
								                       self::MTIME,
							                       );
					                       }
				                       }

				                       return $found;
			                       },
		                       )
		;
	}

	/**
	 * @return resource
	 */
	private function streamOf( string $text )
	{
		$stream = fopen( 'php://memory', 'r+' );
		fwrite( $stream, $text );
		rewind( $stream );

		return $stream;
	}
}
