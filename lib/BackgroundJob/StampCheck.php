<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks the files' stamps a slice per cron run, one of three walks.
 *
 * - {@see WALK_UNSTAMPED}: gives every file whose hashes carry no stamp one.
 *   A recalculation used to save hashes without `file-checksum-updated_at`:
 *   only the queue stamped. Such a file counts as never hashed, and a single
 *   `occ fcias:hash` run could leave tens of thousands of them. The stamp
 *   is {@see MetadataService::stampUnstampedAfter()}'s to decide.
 * - {@see WALK_ETAG}: gives the stamps without an ETag the one their hashes
 *   were read against, where adoption lets it, and their rows back from the
 *   documents ({@see MetadataService::adoptEtagsAfter()}).
 * - {@see WALK_FILECACHE}: treats as written the files whose stamp no
 *   longer vouches for their hashes, the filecache's ETag having moved on
 *   without the app hearing of a write, and queues a file marked gone that
 *   its storage has again ({@see RuleService::queueChangedAfter()}).
 *   Queued once a day by {@see RuleProcessingJob}.
 *
 * Each keeps its work out of the request that queues it. Each run
 * walks until {@see TIME_BUDGET} seconds have passed, keeps its place in
 * the walk's cursor, and queues itself again; the last one clears the
 * cursor. A file the walk fixes leaves the set it reads, so a run cut short
 * repeats nothing.
 */
