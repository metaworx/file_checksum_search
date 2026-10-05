<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\Repair;
use OCA\FileChecksumSearch\Migration\RepairQuietStart;
use OCA\FileChecksumSearch\Migration\RepairStep;
use OCA\FileChecksumSearch\Migration\VerboseOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Aiming the repair: which steps run, which do not, and what the operator is
 * told before anything happens.
 */
class RepairTest
    extends
    TestCase
{

//  private properties

	private RepairQuietStart&MockObject $repair;

	private CommandTester               $tester;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->repair = $this->createMock( RepairQuietStart::class );
		$this->repair->method( 'steps' )
		             ->willReturn( $this->twoSteps() )
		;
		$this->repair->method( 'withExpensive' )
		             ->willReturnSelf()
		;
		$this->repair->method( 'runSteps' )
		             ->willReturn( [] )
		;

		$this->rebuild();
	}


//  other non-static methods

	public function testListShowsEveryStepAndRunsNothing(): void
	{
		$this->repair->expects( $this->never() )
		             ->method( 'runSteps' )
		;

		$this->assertSame( Command::SUCCESS, $this->tester->execute( [ '--list' => true ] ) );

		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( 'cheap-step', $display );
		$this->assertStringContainsString( 'costly-step', $display );
		$this->assertStringContainsString( '(expensive)', $display );
		$this->assertStringContainsString( 'what the cheap one does', $display );
	}

	public function testNamingNoStepRunsThemAll(): void
	{
		$this->repair = $this->createMock( RepairQuietStart::class );
		$this->repair->method( 'steps' )
		             ->willReturn( $this->twoSteps() )
		;
		$this->repair->method( 'withExpensive' )
		             ->willReturnSelf()
		;
		$this->repair->expects( $this->once() )
		             ->method( 'runSteps' )
		             ->with( $this->anything(), null )
		             ->willReturn(
			             [
				             'cheap-step',
				             'costly-step',
			             ],
		             )
		;
		$this->rebuild();

		$this->tester->execute( [] );

		$this->assertStringContainsString( 'Ran 2 step(s)', $this->tester->getDisplay() );
	}

	public function testNamingStepsRunsOnlyThose(): void
	{
		$this->repair = $this->createMock( RepairQuietStart::class );
		$this->repair->method( 'steps' )
		             ->willReturn( $this->twoSteps() )
		;
		$this->repair->method( 'withExpensive' )
		             ->willReturnSelf()
		;
		$this->repair->expects( $this->once() )
		             ->method( 'runSteps' )
		             ->with( $this->anything(), [ 'cheap-step' ] )
		             ->willReturn( [ 'cheap-step' ] )
		;
		$this->rebuild();

		$this->tester->execute( [ '--step' => [ 'cheap-step' ] ] );
	}

	/**
	 * A typo must not report a successful repair that did nothing.
	 */
	public function testAnUnknownStepFailsRatherThanBeingIgnored(): void
	{
		$this->repair->expects( $this->never() )
		             ->method( 'runSteps' )
		;

		$this->assertSame(
			Command::INVALID,
			$this->tester->execute( [ '--step' => [ 'cheep-step' ] ] ),
		);
		$this->assertStringContainsString( 'No such step: cheep-step', $this->tester->getDisplay() );
	}

	public function testADryRunChangesNothingAndSaysWhatWould(): void
	{
		$this->repair->expects( $this->never() )
		             ->method( 'runSteps' )
		;

		$this->tester->execute( [ '--dry-run' => true ] );
		$display = $this->tester->getDisplay();

		$this->assertStringContainsString( 'nothing was changed', $display );
		$this->assertStringContainsString( 'cheap-step', $display );
		// The expensive one says it will ask before it works.
		$this->assertStringContainsString( 'asking first', $display );
	}

	public function testADryRunOfOneStepSaysTheOthersAreNotNamed(): void
	{
		$this->tester->execute(
			[
				'--dry-run' => true,
				'--step'    => [ 'cheap-step' ],
			],
		);

		$this->assertMatchesRegularExpression(
			'/costly-step\s+not named/',
			$this->tester->getDisplay(),
		);
	}

	/**
	 * The flag exists to reach what a guard cannot see, so it has to arrive.
	 */
	public function testIncludeExpensiveReachesTheRepair(): void
	{
		$this->repair = $this->createMock( RepairQuietStart::class );
		$this->repair->method( 'steps' )
		             ->willReturn( $this->twoSteps() )
		;
		$this->repair->expects( $this->once() )
		             ->method( 'withExpensive' )
		             ->with( true )
		             ->willReturnSelf()
		;
		$this->repair->method( 'runSteps' )
		             ->willReturn( [] )
		;
		$this->rebuild();

		$this->tester->execute( [ '--include-expensive' => true ] );
	}

	public function testADryRunOfAnExpensiveStepSaysSoWhenForced(): void
	{
		$this->tester->execute(
			[
				'--dry-run'           => true,
				'--include-expensive' => true,
			],
		);

		$this->assertStringNotContainsString( 'asking first', $this->tester->getDisplay() );
	}

	/**
	 * A step hears the console's verbosity: `-vv` shows its file-by-file
	 * lines, not its `-vvv` ones.
	 */
	public function testTheStepsHearTheConsolesVerbosity(): void
	{
		$heard = [];
		$this->repairRunning(
			static function( VerboseOutput $output ) use ( &$heard ): void
			{
				$heard = [
					$output->shows( VerboseOutput::VERY_VERBOSE ),
					$output->shows( VerboseOutput::DEBUG ),
				];
				$output->line( VerboseOutput::VERY_VERBOSE, 'each file' );
				$output->line( VerboseOutput::DEBUG, 'each hash' );
			},
		);

		$this->tester->execute( [], [ 'verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE ] );

		$this->assertSame( [ true, false ], $heard );
		$this->assertStringContainsString( 'each file', $this->tester->getDisplay() );
		$this->assertStringNotContainsString( 'each hash', $this->tester->getDisplay() );
	}

	/**
	 * `-q` for a run from cron: nothing but warnings and errors.
	 */
	public function testAQuietRunShowsTheWarningsAndNothingElse(): void
	{
		$this->repairRunning(
			static function( VerboseOutput $output ): void
			{
				$output->info( 'all is well' );
				$output->line( VerboseOutput::VERBOSE, 'found 3' );
				$output->warning( 'one file would not' );
			},
		);

		$this->tester->execute( [], [ 'verbosity' => OutputInterface::VERBOSITY_QUIET ] );

		$this->assertStringContainsString( 'one file would not', $this->tester->getDisplay() );
		$this->assertStringNotContainsString( 'all is well', $this->tester->getDisplay() );
		$this->assertStringNotContainsString( 'found 3', $this->tester->getDisplay() );
		$this->assertStringNotContainsString( 'Ran', $this->tester->getDisplay() );
	}

	public function testAFailureIsReportedRatherThanThrown(): void
	{
		$this->repair = $this->createMock( RepairQuietStart::class );
		$this->repair->method( 'steps' )
		             ->willReturn( $this->twoSteps() )
		;
		$this->repair->method( 'withExpensive' )
		             ->willReturnSelf()
		;
		$this->repair->method( 'runSteps' )
		             ->willThrowException( new RuntimeException( 'the database went away' ) )
		;
		$this->rebuild();

		$this->assertSame( Command::FAILURE, $this->tester->execute( [], [ 'verbosity' => OutputInterface::VERBOSITY_QUIET ] ) );
		$this->assertStringContainsString( 'the database went away', $this->tester->getDisplay(), 'also under -q' );
	}

	/**
	 * A repair whose steps do what $steps does with the output they are
	 * handed.
	 *
	 * @param  callable(VerboseOutput): void  $steps
	 */
	private function repairRunning( callable $steps ): void
	{
		$this->repair = $this->createMock( RepairQuietStart::class );
		$this->repair->method( 'steps' )
		             ->willReturn( $this->twoSteps() )
		;
		$this->repair->method( 'withExpensive' )
		             ->willReturnSelf()
		;
		$this->repair->method( 'runSteps' )
		             ->willReturnCallback(
			             static function( VerboseOutput $output ) use ( $steps ): array
			             {
				             $steps( $output );

				             return [ 'cheap-step' ];
			             },
		             )
		;
		$this->rebuild();
	}

	/**
	 * @return list<array{step: RepairStep, method: ReflectionMethod}>
	 * @noinspection PhpDocMissingThrowsInspection
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function twoSteps(): array
	{
		$method = new ReflectionMethod( $this, 'twoSteps' );

		return [
			[
				'step'   => new RepairStep(
					name: 'cheap-step',
					title: 'A cheap one',
					description: 'This is what the cheap one does.',
				),
				'method' => $method,
			],
			[
				'step'   => new RepairStep(
					name: 'costly-step',
					title: 'A costly one',
					description: 'This is what the costly one does.',
					expensive: true,
				),
				'method' => $method,
			],
		];
	}

	private function rebuild(): void
	{
		$this->tester = new CommandTester(
			new Repair( $this->repair, $this->createMock( LoggerInterface::class ) ),
		);
	}
}
