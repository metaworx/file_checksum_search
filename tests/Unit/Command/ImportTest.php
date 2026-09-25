<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\Import;
use OCA\FileChecksumSearch\Service\ImportService;
use OCA\FileChecksumSearch\State\Format\CsvFormat;
use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\FormatRegistry;
use OCA\FileChecksumSearch\State\Format\JsonFormat;
use OCA\FileChecksumSearch\State\Format\SumFormat;
use OCA\FileChecksumSearch\State\ImportPolicy;
use OCA\FileChecksumSearch\State\ImportReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The decisions the command makes before the service is asked to do
 * anything — and the two it refuses to make on the operator's behalf.
 */
class ImportTest
    extends
    TestCase
{

//  private properties

	private ImportService&MockObject $importService;

	private CommandTester            $tester;

	private string                   $file;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->importService = $this->createMock( ImportService::class );
		$this->importService->method( 'import' )
		                    ->willReturn( new ImportReport() )
		;
		$this->rebuild();

		$this->file = sys_get_temp_dir() . '/fcias-import-test-' . getmypid() . '.json';
		file_put_contents( $this->file, '{"schema":1,"hashes":[]}' );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		@unlink( $this->file );

		parent::tearDown();
	}

	/**
	 * There is no safe default: merging keeps what this instance worked out
	 * for itself, replacing prefers the file, and guessing wrong is silent
	 * either way.
	 */
	public function testOneOfMergeOrReplaceIsRequired(): void
	{
		$this->importService->expects( $this->never() )
		                    ->method( 'import' )
		;

		$this->assertSame( Command::INVALID, $this->tester->execute( [ '--input' => $this->file ] ) );
		$this->assertStringContainsString( '--merge', $this->tester->getDisplay() );
	}

	public function testBothAtOnceIsAlsoRefused(): void
	{
		$this->importService->expects( $this->never() )
		                    ->method( 'import' )
		;

		$this->assertSame(
			Command::INVALID,
			$this->tester->execute(
				[
					'--merge'   => true,
					'--replace' => true,
					'--input'   => $this->file,
				],
			),
		);
	}

	/**
	 * The queue says what this instance is about to do. It is worked out
	 * from the rules and the files, so there is nothing to restore.
	 */
	public function testTheStatusSliceIsRefusedWithItsReason(): void
	{
		$this->importService->expects( $this->never() )
		                    ->method( 'import' )
		;

		$this->assertSame(
			Command::INVALID,
			$this->tester->execute(
				[
					'--status'  => true,
					'--replace' => true,
					'--input'   => $this->file,
				],
			),
		);
		$this->assertStringContainsString( 'cannot be imported', $this->tester->getDisplay() );
	}

	public function testASumfileWithoutAnAlgorithmIsRefused(): void
	{
		$this->importService->expects( $this->never() )
		                    ->method( 'import' )
		;

		$this->assertSame(
			Command::INVALID,
			$this->tester->execute(
				[
					'--merge'  => true,
					'--format' => 'sum',
					'--input'  => $this->file,
				],
			),
		);
		$this->assertStringContainsString( '--algo', $this->tester->getDisplay() );
	}

	public function testAnchoringToBothAUserAndAStorageIsRefused(): void
	{
		$this->importService->expects( $this->never() )
		                    ->method( 'import' )
		;

		$this->assertSame(
			Command::INVALID,
			$this->tester->execute(
				[
					'--merge'   => true,
					'--user'    => 'alice',
					'--storage' => 'home::alice',
					'--input'   => $this->file,
				],
			),
		);
	}

	public function testAnUnknownStampPolicyIsRefused(): void
	{
		$this->importService->expects( $this->never() )
		                    ->method( 'import' )
		;

		$this->assertSame(
			Command::INVALID,
			$this->tester->execute(
				[
					'--merge' => true,
					'--stamp' => 'yesterday',
					'--input' => $this->file,
				],
			),
		);
	}

	public function testAnUnreadableInputFails(): void
	{
		$this->importService->expects( $this->never() )
		                    ->method( 'import' )
		;

		$this->assertSame(
			Command::FAILURE,
			$this->tester->execute(
				[
					'--merge' => true,
					'--input' => $this->file . '.missing',
				],
			),
		);
		$this->assertStringContainsString( 'Cannot read', $this->tester->getDisplay() );
	}

	/**
	 * @dataProvider guessableNames
	 */
	public function testTheFilenameChoosesTheFormat(
		string $suffix,
		string $expected,
	): void
	{
		$path = $this->file . $suffix;
		file_put_contents( $path, '' );

		$this->expectCall(
			$this->isInstanceOf( $expected ),
			$this->anything(),
		);

		$this->tester->execute(
			[
				'--merge' => true,
				'--algo'  => 'sha256',
				'--input' => $path,
			],
		);

		@unlink( $path );
	}