class StampCheck
    extends
    QueuedJob
{

//  constants

	/** App config key: the last file id the unstamped walk read; absent until it starts. */
	public const CURSOR = 'stamp_check_after';

	/** App config key: the last file id the ETag walk read; absent until it starts. */
	public const CURSOR_ETAG = 'stamp_check_etag_after';

	/** App config key: the last file id the filecache walk read; absent until it starts. */
	public const CURSOR_FILECACHE = 'stamp_check_filecache_after';

	/** The walk over hashes without a stamp; the job's argument is null. */
	public const WALK_UNSTAMPED = 'unstamped';

	/** The walk over stamps without an ETag. */
	public const WALK_ETAG = 'etag';

	/** The walk over stamps the filecache's ETag has moved on from. */
	public const WALK_FILECACHE = 'filecache';

	/** Seconds one run spends walking before it hands over to the next. */
	public const TIME_BUDGET = 30;

	/** Files per page. */
	public const PAGE_SIZE = 500;


//  constructor

	public function __construct(
		ITimeFactory                     $time,
		private readonly MetadataService $metadataService,
		private readonly RuleService     $ruleService,
		private readonly IAppConfig      $appConfig,
		private readonly IJobList        $jobList,
		private readonly JobStatsService $jobStats,
		private readonly LoggerInterface $logger,
	)
	{
		parent::__construct( $time );
	}


//  static methods

	/**
	 * Queue a walk from the start, unless one is already queued.
	 *
	 * @param  string  $walk  {@see WALK_UNSTAMPED}, {@see WALK_ETAG} or
	 *                        {@see WALK_FILECACHE}.
	 *
	 * @return bool  Whether this call queued it; false when a walk was
	 *               already waiting, which keeps its place.
	 */
	public static function queue(
		IJobList   $jobList,
		IAppConfig $appConfig,
		string     $walk = self::WALK_UNSTAMPED,
	): bool
	{
		$argument = self::argumentOf( $walk );

		if ( $jobList->has( self::class, $argument ) )
		{
			return false;
		}

		$appConfig->deleteKey( Application::APP_ID, self::cursorOf( $walk ) );
		$jobList->add( self::class, $argument );

		return true;
	}

	/**
	 * Whether a walk is queued, or under way between two of its runs: its
	 * job is waiting, or its cursor says it has not reached the end.
	 */
	public static function isPending(
		IJobList   $jobList,
		IAppConfig $appConfig,
		string     $walk,
	): bool
	{
		return $jobList->has( self::class, self::argumentOf( $walk ) )
			|| $appConfig->hasKey( Application::APP_ID, self::cursorOf( $walk ) );
	}

	/**
	 * The stats slot a walk records its runs in: one each, so that one
	 * walk's run never stands for another's.
	 */
	private static function jobOf( string $walk ): string
	{
		return match ( $walk )
		{
			self::WALK_ETAG      => JobStatsService::JOB_STAMP_CHECK_ETAG,
			self::WALK_FILECACHE => JobStatsService::JOB_STAMP_CHECK_FILECACHE,
			default              => JobStatsService::JOB_STAMP_CHECK,
		};
	}

	/**
	 * The job's argument for a walk: none for the unstamped walk, as before
	 * there were two, so that a walk queued then is still found.
	 *
	 * @return array{walk: string}|null
	 */
	private static function argumentOf( string $walk ): ?array
	{
		return in_array( $walk, [ self::WALK_ETAG, self::WALK_FILECACHE ], true )
			? [ 'walk' => $walk ]
			: null;
	}

	private static function cursorOf( string $walk ): string
	{
		return match ( $walk )
		{
			self::WALK_ETAG      => self::CURSOR_ETAG,
			self::WALK_FILECACHE => self::CURSOR_FILECACHE,
			default              => self::CURSOR,
		};
	}

	/**
	 * A walk's result in the terms the status shows for every walk: files
	 * stamped, files queued again, how far it got, and whether it is done.
	 *
	 * @param  array<string, int|bool>  $result
	 * @param  string|null              $stamped  The count of files stamped, if any.
	 * @param  list<string>             $queued   The counts of files that lost their
	 *                                            hashes until computed again.
	 *
	 * @return array{stamped: int, queued: int, last: int, done: bool}
	 */
	private static function counted(
		array   $result,
		?string $stamped,
		array   $queued,
	): array
	{
		return [
			'stamped' => $stamped === null
				? 0
				: (int) $result[ $stamped ],
			'queued'  => array_sum( array_map( static fn( string $key ): int => (int) $result[ $key ], $queued ) ),
			'last'    => (int) $result['last'],
			'done'    => (bool) $result['done'],
		];
	}

	/**
	 * The walk a job's argument names; none is the unstamped walk.
	 */
	private static function walkOf( mixed $argument ): string
	{
		$walk = is_array( $argument )
			? $argument['walk'] ?? null
			: null;

		return in_array( $walk, [ self::WALK_ETAG, self::WALK_FILECACHE ], true )
			? $walk
			: self::WALK_UNSTAMPED;
	}


//  config/init/exe/run methods

	/**
	 * @param  mixed  $argument  Which walk ({@see argumentOf()}); the place
	 *                           is kept in app config.
	 */
	#[\Override]
	protected function run( $argument ): void
	{
		$walk = self::walkOf( $argument );

		// Stamps without an ETag are the ETag walk's to judge first: it keeps
		// those the filecache's checksums vouch for, which this walk would
		// take out of the index or erode. The daily check comes round again
		// on the first tick after it.
		if ( $walk === self::WALK_FILECACHE && self::isPending( $this->jobList, $this->appConfig, self::WALK_ETAG ) )
		{
			$this->appConfig->setValueInt( Application::APP_ID, RuleProcessingJob::STAMP_CHECK_LAST_QUEUED, 0 );
			$this->logger->info(
				'FCIAS StampCheck: the filecache walk waits for the ETag walk, and is queued again after it.',
				[ 'app' => Application::APP_ID ],
			);

			return;
		}

		$cursor   = self::cursorOf( $walk );
		$after    = $this->appConfig->getValueInt( Application::APP_ID, $cursor, 0 );
		$deadline = $this->time->getTime() + self::TIME_BUDGET;
		$started  = hrtime( true );
		$continue = fn(): bool => $this->time->getTime() < $deadline;

		try
		{
			$result = match ( $walk )
			{
				self::WALK_ETAG      => self::counted(
					$this->metadataService->adoptEtagsAfter( $after, self::PAGE_SIZE, $continue, modeOf: $this->ruleService->queueModeFor( ... ) ),
					'adopted',
					[ 'queued' ],
				),
				// An eroded file lost its hashes as a queued one did, without
				// a rule to compute them again.
				self::WALK_FILECACHE => self::counted( $this->ruleService->queueChangedAfter( $after, self::PAGE_SIZE, $continue ), null, [ 'eroded', 'queued' ] ),
				default              => $this->metadataService->stampUnstampedAfter( $after, self::PAGE_SIZE, $continue ),
			};
		}
		catch ( Throwable $e )
		{
			// The cursor stays where it was: the next repair queues the walk
			// again, and it carries on from there.
			$this->logger->error(
				'FCIAS StampCheck: the {walk} walk stopped after file {after}',
				[
					'app'       => Application::APP_ID,
					'walk'      => $walk,
					'after'     => $after,
					'exception' => $e,
				],
			);

			$this->jobStats->recordFailure(
				self::jobOf( $walk ),
				$e,
				JobStatsService::millisecondsSince( $started ),
			);

			return;
		}

		if ( $result['done'] )
		{
			$this->appConfig->deleteKey( Application::APP_ID, $cursor );
		}
		else
		{
			$this->appConfig->setValueInt( Application::APP_ID, $cursor, $result['last'] );
			$this->jobList->add( self::class, self::argumentOf( $walk ) );
		}

		$this->jobStats->record(
			self::jobOf( $walk ),
			[
				'stamped' => $result['stamped'],
				'queued'  => $result['queued'],
				'done'    => $result['done']
					? 1
					: 0,
			],
			JobStatsService::millisecondsSince( $started ),
		);

		$this->logger->info(
			match ( $walk )
			{
				self::WALK_ETAG      => 'FCIAS StampCheck: gave the stamps of {stamped} files their ETag, and queued {queued} to be computed again{rest}',
				self::WALK_FILECACHE => 'FCIAS StampCheck: took the hashes of {queued} files changed without a write the app heard of out{rest}',
				default              => 'FCIAS StampCheck: stamped the hashes of {stamped} files, {queued} of them queued to be computed again{rest}',
			},
			[
				'app'     => Application::APP_ID,
				'stamped' => $result['stamped'],
				'queued'  => $result['queued'],
				'rest'    => match ( true )
				{
					! $result['done']              => sprintf( ', up to file %d; continuing on the next run', $result['last'] ),
					$walk === self::WALK_ETAG      => '; every stamp that can carry an ETag does',
					$walk === self::WALK_FILECACHE => '; every listed stamp vouches for its hashes',
					default                        => '; every stored hash carries a stamp',
				},
			],
		);
	}
}
