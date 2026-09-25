<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Migration\RepairQuietStart;
use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * This app's repair, on its own and step by step.
 *
 * `occ maintenance:repair` runs every app's, which is slow when only this one
 * is in question, and runs all of this one's as a single unit — so an
 * administrator could see that a repair had happened but not what it did, and
 * could not run again the one part that failed.
 *
 * @noinspection PhpUnused
 */
class Repair
    extends
    Command
{

//  constructor

	public function __construct(
		private readonly RepairQuietStart $repair,
		private readonly LoggerInterface  $logger,
	)
	{
		parent::__construct();
	}


//  config/init/exe/run methods

	/**
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{
		$this->setName( 'file-checksum-search:repair' )
		     ->setAliases( [ 'fcias:repair' ] )
		     ->setDescription( 'Run this app\'s repair steps, all of them or by name' )
		     ->addOption(
			     'list',
			     'l',
			     InputOption::VALUE_NONE,
			     'Show the steps and what each one does, and run nothing',
		     )
		     ->addOption(
			     'step',
			     's',
			     InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
			     'Run only this step. Repeatable',
		     )
		     ->addOption(
			     'include-expensive',
			     null,
			     InputOption::VALUE_NONE,
			     'Let the expensive steps do their full work instead of the cheapest correct thing',
		     )
		     ->addOption(
			     'dry-run',
			     null,
			     InputOption::VALUE_NONE,
			     'Say what would run, and change nothing',
		     )
		     ->setHelp(
			     <<<'HELP'
The <info>%command.name%</info> command runs this app's repair steps — the same ones
<comment>occ maintenance:repair</comment> runs, without every other app's.

  <info>occ fcias:repair --list</info>                    what the steps are, and what each does
  <info>occ fcias:repair</info>                           all of them
  <info>occ fcias:repair --step metadata-keys</info>      one of them; repeat the option for more
  <info>occ fcias:repair --dry-run</info>                 what would run, changing nothing

A step marked <comment>only when asked for</comment> is skipped by a plain run: it cannot tell
whether it has work without doing the expensive part, and the answer is
almost always none. Name it with <info>--step</info>, or pass <info>--include-expensive</info>.

A step marked <comment>expensive</comment> costs more the larger the instance is, so it does the
cheapest thing that is correct: it asks whether there is anything to do before
doing it. <info>--include-expensive</info> tells it not to ask. Reach for that when a step
reports nothing to do and you have reason to believe otherwise.

Every step is safe to run again. Running one that has nothing to do costs the
asking and no more.
HELP,
		     )
		;
	}

	/**
	 * @noinspection PhpUnused
	 */
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int
	{
		$steps = $this->repair->steps();

		if ( $input->getOption( 'list' ) )
		{
			$this->listSteps( $steps, $output );

			return Command::SUCCESS;
		}

		$only = $input->getOption( 'step' );

		if ( $only !== [] )
		{
			$known   = array_map( static fn(
				array $e,
			): string => $e['step']->name, $steps );
			$unknown = array_diff( $only, $known );

			// Named and not run is the failure worth catching: a typo would
			// otherwise report a successful repair that did nothing.
			if ( $unknown !== [] )
			{
				$output->writeln(
					sprintf(
						'<error>No such step: %s. Try --list.</error>',
						implode( ', ', $unknown ),
					),
				);

				return Command::INVALID;
			}
		}

		if ( $input->getOption( 'dry-run' ) )
		{
			$this->describeRun( $steps, $only, (bool) $input->getOption( 'include-expensive' ), $output );

			return Command::SUCCESS;
		}

		try
		{
			$ran = $this->repair
				->withExpensive( (bool) $input->getOption( 'include-expensive' ) )
				->runSteps(
					$this->asRepairOutput( $output ),
					$only === []
						? null
						: $only,
				)
			;
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS repair: failed.',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);
			$output->writeln( '<error>' . $e->getMessage() . '</error>' );

			return Command::FAILURE;
		}

		$this->logger->info(
			'FCIAS: repair run from the command line.',
			[
				'app'   => Application::APP_ID,
				'steps' => implode( ', ', $ran ),
			],
		);

		$output->writeln( sprintf( 'Ran %d step(s): %s', count( $ran ), implode( ', ', $ran ) ) );

		return Command::SUCCESS;
	}


//  other non-static methods

	/**
	 * @param  list<array{step: \OCA\FileChecksumSearch\Migration\RepairStep, method: \ReflectionMethod}>  $steps
	 */
	private function listSteps(
		array           $steps,
		OutputInterface $output,
	): void
	{
		foreach ( $steps as $entry )
		{
			$step = $entry['step'];

			$labels = [];

			if ( $step->expensive )
			{
				$labels[] = 'expensive';
			}

			if ( $step->manualOnly )
			{
				$labels[] = 'only when asked for';
			}

			$output->writeln(
				sprintf(
					'<info>%s</info>%s',
					$step->name,
					$labels === []
						? ''
						: '  <comment>(' . implode( ', ', $labels ) . ')</comment>',
				),
			);
			$output->writeln( '  ' . $step->title );

			foreach ( explode( "\n", wordwrap( $step->description, 74 ) ) as $line )
			{
				$output->writeln( '    ' . $line );
			}

			$output->writeln( '' );
		}
	}

	/**
	 * @param  list<array{step: \OCA\FileChecksumSearch\Migration\RepairStep, method: \ReflectionMethod}>  $steps
	 * @param  list<string>                                                                                $only
	 */
	private function describeRun(
		array           $steps,
		array           $only,
		bool            $includeExpensive,
		OutputInterface $output,
	): void
	{
		$output->writeln( '<comment>Dry run — nothing was changed.</comment>' );

		foreach ( $steps as $entry )
		{
			$step = $entry['step'];

			if ( $only !== [] && ! in_array( $step->name, $only, true ) )
			{
				$output->writeln( sprintf( '  %-24s not named', $step->name ) );

				continue;
			}

			if ( $step->manualOnly && $only === [] && ! $includeExpensive )
			{
				$output->writeln(
					sprintf(
						'  %-24s skipped, it only runs when asked for by name or with --include-expensive',
						$step->name,
					),
				);

				continue;
			}

			$output->writeln(
				sprintf(
					'  %-24s would run%s',
					$step->name,
					$step->expensive && ! $includeExpensive && ! $step->manualOnly
						? ', asking first whether there is anything to do'
						: '',
				),
			);
		}
	}

	/**
	 * Adapt the console to what a repair step writes to.
	 *
	 * The steps take Nextcloud's `IOutput`, because they are also run by
	 * `maintenance:repair`; this hands them a console instead of a migration.
	 */
	private function asRepairOutput( OutputInterface $output ): IOutput
	{
		return new class( $output )
		    implements
		    IOutput {

//  constructor

			public function __construct(
				private readonly OutputInterface $output,
			) {
			}


//  other non-static methods

			public function debug( string $message ): void
			{
				$this->output->writeln( '  ' . $message, OutputInterface::VERBOSITY_VERBOSE );
			}

			/**
			 * Untyped return, because that is how the interface declares it.
			 *
			 * @noinspection PhpMissingReturnTypeInspection
			 */
			public function info( $message )
			{
				$this->output->writeln( '  ' . $message );
			}

			/** @noinspection PhpMissingReturnTypeInspection */
			public function warning( $message )
			{
				$this->output->writeln( '  <comment>' . $message . '</comment>' );
			}

			public function startProgress( $max = 0 )
			{
			}

			public function advance(
				$step = 1,
				$description = '',
			) {
			}

			public function finishProgress()
			{
			}
		};
	}
}
