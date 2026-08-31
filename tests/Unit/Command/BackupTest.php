<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use InvalidArgumentException;
use OCA\FileChecksumSearch\Command\Backup;
use OCA\FileChecksumSearch\Service\ExportService;
use OCA\FileChecksumSearch\State\Format\CsvFormat;
use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\FormatRegistry;
use OCA\FileChecksumSearch\State\Format\JsonFormat;
use OCA\FileChecksumSearch\State\Format\SumFormat;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What the command decides before the export service is asked to do
 * anything: which slices, which format, and whether the destination can be
 * written at all.
 */
class BackupTest
	extends
	TestCase
{

	private ExportService&MockObject $exportService;

	private CommandTester            $tester;

	private string                   $tempDir;


	protected function setUp(): void
	{

		parent::setUp();

		$this->exportService = $this->createMock( ExportService::class );
		$this->exportService->method( 'export' )
		                    ->willReturn(
			                    [
				                    ExportService::SLICE_CONFIG => 0,
				                    ExportService::SLICE_STATUS => 0,
				                    ExportService::SLICE_HASHES => 0,
			                    ],
		                    )
		;

		$this->tester = new CommandTester(
			new Backup(
				$this->exportService,
				new FormatRegistry(),
				$this->createMock( LoggerInterface::class ),
			),
		);

		$this->tempDir = sys_get_temp_dir() . '/fcias-backup-test-' . getmypid();
		@mkdir( $this->tempDir, 0o777, true );
	}


	protected function tearDown(): void
	{

		foreach (
			glob( $this->tempDir . '/*' )
				?: [] as $file
		)
		{
			@unlink( $file );
		}
		@rmdir( $this->tempDir );

		parent::tearDown();
	}


	/**
	 * A backup that quietly held something back would not be one.
	 */
	public function testNamingNoSliceBacksUpAllOfThem(): void
	{

		$this->expectSlices( ExportService::SLICES );

		$this->assertSame(
			Command::SUCCESS,
			$this->tester->execute( [ '--output' => $this->tempDir . '/out.json' ] ),
		);
	}


	public function testNamingSlicesRestrictsTheBackupToThem(): void
	{

		$this->expectSlices(
			[
				ExportService::SLICE_CONFIG,
				ExportService::SLICE_HASHES,
			],
		);

		$this->tester->execute(
			[
				'--config' => true,
				'--hashes' => true,
				'--output' => $this->tempDir . '/out.json',
			],
		);
	}


	/**
	 * @dataProvider guessableNames
	 */
	public function testTheOutputFilenameChoosesTheFormat(
		string $filename,
		string $expected,
	): void {

		$this->expectFormat( $expected );

		$this->tester->execute(
			[
				'--output' => $this->tempDir . '/' . $filename,
				'--algo'   => 'sha256',
			],
		);
	}


	/**
	 * @return array<string, array{string, class-string}>
	 */
	public static function guessableNames(): array
	{

		return [
			'json' => [
				'backup.json',
				JsonFormat::class,
			],
			'csv' => [
				'export.csv',
				CsvFormat::class,
			],
			'sumfile' => [
				'SHA256SUMS.sha256',
				SumFormat::class,
			],
		];
	}


	public function testAnExplicitFormatBeatsTheFilename(): void
	{

		$this->expectFormat( CsvFormat::class );

		$this->tester->execute(
			[
				'--output' => $this->tempDir . '/misleading.json',
				'--format' => 'csv',
			],
		);
	}


	public function testAnUnnamedDestinationDefaultsToJson(): void
	{

		$this->expectFormat( JsonFormat::class );

		$this->tester->execute( [] );
	}


	public function testAnUnknownFormatIsRefusedWithTheListOfRealOnes(): void
	{

		$this->exportService->expects( $this->never() )
		                    ->method( 'export' )
		;

		$this->assertSame( Command::INVALID, $this->tester->execute( [ '--format' => 'yaml' ] ) );
		$this->assertStringContainsString( 'json, csv, sum', $this->tester->getDisplay() );
	}


	/**
	 * The one thing a checksum listing cannot say about itself.
	 */
	public function testASumfileWithoutAnAlgorithmIsRefused(): void
	{

		$this->exportService->expects( $this->never() )
		                    ->method( 'export' )
		;

		$this->assertSame( Command::INVALID, $this->tester->execute( [ '--format' => 'sum' ] ) );
		$this->assertStringContainsString( '--algo', $this->tester->getDisplay() );
	}


	/**
	 * Checked before a single row is read: finding out afterwards would waste
	 * the whole run, and on a large instance that is not a short wait.
	 */
	public function testAnUnwritableDestinationFailsBeforeAnythingIsExported(): void
	{

		$this->exportService->expects( $this->never() )
		                    ->method( 'export' )
		;

		$this->assertSame(
			Command::FAILURE,
			$this->tester->execute( [ '--output' => $this->tempDir . '/no/such/directory/out.json' ] ),
		);
		$this->assertStringContainsString( 'Cannot write', $this->tester->getDisplay() );
	}


	/**
	 * A refusal from the service — a hash-only format asked for the config
	 * slice — reaches the operator with its reason, not as a stack trace.
	 */
	public function testARefusalFromTheServiceIsReportedAsAFailure(): void
	{

		$this->exportService = $this->createMock( ExportService::class );
		$this->exportService->method( 'export' )
		                    ->willThrowException(
			                    new InvalidArgumentException( 'This format carries hashes only' ),
		                    )
		;
		$this->tester = new CommandTester(
			new Backup(
				$this->exportService,
				new FormatRegistry(),
				$this->createMock( LoggerInterface::class ),
			),
		);

		$this->assertSame(
			Command::FAILURE,
			$this->tester->execute(
				[
					'--format' => 'csv',
					'--config' => true,
					'--output' => $this->tempDir . '/out.csv',
				],
			),
		);
		$this->assertStringContainsString( 'hashes only', $this->tester->getDisplay() );
	}


	/**
	 * To standard output the backup document *is* the output: a summary
	 * printed alongside it would land in the same stream and corrupt the
	 * file.
	 */
	public function testNoSummaryIsPrintedWhenTheDocumentGoesToStandardOutput(): void
	{

		$this->tester->execute( [] );

		$this->assertSame( '', $this->tester->getDisplay() );
	}


	public function testWritingToAFileReportsTheCountsAndWhatTheFormatCannotCarry(): void
	{

		$this->tester->execute(
			[
				'--format' => 'csv',
				'--hashes' => true,
				'--output' => $this->tempDir . '/out.csv',
			],
		);

		$display = $this->tester->getDisplay();

		$this->assertStringContainsString( 'hashes', $display );
		$this->assertStringContainsString( 'Written to', $display );
		$this->assertStringContainsString( 'does not carry', $display );
	}


	/**
	 * `--algo` narrows a checksum listing to one algorithm; every other
	 * format names the algorithm on each record, so it has nothing to do
	 * there. Saying so beats writing a backup document the operator
	 * believes is narrower than it is.
	 */
	public function testAPointlessAlgoIsCalledOut(): void
	{

		$this->tester->execute(
			[
				'--format' => 'csv',
				'--algo'   => 'sha256',
				'--output' => $this->tempDir . '/out.csv',
			],
		);

		$this->assertStringContainsString( '--algo only narrows', $this->tester->getDisplay() );
	}


	/**
	 * @param  list<string>  $expected
	 */
	private function expectSlices( array $expected ): void
	{

		$this->exportService = $this->createMock( ExportService::class );
		$this->exportService->expects( $this->once() )
		                    ->method( 'export' )
		                    ->with(
			                    $this->anything(),
			                    $expected,
			                    $this->anything(),
			                    $this->anything(),
		                    )
		                    ->willReturn( [] )
		;
		$this->rebuild();
	}


	/**
	 * @param  class-string  $expected
	 */
	private function expectFormat( string $expected ): void
	{

		$this->exportService = $this->createMock( ExportService::class );
		$this->exportService->expects( $this->once() )
		                    ->method( 'export' )
		                    ->with(
			                    $this->isInstanceOf( $expected ),
			                    $this->anything(),
			                    $this->anything(),
			                    $this->isInstanceOf( FormatOptions::class ),
		                    )
		                    ->willReturn( [] )
		;
		$this->rebuild();
	}


	private function rebuild(): void
	{

		$this->tester = new CommandTester(
			new Backup(
				$this->exportService,
				new FormatRegistry(),
				$this->createMock( LoggerInterface::class ),
			),
		);
	}

}
