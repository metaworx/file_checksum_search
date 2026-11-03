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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * An account's duplicates resolved from the shell, as the Duplicates page's
 * *Select duplicates* resolves them: every copy of a group but the one that
 * stays ({@see DuplicateChoice}), through the page's own service, which opens
 * each row through the account's own reach and re-checks its hash.
 *
 * The accounts are taken in turn, each through its own reach: a file two of
 * them reach is listed for both, and copies in two accounts are not compared.
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
			     'dry-run',
			     null,
			     InputOption::VALUE_NONE,
			     'Report what would go, and delete nothing',
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

<info>--path</info> lets only the copies it matches go, and <info>--no-path</info> keeps the ones it
matches; both repeat. Where both match a copy, the pattern naming more folders
wins, <info>/Backup/keep</info> over <info>/Backup</info>, either over <info>*.bak</info>; a tie keeps the copy.
Where the paths take every copy of a group, <info>--keep</info> picks the one that stays,
and <info>--remove-last</info> keeps none.

Patterns: <info>*</info> within a name, <info>**</info> across folders, <info>?</info> one character; without a
<info>/</info> a name anywhere, with one a path from the account's root; case is ignored.

Examples:

  <info>occ fcias:resolve-duplicates -u alice --dry-run</info>
  <info>occ fcias:resolve-duplicates -u alice --path=/Backup --no-path=/Backup/keep --dry-run</info>
  <info>occ fcias:resolve-duplicates --all --keep=newest --dry-run -o json</info>
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
				'app'   => Application::APP_ID,
				'users' => $input->getOption( 'user' ),
				'all'   => $input->getOption( 'all' ),
			],
		);

		$format = (string) $input->getOption( 'output' );
		$keep   = $input->getOption( 'keep' );
		$algo   = strtolower( trim( (string) $input->getOption( 'algo' ) ) );
		$hash   = strtolower( trim( (string) $input->getOption( 'hash' ) ) );

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
			$accounts[] = [
				'user'   => $user->getUID(),
				'groups' => $this->resolve(
					$user,
					$choice,
					$algo === '' ? null : $algo,
					$hash === '' ? null : $hash,
					max( 2, (int) $input->getOption( 'min-count' ) ),
					$paths,
				),
			];
		}

		$summary = $this->summaryOf( $accounts );

		$this->write( $output, $format, $accounts, $summary );

		return $summary['refused'] > 0
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
			! $input->getOption( 'dry-run' )                                    => 'Only --dry-run is available: it reports what would go.',
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
	 * One account's duplicates resolved: per group with a row to go, the
	 * service's answer for each such row, and the rows that stay.
	 *
	 * A file listed in two groups, under two algorithms, goes once, and
	 * stays everywhere where one group keeps it.
	 *
	 * @param  list<PathPattern>  $paths  The groups are listed per pattern
	 *                                    and joined; none, all of them.
	 *
	 * @return list<array{algo: string, hash_value: string, keeper: ?int, keepsNone: bool, results: list<array<string, mixed>>, stays: list<array{fileid: int, path: string}>}>
	 */
	private function resolve(
		IUser           $user,
		DuplicateChoice $choice,
		?string         $algo,
		?string         $hash,
		int             $minCount,
		array           $paths,
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

		$done = [];

		foreach ( $groups as $i => $group )
		{
			if ( $going[ $i ] === [] )
			{
				continue;
			}

			$rows = array_column( $group['files'], null, 'fileid' );
			usort( $going[ $i ], static fn ( int $a, int $b ): int => self::orderOf( $rows[ $a ] ) <=> self::orderOf( $rows[ $b ] ) );

			$results = [];

			foreach ( array_chunk( $going[ $i ], DuplicateActionService::MAX_ROWS ) as $part )
			{
				foreach ( $this->duplicateActions->delete( $user, $part, $group['algo'], $group['hash_value'], false, true ) as $result )
				{
					$results[] = $result + [ 'path' => (string) ( $rows[ $result['fileid'] ]['path'] ?? '' ) ];
				}
			}

			$best  = array_column( DuplicateChoice::bestCopies( $group ), 'fileid' );
			$stays = array_filter( $group['files'], static fn ( array $row ): bool => ! in_array( (int) $row['fileid'], $taken, true ) );

			$done[] = [
				'algo'       => $group['algo'],
				'hash_value' => $group['hash_value'],
				'keeper'     => $choices[ $i ]['keeper'],
				'keepsNone'  => $best !== [] && array_diff( $best, $taken ) === [],
				'results'    => $results,
				'stays'      => array_values( array_map( static fn ( array $row ): array => [ 'fileid' => (int) $row['fileid'], 'path' => (string) $row['path'] ], $stays ) ),
			];
		}

		return $done;
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
	 * @param  list<array{user: string, groups: list<array<string, mixed>>}>  $accounts
	 *
	 * @return array{accounts: int, groups: int, rows: int, refused: int, keepsNone: int}
	 */
	private function summaryOf( array $accounts ): array
	{
		$summary = [
			'accounts'  => 0,
			'groups'    => 0,
			'rows'      => 0,
			'refused'   => 0,
			'keepsNone' => 0,
		];

		foreach ( $accounts as $account )
		{
			$summary['accounts'] += $account['groups'] === [] ? 0 : 1;

			foreach ( $account['groups'] as $group )
			{
				$refused = count( array_filter( $group['results'], static fn ( array $result ): bool => $result['status'] === DuplicateActionService::STATUS_REFUSED ) );

				$summary['groups']    ++;
				$summary['rows'] += count( $group['results'] ) - $refused;
				$summary['refused'] += $refused;
				$summary['keepsNone'] += $group['keepsNone'] ? 1 : 0;
			}
		}

		return $summary;
	}

	/**
	 * @param  list<array{user: string, groups: list<array<string, mixed>>}>  $accounts
	 * @param  array{accounts: int, groups: int, rows: int, refused: int, keepsNone: int}  $summary
	 */
	private function write(
		OutputInterface $output,
		string          $format,
		array           $accounts,
		array           $summary,
	): void
	{
		if ( $format !== 'plain' )
		{
			$output->writeln(
				(string) json_encode(
					[
						'dryRun'   => true,
						'accounts' => $accounts,
						'summary'  => $summary,
					],
					$format === 'json_pretty'
						? JSON_PRETTY_PRINT
						: 0,
				),
			);

			return;
		}

		if ( $summary['groups'] === 0 )
		{
			$output->writeln( 'No duplicates to resolve.' );

			return;
		}

		foreach ( $accounts as $account )
		{
			if ( $account['groups'] === [] )
			{
				continue;
			}

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
					$refused = $result['status'] === DuplicateActionService::STATUS_REFUSED;

					$output->writeln(
						sprintf(
							'    %-8s [%d] %s%s',
							$refused ? 'refused' : (string) ( $result['action'] ?? $result['status'] ),
							$result['fileid'],
							$result['path'],
							$refused ? ': ' . (string) ( $result['reason'] ?? '' ) : '',
						),
					);
				}
			}

			$output->writeln( '' );
		}

		$output->writeln( sprintf( 'Would go: %d, in %d groups of %d accounts. Refused: %d.', $summary['rows'], $summary['groups'], $summary['accounts'], $summary['refused'] ) );

		if ( $summary['keepsNone'] > 0 )
		{
			$output->writeln( sprintf( 'Groups keeping no copy: %d.', $summary['keepsNone'] ) );
		}

		$output->writeln( 'Nothing was deleted: --dry-run.' );
	}
}