//  static methods

	/**
	 * @return array<string, array{string, class-string}>
	 */
	public static function guessableNames(): array
	{
		return [
			'json' => [
				'',
				JsonFormat::class,
			],
			'csv'  => [
				'.csv',
				CsvFormat::class,
			],
			'sum'  => [
				'.sum',
				SumFormat::class,
			],
		];
	}

	public function testThePolicyReachesTheServiceAsWritten(): void
	{
		$this->expectCall(
			$this->anything(),
			$this->callback(
				static fn(
					ImportPolicy $policy,
				): bool => $policy->merge === false
					&& $policy->stamp === ImportPolicy::STAMP_MTIME
					&& $policy->allowOutdated
					&& $policy->strict
					&& $policy->dryRun,
			),
		);

		$this->tester->execute(
			[
				'--replace'     => true,
				'--stamp'       => 'mtime',
				'--allow-stale' => true,
				'--strict'      => true,
				'--dry-run'     => true,
				'--input'       => $this->file,
			],
		);
	}

	/**
	 * Naming neither slice takes both, the way the backup takes all three.
	 */
	public function testNamingNoSliceImportsBoth(): void
	{
		$this->importService = $this->createMock( ImportService::class );
		$this->importService->expects( $this->once() )
		                    ->method( 'import' )
		                    ->with(
			                    $this->anything(),
			                    $this->anything(),
			                    $this->anything(),
			                    $this->anything(),
			                    true,
			                    true,
		                    )
		                    ->willReturn( new ImportReport() )
		;
		$this->rebuild();

		$this->tester->execute(
			[
				'--merge' => true,
				'--input' => $this->file,
			],
		);
	}

	/**
	 * A hash table has nowhere to put configuration, so "everything the file
	 * could hold" is the hashes alone. Defaulting to both regardless made a
	 * plain `--format=sum` import fail on a slice the file was never able to
	 * carry — which is what the documented example did.
	 */
	public function testAHashOnlyFormatDefaultsToHashesAlone(): void
	{
		$this->importService = $this->createMock( ImportService::class );
		$this->importService->expects( $this->once() )
		                    ->method( 'import' )
		                    ->with(
			                    $this->anything(),
			                    $this->anything(),
			                    $this->anything(),
			                    $this->anything(),
			                    false,
			                    true,
		                    )
		                    ->willReturn( new ImportReport() )
		;
		$this->rebuild();

		$this->tester->execute(
			[
				'--merge'  => true,
				'--format' => 'sum',
				'--algo'   => 'sha256',
				'--input'  => $this->file,
			],
		);
	}

	public function testNamingOneSliceLeavesTheOtherOut(): void
	{
		$this->importService = $this->createMock( ImportService::class );
		$this->importService->expects( $this->once() )
		                    ->method( 'import' )
		                    ->with(
			                    $this->anything(),
			                    $this->anything(),
			                    $this->anything(),
			                    $this->anything(),
			                    false,
			                    true,
		                    )
		                    ->willReturn( new ImportReport() )
		;
		$this->rebuild();

		$this->tester->execute(
			[
				'--merge'  => true,
				'--hashes' => true,
				'--input'  => $this->file,
			],
		);
	}

	/**
	 * A policy that asserts more than its data supports should say so, since
	 * nothing downstream will ever notice if it is wrong.
	 *
	 * @dataProvider overstatingPolicies
	 */
	public function testAPolicyThatOverstatesItsEvidenceWarns(
		string $option,
		string $expected,
	): void
	{
		$this->tester->execute(
			[
				'--merge' => true,
				'--input' => $this->file,
				...$option === '--stamp'
					? [ '--stamp' => 'now' ]
					: [ $option => true ],
			],
		);

		$this->assertStringContainsString( $expected, $this->tester->getDisplay() );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function overstatingPolicies(): array
	{
		return [
			'stamp=now'   => [
				'--stamp',
				'will ever notice',
			],
			'allow-stale' => [
				'--allow-stale',
				'wrong from the moment they land',
			],
		];
	}

	public function testADryRunSaysSo(): void
	{
		$this->tester->execute(
			[
				'--merge'   => true,
				'--dry-run' => true,
				'--input'   => $this->file,
			],
		);

		$this->assertStringContainsString( 'nothing was written', $this->tester->getDisplay() );
	}

	public function testRefusedConfigKeysAreNamed(): void
	{
		$report                = new ImportReport();
		$report->configWritten = 1;
		$report->configRefused = [ 'from_a_newer_version' ];

		$this->importService = $this->createMock( ImportService::class );
		$this->importService->method( 'import' )
		                    ->willReturn( $report )
		;
		$this->rebuild();

		$this->tester->execute(
			[
				'--merge' => true,
				'--input' => $this->file,
			],
		);

		$this->assertStringContainsString( 'from_a_newer_version', $this->tester->getDisplay() );
	}

	private function expectCall(
		mixed $format,
		mixed $policy,
	): void
	{
		$this->importService = $this->createMock( ImportService::class );
		$this->importService->expects( $this->once() )
		                    ->method( 'import' )
		                    ->with(
			                    $format,
			                    $this->anything(),
			                    $this->isInstanceOf( FormatOptions::class ),
			                    $policy,
			                    $this->anything(),
			                    $this->anything(),
		                    )
		                    ->willReturn( new ImportReport() )
		;
		$this->rebuild();
	}

	private function rebuild(): void
	{
		$this->tester = new CommandTester(
			new Import(
				$this->importService,
				new FormatRegistry(),
				$this->createMock( LoggerInterface::class ),
			),
		);
	}
}
