<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleOverrides;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;
use Throwable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class HashFiles
	extends
	Command
{

	public function __construct(
		private readonly HashIndexService $hashIndexService,
		private readonly MetadataService  $metadataService,
		private readonly FilecacheService $filecacheService,
		private readonly RuleService      $ruleService,
		private readonly LoggerInterface  $logger,
	) {

		parent::__construct();
	}


	/**
	 * Configure the hash command.
	 *
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:hash' )
		     ->setAliases( [ 'fcias:hash' ] )
		     ->setDescription( 'Compute checksums for user files, or mark them for background processing' )
		     ->addOption(
			     'user',
			     null,
			     InputOption::VALUE_OPTIONAL,
			     'User whose files to process (omit for all users)',
			     'all',
		     )
		     ->addOption(
			     'path',
			     null,
			     InputOption::VALUE_OPTIONAL,
			     'Glob pattern for file paths (e.g. **/*.pdf)',
			     null,
		     )
		     ->addOption(
			     'algo',
			     null,
			     InputOption::VALUE_OPTIONAL,
			     'Hash algorithm(s), comma-separated, or "all" for every supported algorithm',
			     HashCalculationService::getDefaultAlgo(),
		     )
		     ->addOption(
			     'batch-size',
			     null,
			     InputOption::VALUE_OPTIONAL,
			     'Maximum files to process per run',
		     )
		     ->addOption(
			     'mark',
			     null,
			     InputOption::VALUE_NONE,
			     'Mark files as pending:auto instead of computing hashes immediately',
		     )
		     ->addOption(
			     'with-ignored',
			     null,
			     InputOption::VALUE_NONE,
			     'Also process files whose governing rule is "ignore". Does not affect "exclude".',
		     )
		     ->addOption(
			     'ignore-rule',
			     null,
			     InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
			     'Evaluate as if this rule did not exist, so the next matching rule decides. Repeatable.',
		     )
		;
	}


	/**
	 * Execute the hash command.
	 *
	 * @noinspection PhpUnused
	 */
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$userScope   = $input->getOption( 'user' );
		$pathPattern = $input->getOption( 'path' );
		$algo        = $input->getOption( 'algo' );
		$algos       = $this->normalizeAlgoList( $algo );
		$algoLabel   = implode( ',', $algos );
		$batchSize   = $input->getOption( 'batch-size' );
		$batchSize   = $batchSize !== null
			? (int) $batchSize
			: null;
		$markOnly    = (bool) $input->getOption( 'mark' );

		$overrides = $this->overridesFrom( $input, $output );

		if ( $overrides === null )
		{
			return Command::FAILURE;
		}

		$users = $this->ruleService->resolveUsers( $userScope );

		if ( empty( $users ) )
		{
			$output->writeln(
				sprintf(
					'<error>No users found for scope "%s".</error>',
					$userScope,
				),
			);

			return Command::FAILURE;
		}

		if ( $markOnly )
		{
			return $this->executeMarkOnly( $users, $pathPattern, $batchSize, $overrides, $output );
		}

		$output->writeln(
			sprintf(
				'Generating %s hashes for %d user(s) …',
				$algoLabel,
				count( $users ),
			),
		);

		if ( $output->isVerbose() )
		{
			$output->writeln( sprintf( '  User scope: %s', $userScope ) );
			$output->writeln( sprintf( '  Path pattern: %s', $pathPattern ?? '**' ) );
			$output->writeln(
				sprintf(
					'  Batch size: %s',
					$batchSize === null
						? 'unlimited'
						: (string) $batchSize,
				),
			);
		}
		elseif ( $batchSize !== null )
		{
			$output->writeln( sprintf( '  Batch size: %d', $batchSize ) );
		}

		$this->logger->debug(
			'FCIAS: hash command starting',
			[
				'app'         => Application::APP_ID,
				'userScope'   => $userScope,
				'users'       => $users,
				'algo'        => $algoLabel,
				'pathPattern' => $pathPattern,
				'batchSize'   => $batchSize,
			],
		);

		$totalProcessed = 0;
		$totalSkipped   = 0;
		$remaining      = $batchSize;

		foreach ( $users as $userId )
		{
			if ( $remaining !== null && $remaining <= 0 )
			{
				break;
			}

			$output->writeln( sprintf( '  User: %s', $userId ) );

			$result = $this->hashIndexService->generateMissingHashes(
				$userId,
				$algos,
				$pathPattern,
				$remaining ?? 0, // 0 = unlimited (--batch-size omitted)
				$output,
				$overrides,
			);

			$totalProcessed += $result['processed'];
			$totalSkipped   += $result['skipped'];

			if ( $remaining !== null )
			{
				$remaining -= $result['processed'];
			}
		}

		$limitReached = $batchSize !== null && $totalProcessed >= $batchSize;

		$output->writeln(
			sprintf(
				'%s %d files hashed, %d skipped.',
				$limitReached
					? 'Batch limit reached.'
					: 'Done.',
				$totalProcessed,
				$totalSkipped,
			),
		);

		return Command::SUCCESS;
	}


	/**
	 * Build the run's rule overrides, or null when the input is not usable.
	 *
	 * An unknown --ignore-rule id fails the run rather than being skipped
	 * quietly: the whole point of naming a rule is that the operator has a
	 * specific one in mind, and a typo would otherwise produce a normal-looking
	 * run that honoured the rule they meant to set aside.
	 *
	 * Setting aside an admin-enforced rule is allowed — this command already
	 * requires shell access as the web server user, so there is no privilege to
	 * protect — but it is logged at warning level naming the rule, because an
	 * enforced rule is the one an administrator wrote down as non-negotiable.
	 */
	private function overridesFrom(
		InputInterface  $input,
		OutputInterface $output,
	): ?RuleOverrides {

		/** @var list<string> $ignoreRuleIds */
		$ignoreRuleIds = $input->getOption( 'ignore-rule' );
		$withIgnored   = (bool) $input->getOption( 'with-ignored' );

		foreach ( $ignoreRuleIds as $ruleId )
		{
			$rule = $this->ruleService->findRuleById( $ruleId );

			if ( $rule === null )
			{
				$output->writeln(
					sprintf( '<error>No rule with ID "%s".</error>', $ruleId ),
				);

				return null;
			}

			if ( empty( $rule['admin_enforced'] ) )
			{
				continue;
			}

			$this->logger->warning(
				'FCIAS: hash command set aside admin-enforced rule {ruleId}',
				[
					'app'       => Application::APP_ID,
					'ruleId'    => $ruleId,
					'path'      => $rule['path'] ?? '',
					'userScope' => $rule['userScope'] ?? '',
					'type'      => RuleService::verdictOf( $rule ),
				],
			);

			$output->writeln(
				sprintf(
					'<comment>Setting aside admin-enforced rule %s (%s on %s).</comment>',
					$ruleId,
					RuleService::verdictOf( $rule ),
					$rule['path'] ?? '**',
				),
			);
		}

		if ( $withIgnored )
		{
			$output->writeln(
				'<comment>Processing files their rule says to ignore. Excluded files are still skipped.</comment>',
			);
		}

		return new RuleOverrides( $withIgnored, $ignoreRuleIds );
	}


	/**
	 * Normalize the --algo option value into a lowercase, unique algorithm
	 * list. The literal "all" expands to every supported algorithm.
	 *
	 * @param  mixed  $algo
	 *
	 * @return string[]
	 */
	private function normalizeAlgoList( mixed $algo ): array
	{

		$algo = (string) $algo;

		if ( strtolower( trim( $algo ) ) === 'all' )
		{
			return HashCalculationService::SUPPORTED_ALGOS;
		}

		$algos = array_filter( array_map( 'trim', explode( ',', $algo ) ), 'strlen' );

		return array_values( array_unique( array_map( 'strtolower', $algos ) ) );
	}


	/**
	 * Mark-only mode: walk user folders and mark matching files as pending:auto.
	 *
	 * @param  string[]         $users
	 * @param  string|null      $pathPattern
	 * @param  int|null         $batchSize
	 * @param  RuleOverrides    $overrides
	 * @param  OutputInterface  $output
	 *
	 * @return int
	 */
	private function executeMarkOnly(
		array           $users,
		?string         $pathPattern,
		?int            $batchSize,
		RuleOverrides   $overrides,
		OutputInterface $output,
	): int {

		$output->writeln(
			sprintf(
				'Marking files as pending:auto for %d user(s) …',
				count( $users ),
			),
		);

		if ( $batchSize !== null )
		{
			$output->writeln( sprintf( '  Batch size: %d', $batchSize ) );
		}

		$totalMarked  = 0;
		$totalSkipped = 0;
		$remaining    = $batchSize;

		foreach ( $users as $userId )
		{
			if ( $remaining !== null && $remaining <= 0 )
			{
				break;
			}

			$output->writeln( sprintf( '  User: %s', $userId ) );

			try
			{
				$userFolder = $this->filecacheService->getUserFolder( $userId );
			}
			catch ( Throwable )
			{
				$output->writeln( '    User folder not found, skipping.' );

				continue;
			}

			$skipped = 0;
			$marked  = $this->markFolder(
				$userFolder,
				$pathPattern,
				$overrides,
				$output,
				$remaining,
				$skipped,
			);

			$totalMarked  += $marked;
			$totalSkipped += $skipped;

			if ( $remaining !== null )
			{
				$remaining -= $marked;
			}

			$output->writeln(
				$skipped > 0
					? sprintf( '    Marked %d files, skipped %d excluded by rules.', $marked, $skipped )
					: sprintf( '    Marked %d files.', $marked ),
			);
		}

		$limitReached = $batchSize !== null && $totalMarked >= $batchSize;

		$output->writeln(
			sprintf(
				'%s %d files marked as pending:auto.%s',
				$limitReached
					? 'Batch limit reached.'
					: 'Done.',
				$totalMarked,
				$totalSkipped > 0
					? sprintf( ' %d skipped: a rule excludes them from hashing.', $totalSkipped )
					: '',
			),
		);

		return Command::SUCCESS;
	}


	/**
	 * Mark files matching a path glob as pending:auto.
	 *
	 * Delegates file search to RuleService::searchFilesByGlob(). Files whose
	 * governing rule says not to hash them automatically are skipped and
	 * counted in $skipped: this command is bulk maintenance, the CLI face of
	 * the background job, so an `ignore` or `exclude` verdict applies to it
	 * just as it does to the job. A user asking for one specific file by hand
	 * goes through the recalculation endpoint instead, where only `exclude`
	 * refuses.
	 *
	 * @return int Number of files marked
	 */
	private function markFolder(
		Folder          $folder,
		?string         $pathPattern,
		RuleOverrides   $overrides,
		OutputInterface $output,
		?int            &$remaining,
		int             &$skipped = 0,
	): int {

		$files = $this->ruleService->searchFilesByGlob(
			$folder,
			$pathPattern ?? '**',
			$remaining ?? 0, // 0 = unlimited (--batch-size omitted)
		);

		$marked = 0;

		foreach ( $files as $file )
		{
			if ( $remaining !== null && $remaining <= 0 )
			{
				break;
			}

			$rule = $this->ruleService->findFirstMatchingRule(
				$file->getPath(),
				$file->getOwner()
				     ?->getUID(),
				$overrides->ignoreRuleIds,
			);

			if ( ! $overrides->allows( $rule ) )
			{
				$skipped ++;
				$overrides->report( $output, $file->getPath(), $rule, false );

				continue;
			}

			$overrides->report( $output, $file->getPath(), $rule, true );

			$this->metadataService->markPending( $file->getId(), MetadataService::PENDING_AUTO );
			$marked ++;

			if ( $remaining !== null )
			{
				$remaining --;
			}
		}

		return $marked;
	}

}
