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
use OCP\Files\File;
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

	/**
	 * Sentinel default for --unmatched, distinguishing "option absent"
	 * (→ skip) from "present without a value" (→ unmatched-only).
	 */
	private const UNMATCHED_ABSENT = "\0absent";


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
			     'a',
			     InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
			     'Algorithm name, "all", or "auto" (the governing rule\'s list). Repeatable; '
			     . 'comma-separated values work too. Explicit names are exclusive; combining '
			     . 'them with "auto" forms the union.',
			     [ HashCalculationService::ALGO_AUTO ],
		     )
		     ->addOption(
			     'mode',
			     'm',
			     InputOption::VALUE_REQUIRED,
			     '"missing" computes absent hashes and refreshes outdated ones; "force" recomputes '
			     . 'everything requested. ("auto" is the background drain\'s job; deferring is --mark.)',
			     MetadataService::PENDING_MODE_MISSING,
		     )
		     ->addOption(
			     'unmatched',
			     'u',
			     InputOption::VALUE_OPTIONAL,
			     'Files no rule governs: "skip" (default), "include" (process them too), or '
			     . '"unmatched" (process only them). Bare -u means "unmatched".',
			     self::UNMATCHED_ABSENT,
		     )
		     ->addOption(
			     'batch-size',
			     null,
			     InputOption::VALUE_OPTIONAL,
			     'Maximum files to process per run',
		     )
		     ->addOption(
			     'mark',
			     'k',
			     InputOption::VALUE_NONE,
			     'Queue matching files as pending:<mode> for the background job instead of '
			     . 'computing now',
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
		$algos       = $this->normalizeAlgoList( $input->getOption( 'algo' ) );
		$algoLabel   = implode( ',', $algos );
		$batchSize   = $input->getOption( 'batch-size' );
		$batchSize   = $batchSize !== null
			? (int) $batchSize
			: null;
		$markOnly    = (bool) $input->getOption( 'mark' );
		$mode        = (string) $input->getOption( 'mode' );

		$invalid = array_diff(
			$algos,
			HashCalculationService::SUPPORTED_ALGOS,
			[ HashCalculationService::ALGO_AUTO ],
		);

		if ( $invalid !== [] )
		{
			// An unknown algorithm used to flow through silently and simply
			// produce nothing; a typo must fail, not underdeliver.
			$output->writeln(
				sprintf( '<error>Unsupported algorithm(s): %s.</error>', implode( ', ', $invalid ) ),
			);

			return Command::FAILURE;
		}

		if ( ! in_array(
			$mode,
			[
				MetadataService::PENDING_MODE_MISSING,
				MetadataService::PENDING_MODE_FORCE,
			],
			true,
		) )
		{
			$output->writeln(
				sprintf(
					'<error>Unsupported mode "%s". Use "missing" or "force" — "auto" is the '
					. 'background drain\'s semantics, and deferring is --mark.</error>',
					$mode,
				),
			);

			return Command::FAILURE;
		}

		$overrides = $this->overridesFrom( $input, $output );

		if ( $overrides === null )
		{
			return Command::FAILURE;
		}

		$hasExplicitAlgos = $algos !== [ HashCalculationService::ALGO_AUTO ]
			&& array_diff( $algos, [ HashCalculationService::ALGO_AUTO ] ) !== [];

		if ( $overrides->unmatched !== RuleOverrides::UNMATCHED_SKIP && ! $hasExplicitAlgos )
		{
			// An unmatched file has no rule to supply algorithms, so a run
			// that includes them under pure "auto" could only skip every one
			// of them — a normal-looking run that did nothing asked of it.
			$output->writeln(
				'<error>--unmatched processes files no rule governs, so no rule can supply '
				. 'their algorithms: pass at least one explicit --algo.</error>',
			);

			return Command::FAILURE;
		}

		if ( $markOnly && $overrides->unmatched !== RuleOverrides::UNMATCHED_SKIP )
		{
			// The background drain resolves rules at action time and drops
			// marks nothing governs, so deferring unmatched files would queue
			// work the drain is designed to refuse.
			$output->writeln(
				'<error>--mark cannot defer unmatched files: the background job honours the '
				. 'rules, and a file no rule governs would be dropped at drain time. Run '
				. 'without --mark to hash them now.</error>',
			);

			return Command::FAILURE;
		}

		if ( $markOnly && $hasExplicitAlgos )
		{
			$output->writeln(
				'<comment>--algo is ignored with --mark: the background job takes each '
				. 'file\'s algorithms from its governing rule at drain time.</comment>',
			);
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
			return $this->executeMarkOnly( $users, $pathPattern, $batchSize, $overrides, $mode, $output );
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
				$mode,
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
		$unmatchedRaw  = $input->getOption( 'unmatched' );

		// Absent → skip; bare --unmatched / -u → only unmatched files.
		$unmatched = match ( $unmatchedRaw )
		{
			self::UNMATCHED_ABSENT => RuleOverrides::UNMATCHED_SKIP,
			null => RuleOverrides::UNMATCHED_ONLY,
			default => strtolower( (string) $unmatchedRaw ),
		};

		if ( ! in_array( $unmatched, RuleOverrides::UNMATCHED_CHOICES, true ) )
		{
			$output->writeln(
				sprintf(
					'<error>Unsupported --unmatched value "%s". Use include, skip, or unmatched.</error>',
					$unmatchedRaw,
				),
			);

			return null;
		}

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
					'app'      => Application::APP_ID,
					'ruleId'   => $ruleId,
					'path'     => $rule['path'] ?? '',
					'selector' => RuleService::ruleSelector( $rule )
					                         ->canonical(),
					'type'     => RuleService::verdictOf( $rule ),
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

		if ( $unmatched === RuleOverrides::UNMATCHED_ONLY )
		{
			$output->writeln(
				'<comment>Processing only files no rule governs. Matched files are skipped, '
				. 'whatever their verdict.</comment>',
			);
		}

		return new RuleOverrides( $withIgnored, $ignoreRuleIds, $unmatched );
	}


	/**
	 * Normalize the repeatable --algo option into a lowercase, unique token
	 * list. Each value may itself be comma-separated; "all" expands to every
	 * supported algorithm; "auto" is kept as a token for the service to
	 * resolve per file against the governing rule.
	 *
	 * @param  string[]  $values
	 *
	 * @return string[]
	 */
	private function normalizeAlgoList( array $values ): array
	{

		$tokens = [];

		foreach ( $values as $value )
		{
			foreach ( explode( ',', strtolower( $value ) ) as $token )
			{
				$token = trim( $token );

				if ( $token === '' )
				{
					continue;
				}

				if ( $token === 'all' )
				{
					$tokens = array_merge( $tokens, HashCalculationService::SUPPORTED_ALGOS );

					continue;
				}

				$tokens[] = $token;
			}
		}

		return array_values( array_unique( $tokens ) );
	}


	/**
	 * Mark-only mode: walk user folders and mark matching files as pending:<mode>.
	 *
	 * @param  string[]         $users
	 * @param  string|null      $pathPattern
	 * @param  int|null         $batchSize
	 * @param  RuleOverrides    $overrides
	 * @param  string           $mode
	 * @param  OutputInterface  $output
	 *
	 * @return int
	 */
	private function executeMarkOnly(
		array           $users,
		?string         $pathPattern,
		?int            $batchSize,
		RuleOverrides   $overrides,
		string          $mode,
		OutputInterface $output,
	): int {

		$output->writeln(
			sprintf(
				'Marking files as pending:%s for %d user(s) …',
				$mode,
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
				$mode,
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
				'%s %d files marked as pending:%s.%s',
				$limitReached
					? 'Batch limit reached.'
					: 'Done.',
				$totalMarked,
				$mode,
				$totalSkipped > 0
					? sprintf( ' %d skipped: a rule excludes them from hashing.', $totalSkipped )
					: '',
			),
		);

		return Command::SUCCESS;
	}


	/**
	 * Mark files matching a path glob as pending:<mode>.
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
		string          $mode,
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

		// One scan for the whole result set instead of a lookup per file.
		$rulesByFileId = $this->ruleService->governingRulesForFileIds(
			array_map(
				static fn(
					File $file,
				): int => $file->getId(),
				$files,
			),
			$overrides->ignoreRuleIds,
		);

		foreach ( $files as $file )
		{
			if ( $remaining !== null && $remaining <= 0 )
			{
				break;
			}

			$rule = $rulesByFileId[ $file->getId() ] ?? null;

			if ( ! $overrides->allows( $rule ) )
			{
				$skipped ++;
				$overrides->report( $output, $file->getPath(), $rule, false );

				continue;
			}

			$overrides->report( $output, $file->getPath(), $rule, true );

			// pending:<mode>, not a hardcoded pending:auto — "queue a forced
			// background recompute" is expressible now.
			$this->metadataService->markPending(
				$file->getId(),
				MetadataService::PENDING_PREFIX . $mode,
			);
			$marked ++;

			if ( $remaining !== null )
			{
				$remaining --;
			}
		}

		return $marked;
	}

}
