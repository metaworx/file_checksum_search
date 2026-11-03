<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use InvalidArgumentException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\DuplicateActionService;
use OCA\FileChecksumSearch\Service\DuplicateChoice;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\PathPattern;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * An account's duplicates resolved from the shell, as the Duplicates page's
 * *Select duplicates* resolves them: every copy of a group but the one that
 * stays ({@see DuplicateChoice}), through the page's own service, which opens
 * each row through the account's own reach and re-checks its hash.
 *
 * The accounts are taken in turn, each through its own reach, each listing
 * read after the one before was acted on: a file two of them reach is listed
 * for both, and copies in two accounts are not compared.
 *
 * @noinspection PhpUnused
 */
class ResolveDuplicates
    extends
    Command
{

//  constants

	/** The groups read at once from the listing, which walks every page. */
	private const PAGE_SIZE = 500;

	private const OUTPUTS = [
		'plain',
		'json',
		'json_pretty',
	];

	/** The order rows go in, as the service orders them: a version before its file. */
	private const ORDER = [
		DuplicateActionService::KIND_TRASHED_VERSION => 0,
		DuplicateActionService::KIND_VERSION         => 1,
		DuplicateActionService::KIND_TRASH           => 2,
		DuplicateActionService::KIND_FILE            => 3,
	];


//  constructor

	public function __construct(
		private readonly HashIndexService       $hashIndexService,
		private readonly DuplicateActionService $duplicateActions,
		private readonly IUserManager           $userManager,
		private readonly LoggerInterface        $logger,
	)
	{
		parent::__construct();
	}


//  config/init/exe/run methods

	/** @noinspection PhpUnused */
	#[\Override]
	protected function configure(): void
	{
		$this->setName( 'file-checksum-search:resolve-duplicates' )
		     ->setAliases( [ 'fcias:resolve-duplicates' ] )
		     ->setDescription( 'Delete an account\'s duplicate copies, keeping one of each' )
		     ->addOption(
			     'user',
			     'u',
			     InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
			     'An account whose duplicates are resolved; repeat it for several',
		     )
		     ->addOption(
			     'all',
			     null,
			     InputOption::VALUE_NONE,
			     'Every account, each in turn',
		     )
		     ->addOption(
			     'algo',
			     'a',
			     InputOption::VALUE_REQUIRED,
			     'Only the groups of this algorithm (e.g. sha1, sha256)',
		     )
		     ->addOption(
			     'hash',
			     null,
			     InputOption::VALUE_REQUIRED,
			     'Only the group of this checksum, in hexadecimal',
		     )
		     ->addOption(
			     'min-count',
			     'm',
			     InputOption::VALUE_REQUIRED,
			     'Only the groups of at least this many copies (default: 2)',
			     2,
		     )
		     ->addOption(
			     'path',
			     null,
			     InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
			     'Only copies whose path the pattern matches may go; repeat it for several',
		     )
		     ->addOption(
			     'no-path',
			     null,
			     InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
			     'Copies whose path the pattern matches stay; repeat it for several',
		     )
		     ->addOption(
			     'keep',
			     null,
			     InputOption::VALUE_REQUIRED,
			     'The copy that stays: oldest, newest, or first by its path (default: oldest)',
		     )
		     ->addOption(
			     'remove-last',
			     null,
			     InputOption::VALUE_NONE,
			     'Keep no copy: every copy the paths let go, goes',
		     )
		     ->addOption(
			     'permanent',
			     null,
			     InputOption::VALUE_NONE,
			     'Delete for good, past the trash',
		     )
		     ->addOption(
			     'no-merge',
			     null,
			     InputOption::VALUE_NONE,
			     'Let the deleted files\' versions go, rather than merge them into the copy that stays',
		     )
		     ->addOption(
			     'dry-run',
			     null,
			     InputOption::VALUE_NONE,
			     'Report what would go, and delete nothing',
		     )
		     ->addOption(
			     'yes',
			     'y',
			     InputOption::VALUE_NONE,
			     'Delete without asking first',
		     )
		     ->addOption(
			     'output',
			     'o',
			     InputOption::VALUE_REQUIRED,
			     'Output format: plain, json, json_pretty (default: plain)',
			     'plain',
		     )
		     ->setHelp(
			     <<<'HELP'
The <info>%command.name%</info> command resolves an account's duplicates as the Duplicates
page's <comment>Select duplicates</comment> does: every copy of a group goes but one, the oldest by
when it last changed, the first path where two are as old. Where the account
may not delete one of a group's copies, that one stays, and no other is kept.

A copy is a file outside the trash. A group of versions alone keeps its oldest
version; a version beside a file, and anything in the trash, is no copy, and
goes with the rest.

What goes, goes to the trash, or for good with <info>--permanent</info>; a version and
anything in the trash always for good. The versions of a file that goes are
merged into the copy that stays, unless <info>--no-merge</info>.

<comment>On a terminal it shows what would go and asks first</comment>, unless <info>-y</info>; without one,
the answer is no and nothing goes. <info>--dry-run</info> reports and deletes nothing.

<info>--path</info> lets only the copies it matches go, and <info>--no-path</info> keeps the ones it
matches; both repeat. Where both match a copy, the pattern naming more folders
wins, <info>/Backup/keep</info> over <info>/Backup</info>, either over <info>*.bak</info>; a tie keeps the copy.
Where the paths take every copy of a group, <info>--keep</info> picks the one that stays,
and <info>--remove-last</info> keeps none.

Patterns: <info>*</info> within a name, <info>**</info> across folders, <info>?</info> one character; without a
<info>/</info> a name anywhere, with one a path from the account's root; case is ignored.

Exit code: 0 where every copy went, or would; 1 where one was refused or
failed, or the question was answered no; 2 where the command could not run.

Examples:

  <info>occ fcias:resolve-duplicates -u alice --dry-run</info>
  <info>occ fcias:resolve-duplicates -u alice --path=/Backup --no-path=/Backup/keep</info>
  <info>occ fcias:resolve-duplicates --all --keep=newest -y -o json</info>
HELP,
		     )
		;
	}

	/** @noinspection PhpUnused */
	#[\Override]
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int
	{
		$this->logger->info(
			'FCIAS ResolveDuplicates command: invoked',
			[
				'app'    => Application::APP_ID,
				'users'  => $input->getOption( 'user' ),
				'all'    => $input->getOption( 'all' ),
				'dryRun' => $input->getOption( 'dry-run' ),
			],
		);

		$format  = (string) $input->getOption( 'output' );
		$keep    = $input->getOption( 'keep' );
		$algo    = strtolower( trim( (string) $input->getOption( 'algo' ) ) );
		$hash    = strtolower( trim( (string) $input->getOption( 'hash' ) ) );
		$dryRun  = (bool) $input->getOption( 'dry-run' );
		$forGood = (bool) $input->getOption( 'permanent' );
		$asking  = ! $dryRun && ! $input->getOption( 'yes' );
		$plain   = $format === 'plain';

		try
		{
			$this->assertRunnable( $input, $format, $keep, $hash );

			$users   = $this->usersOf( $input );
			$paths   = $this->patternsOf( (array) $input->getOption( 'path' ) );
			$noPaths = $this->patternsOf( (array) $input->getOption( 'no-path' ) );
		}
		catch ( InvalidArgumentException $e )
		{
			$output->writeln( sprintf( '<error>%s</error>', $e->getMessage() ) );

			return Command::INVALID;
		}

		$choice = new DuplicateChoice(
			$input->getOption( 'remove-last' )
				? null
				: $keep ?? DuplicateChoice::KEEP_OLDEST,
			$paths,
			$noPaths,
		);

		$accounts = [];

		foreach ( $users as $user )
		{
			$account = [
				'user'     => $user->getUID(),
				'declined' => false,
				'groups'   => $this->resolve(
					$user,
					$choice,
					$algo === '' ? null : $algo,
					$hash === '' ? null : $hash,
					max( 2, (int) $input->getOption( 'min-count' ) ),
					$paths,
					! $input->getOption( 'no-merge' ),
				),
			];

			if ( $account['groups'] !== [] && ( $dryRun || $asking ) )
			{
				$account['groups'] = $this->apply( $user, $account['groups'], $forGood, true );

				if ( $plain )
				{
					$this->writeAccount( $output, $account, true );
				}
			}

			// Asked, a run whose every row was refused has nothing to ask
			// about: the dry run's answer is the account's.
			if ( $account['groups'] !== [] && ! $dryRun && ( ! $asking || self::readyIn( $account ) > 0 ) )
			{
				$account['declined'] = $asking && ! $this->agreed( $input, $output, $account, $forGood );

				if ( ! $account['declined'] )
				{
					$account['groups'] = $this->apply( $user, $account['groups'], $forGood, false );

					if ( $plain )
					{
						$this->writeAccount( $output, $account, false );
					}
				}
			}

			$accounts[] = $account;
		}

		$summary = $this->summaryOf( $accounts );

		if ( $plain )
		{
			$this->writeSummary( $output, $summary, $dryRun, $input->isInteractive() );
		}
		else
		{
			$this->writeJson( $output, $format, $accounts, $summary, $dryRun );
		}

		return $summary['refused'] + $summary['failed'] + $summary['declined'] > 0
			? Command::FAILURE
			: Command::SUCCESS;
	}


//  static methods

	/**
	 * Where a row goes in the order: a version before the file it is of,
	 * which would take it along, and a trashed row before a live one.
	 *
	 * @param  array<string, mixed>  $row
	 */
	private static function orderOf( array $row ): int
	{
		$kind = match ( true )
		{
			isset( $row['version'], $row['trash'] ) => DuplicateActionService::KIND_TRASHED_VERSION,
			isset( $row['version'] )                => DuplicateActionService::KIND_VERSION,
			isset( $row['trash'] )                  => DuplicateActionService::KIND_TRASH,
			default                                 => DuplicateActionService::KIND_FILE,
		};

		return self::ORDER[ $kind ];
	}

	/**
	 * The copy that takes the versions of a group's files that go, as the
	 * page picks it: of the files that stay and may take them, the one, or
	 * the most recently changed where several do.
	 *
	 * @param  list<array<string, mixed>>  $stays
	 */
	private static function mergeTargetOf( array $stays ): ?int
	{
		$target = null;

		foreach ( $stays as $row )
		{
			if ( isset( $row['version'] ) || isset( $row['trash'] ) || ! in_array( DuplicateActionService::ACTION_MERGE_INTO, (array) ( $row['actions'] ?? [] ), true ) )
			{
				continue;
			}

			if ( $target === null || (int) ( $row['mtime'] ?? 0 ) > (int) ( $target['mtime'] ?? 0 ) )
			{
				$target = $row;
			}
		}

		return $target === null
			? null
			: (int) $target['fileid'];
	}

	/**
	 * How many of the account's rows the dry run found ready to go.
	 *
	 * @param  array{user: string, declined: bool, groups: list<array<string, mixed>>}  $account
	 */
	private static function readyIn( array $account ): int
	{
		$ready = 0;

		foreach ( $account['groups'] as $group )
		{
			$ready += count( array_filter( (array) ( $group['results'] ?? [] ), static fn ( array $result ): bool => $result['status'] === DuplicateActionService::STATUS_READY ) );
		}

		return $ready;
	}

	/**
	 * Of a row's result, what is said after its path: why it was refused or
	 * failed, or what became of its versions.
	 *
	 * @param  array<string, mixed>  $result
	 */
	private static function noteOf(
		array $result,
		?int  $mergeInto,
	): string
	{
		$merge    = $result['merge'] ?? null;
		$versions = count( (array) ( $result['versions'] ?? [] ) );

		return match ( true )
		{
			isset( $result['reason'] )                         => ': ' . (string) $result['reason'],
			is_array( $merge ) && $merge['failed']             => ' (its versions could not be merged)',
			is_array( $merge ) && $merge['imported'] > 0       => sprintf( ' (%d versions into [%d])', $merge['imported'], (int) $mergeInto ),
			$versions > 0                                      => sprintf( ' (%d versions with it)', $versions ),
			default                                            => '',
		};
	}


//  other non-static methods

	/**
	 * @throws InvalidArgumentException  Where the options cannot be run.
	 */
	private function assertRunnable(
		InputInterface $input,
		string         $format,
		?string        $keep,
		string         $hash,
	): void
	{
		$refusal = match ( true )
		{
			! in_array( $format, self::OUTPUTS, true )                          => 'The output format is plain, json or json_pretty.',
			$keep !== null && ! in_array( $keep, DuplicateChoice::KEEPS, true ) => 'The copy that stays is oldest, newest or first.',
			$keep !== null && $input->getOption( 'remove-last' )                => '--remove-last keeps no copy, so it cannot be given with --keep.',
			$hash !== '' && preg_match( '/^[0-9a-f]+$/', $hash ) !== 1          => 'The checksum is hexadecimal.',
			default                                                             => null,
		};

		if ( $refusal !== null )
		{
			throw new InvalidArgumentException( $refusal );
		}
	}

	/**
	 * The accounts named, or every one with --all.
	 *
	 * @return list<IUser>
	 * @throws InvalidArgumentException  Where neither or both are given, or
	 *                                   a named account does not exist.
	 */
	private function usersOf( InputInterface $input ): array
	{
		$uids = array_values( array_unique( array_filter( array_map( 'trim', (array) $input->getOption( 'user' ) ) ) ) );
		$all  = (bool) $input->getOption( 'all' );

		if ( $all === ( $uids !== [] ) )
		{
			throw new InvalidArgumentException( 'Name the accounts with --user, or give --all for every one.' );
		}

		$users = [];

		if ( $all )
		{
			$this->userManager->callForAllUsers(
				static function( IUser $user ) use ( &$users ): void
				{
					$users[] = $user;
				},
			);

			return $users;
		}

		foreach ( $uids as $uid )
		{
			$user = $this->userManager->get( $uid );

			if ( $user === null )
			{
				throw new InvalidArgumentException( sprintf( 'User "%s" not found.', $uid ) );
			}

			$users[] = $user;
		}

		return $users;
	}

	/**
	 * The patterns given, compiled, an empty one dropped.
	 *
	 * @param  list<string>  $patterns
	 *
	 * @return list<PathPattern>
	 * @throws InvalidArgumentException  Where one is too long.
	 */
	private function patternsOf( array $patterns ): array
	{
		$compiled = [];

		foreach ( $patterns as $pattern )
		{
			if ( strlen( $pattern ) > PathPattern::MAX_LENGTH )
			{
				throw new InvalidArgumentException( sprintf( 'A path pattern is at most %d characters.', PathPattern::MAX_LENGTH ) );
			}

			$compiled[] = PathPattern::of( $pattern );
		}

		return array_values( array_filter( $compiled ) );
	}

	/**
	 * What one account's duplicates come to: per group with a row to go,
	 * those rows in the order they go, the rows that stay, and the copy that
	 * takes the versions.
	 *
	 * A file listed in two groups, under two algorithms, goes once, and
	 * stays everywhere where one group keeps it.
	 *
	 * @param  list<PathPattern>  $paths  The groups are listed per pattern
	 *                                    and joined; none, all of them.
	 * @param  bool               $merge  Whether a copy that stays takes
	 *                                    the versions.
	 *
	 * @return list<array{algo: string, hash_value: string, keeper: ?int, keepsNone: bool, mergeInto: ?int, going: list<int>, paths: array<int, string>, stays: list<array{fileid: int, path: string}>}>
	 */
	private function resolve(
		IUser           $user,
		DuplicateChoice $choice,
		?string         $algo,
		?string         $hash,
		int             $minCount,
		array           $paths,
		bool            $merge,
	): array
	{
		$groups  = $this->duplicateActions->withActions( $user, $this->groupsOf( $user->getUID(), $algo, $hash, $minCount, $paths ) );
		$choices = array_map( static fn ( array $group ): array => $choice->of( $group ), $groups );
		$kept    = array_filter( array_column( $choices, 'keeper' ) );
		$going   = [];
		$taken   = [];

		foreach ( $groups as $i => $group )
		{
			$going[ $i ] = array_values( array_diff( $choices[ $i ]['going'], $kept, $taken ) );
			$taken       = [ ...$taken, ...$going[ $i ] ];
		}

		$resolved = [];

		foreach ( $groups as $i => $group )
		{
			if ( $going[ $i ] === [] )
			{
				continue;
			}

			$rows = array_column( $group['files'], null, 'fileid' );
			usort( $going[ $i ], static fn ( int $a, int $b ): int => self::orderOf( $rows[ $a ] ) <=> self::orderOf( $rows[ $b ] ) );

			$best  = array_column( DuplicateChoice::bestCopies( $group ), 'fileid' );
			$stays = array_values( array_filter( $group['files'], static fn ( array $row ): bool => ! in_array( (int) $row['fileid'], $taken, true ) ) );

			$resolved[] = [
				'algo'       => $group['algo'],
				'hash_value' => $group['hash_value'],
				'keeper'     => $choices[ $i ]['keeper'],
				'keepsNone'  => $best !== [] && array_diff( $best, $taken ) === [],
				'mergeInto'  => $merge
					? self::mergeTargetOf( $stays )
					: null,
				'going'      => $going[ $i ],
				'paths'      => array_map( static fn ( array $row ): string => (string) $row['path'], $rows ),
				'stays'      => array_map( static fn ( array $row ): array => [ 'fileid' => (int) $row['fileid'], 'path' => (string) $row['path'] ], $stays ),
			];
		}

		return $resolved;
	}

	/**
	 * Every duplicate group of the account, from every page of the listing;
	 * with patterns, the groups each lists, joined by algorithm and hash.
	 *
	 * @param  list<PathPattern>  $paths
	 *
	 * @return list<array<string, mixed>>
	 */
	private function groupsOf(
		string  $uid,
		?string $algo,
		?string $hash,
		int     $minCount,
		array   $paths,
	): array
	{
		$groups = [];

		foreach ( $paths === [] ? [ null ] : $paths as $pattern )
		{
			$offset = 0;

			do
			{
				$page = $this->hashIndexService->listDuplicatesForUser( $uid, $algo, $minCount, self::PAGE_SIZE, $offset, $hash, path: $pattern );

				foreach ( $page['duplicates'] as $group )
				{
					$groups[ $group['algo'] . ':' . $group['hash_value'] ] ??= $group;
				}

				$offset = $page['pagination']['next_offset'];
			}
			while ( $offset !== null );
		}

		return array_values( $groups );
	}

	/**
	 * The rows of the account's groups handed to the service together, as a
	 * dry run or not, so that they share one queue: copies sharing a name go
	 * to the trash a second apart, between the others. Each group gains
	 * `results`, the service's answer per row, with its path.
	 *
	 * @param  list<array<string, mixed>>  $groups  As {@see resolve()} gives them.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function apply(
		IUser $user,
		array $groups,
		bool  $forGood,
		bool  $dryRun,
	): array
	{
		$answers = $this->duplicateActions->deleteGroups(
			$user,
			array_map(
				static fn ( array $group ): array => [
					'fileIds'   => $group['going'],
					'algo'      => $group['algo'],
					'hash'      => $group['hash_value'],
					'mergeInto' => $group['mergeInto'],
				],
				$groups,
			),
			$forGood,
			$dryRun,
		);

		foreach ( $groups as $i => &$group )
		{
			$group['results'] = array_map(
				static fn ( array $result ): array => $result + [ 'path' => $group['paths'][ $result['fileid'] ] ?? '' ],
				$answers[ $i ] ?? [],
			);
		}

		unset( $group );

		return $groups;
	}

	/**
	 * Whether the account's rows are to go: asked on a terminal, with how
	 * many would; no without one.
	 *
	 * @param  array{user: string, declined: bool, groups: list<array<string, mixed>>}  $account  With the dry run's results.
	 */
	private function agreed(
		InputInterface  $input,
		OutputInterface $output,
		array           $account,
		bool            $forGood,
	): bool
	{
		if ( ! $input->isInteractive() )
		{
			return false;
		}

		$rows      = self::readyIn( $account );
		$keepsNone = count( array_filter( array_column( $account['groups'], 'keepsNone' ) ) );

		/** @var QuestionHelper $question Symfony registers it under this name. */
		$question = $this->getHelper( 'question' );

		return (bool) $question->ask(
			$input,
			$output,
			new ConfirmationQuestion(
				sprintf(
					'Delete %d %s of %s\'s %s%s? [y/N] ',
					$rows,
					$rows === 1 ? 'copy' : 'copies',
					$account['user'],
					$forGood ? 'for good' : 'to the trash',
					$keepsNone > 0
						? sprintf( ', keeping no copy of %d %s', $keepsNone, $keepsNone === 1 ? 'group' : 'groups' )
						: '',
				),
				false,
			),
		);
	}

	/**
	 * @param  list<array{user: string, declined: bool, groups: list<array<string, mixed>>}>  $accounts
	 *
	 * @return array{accounts: int, groups: int, rows: int, refused: int, failed: int, keepsNone: int, declined: int}
	 */
	private function summaryOf( array $accounts ): array
	{
		$summary = [
			'accounts'  => 0,
			'groups'    => 0,
			'rows'      => 0,
			'refused'   => 0,
			'failed'    => 0,
			'keepsNone' => 0,
			'declined'  => 0,
		];

		foreach ( $accounts as $account )
		{
			if ( $account['declined'] )
			{
				$summary['declined'] ++;

				continue;
			}

			$summary['accounts'] += $account['groups'] === [] ? 0 : 1;

			foreach ( $account['groups'] as $group )
			{
				$statuses = array_count_values( array_column( $group['results'], 'status' ) );
				$refused  = $statuses[ DuplicateActionService::STATUS_REFUSED ] ?? 0;
				$failed   = $statuses[ DuplicateActionService::STATUS_FAILED ] ?? 0;

				$summary['groups']    ++;
				$summary['rows'] += count( $group['results'] ) - $refused - $failed;
				$summary['refused'] += $refused;
				$summary['failed'] += $failed;
				$summary['keepsNone'] += $group['keepsNone'] ? 1 : 0;
			}
		}

		return $summary;
	}

	/**
	 * One account's groups: per group the rows that stay, the copy kept
	 * among them, and per row that goes what would become, or became, of it.
	 *
	 * @param  array{user: string, declined: bool, groups: list<array<string, mixed>>}  $account
	 */
	private function writeAccount(
		OutputInterface $output,
		array           $account,
		bool            $dryRun,
	): void
	{
		$output->writeln( sprintf( '<comment>%s</comment>', $account['user'] ) );

		foreach ( $account['groups'] as $group )
		{
			$output->writeln( sprintf( '  <info>%s / %s</info>%s', strtoupper( $group['algo'] ), $group['hash_value'], $group['keepsNone'] ? ' (no copy stays)' : '' ) );

			foreach ( $group['stays'] as $row )
			{
				$output->writeln( sprintf( '    %-8s [%d] %s', $row['fileid'] === $group['keeper'] ? 'kept' : 'stays', $row['fileid'], $row['path'] ) );
			}

			foreach ( $group['results'] as $result )
			{
				$output->writeln(
					sprintf(
						'    %-8s [%d] %s%s',
						$dryRun && $result['status'] === DuplicateActionService::STATUS_READY
							? (string) ( $result['action'] ?? '' )
							: (string) $result['status'],
						$result['fileid'],
						$result['path'],
						self::noteOf( $result, $group['mergeInto'] ),
					),
				);
			}
		}

		$output->writeln( '' );
	}

	/**
	 * @param  array{accounts: int, groups: int, rows: int, refused: int, failed: int, keepsNone: int, declined: int}  $summary
	 */
	private function writeSummary(
		OutputInterface $output,
		array           $summary,
		bool            $dryRun,
		bool            $interactive,
	): void
	{
		if ( $summary['groups'] === 0 && $summary['declined'] === 0 )
		{
			$output->writeln( 'No duplicates to resolve.' );

			return;
		}

		if ( $summary['groups'] > 0 )
		{
			$output->writeln(
				sprintf(
					'%s: %d, in %d groups of %d accounts. Refused: %d.%s',
					$dryRun ? 'Would go' : 'Gone',
					$summary['rows'],
					$summary['groups'],
					$summary['accounts'],
					$summary['refused'],
					$summary['failed'] > 0
						? sprintf( ' Failed: %d.', $summary['failed'] )
						: '',
				),
			);
		}

		if ( $summary['keepsNone'] > 0 )
		{
			$output->writeln( sprintf( 'Groups keeping no copy: %d.', $summary['keepsNone'] ) );
		}

		if ( $summary['declined'] > 0 )
		{
			$output->writeln(
				$interactive
					? sprintf( 'Left as they were, the question answered no: %d accounts.', $summary['declined'] )
					: sprintf( 'Left as they were: %d accounts. Without a terminal to ask on, the answer is no; -y deletes without asking.', $summary['declined'] ),
			);
		}

		if ( $dryRun )
		{
			$output->writeln( 'Nothing was deleted: --dry-run.' );
		}
	}

	/**
	 * @param  list<array{user: string, declined: bool, groups: list<array<string, mixed>>}>  $accounts
	 * @param  array<string, int>  $summary
	 */
	private function writeJson(
		OutputInterface $output,
		string          $format,
		array           $accounts,
		array           $summary,
		bool            $dryRun,
	): void
	{
		$output->writeln(
			(string) json_encode(
				[
					'dryRun'   => $dryRun,
					'accounts' => array_map(
						static fn ( array $account ): array => [
							'groups' => array_map(
								static fn ( array $group ): array => array_diff_key( $group, [ 'going' => true, 'paths' => true ] ),
								$account['groups'],
							),
						] + $account,
						$accounts,
					),
					'summary'  => $summary,
				],
				$format === 'json_pretty'
					? JSON_PRETTY_PRINT
					: 0,
			),
		);
	}
}
