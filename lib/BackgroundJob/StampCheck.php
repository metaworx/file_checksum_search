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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks the files' stamps a slice per cron run, one of two walks.
 *
 * - {@see WALK_UNSTAMPED}: gives every file whose hashes carry no stamp one.
 *   A recalculation used to save hashes without `file-checksum-updated_at`:
 *   only the queue stamped. Such a file counts as never hashed, and a single
 *   `occ fcias:hash` run could leave tens of thousands of them. The stamp
 *   is {@see MetadataService::stampUnstampedAfter()}'s to decide.
 * - {@see WALK_ETAG}: gives the stamps without an ETag the one their hashes
 *   were read against, where adoption lets it, and their rows back from the
 *   documents ({@see MetadataService::adoptEtagsAfter()}).
 *
 * Either keeps the work out of the request that upgrades the app. Each run
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

	/** The walk over hashes without a stamp; the job's argument is null. */
	public const WALK_UNSTAMPED = 'unstamped';

	/** The walk over stamps without an ETag. */
	public const WALK_ETAG = 'etag';

	/** Seconds one run spends walking before it hands over to the next. */
	public const TIME_BUDGET = 30;

	/** Files per page. */
	public const PAGE_SIZE = 500;


//  constructor

	public function __construct(
		ITimeFactory                     $time,
		private readonly MetadataService $metadataService,
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
	 * @param  string  $walk  {@see WALK_UNSTAMPED} or {@see WALK_ETAG}.
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
	 * The job's argument for a walk: none for the unstamped walk, as before
	 * there were two, so that a walk queued then is still found.
	 *
	 * @return array{walk: string}|null
	 */
	private static function argumentOf( string $walk ): ?array
	{
		return $walk === self::WALK_ETAG
			? [ 'walk' => self::WALK_ETAG ]
			: null;
	}

	private static function cursorOf( string $walk ): string
	{
		return $walk === self::WALK_ETAG
			? self::CURSOR_ETAG
			: self::CURSOR;
	}


//  config/init/exe/run methods

	/**
	 * @param  mixed  $argument  Which walk ({@see argumentOf()}); the place
	 *                           is kept in app config.
	 */
	#[\Override]
	protected function run( $argument ): void
	{
		$walk     = is_array( $argument ) && ( $argument['walk'] ?? null ) === self::WALK_ETAG
			? self::WALK_ETAG
			: self::WALK_UNSTAMPED;
		$cursor   = self::cursorOf( $walk );
		$after    = $this->appConfig->getValueInt( Application::APP_ID, $cursor, 0 );
		$deadline = $this->time->getTime() + self::TIME_BUDGET;
		$started  = hrtime( true );
		$continue = fn(): bool => $this->time->getTime() < $deadline;

		try
		{
			if ( $walk === self::WALK_ETAG )
			{
				$adopted = $this->metadataService->adoptEtagsAfter( $after, self::PAGE_SIZE, $continue );
				$result  = [
					'stamped' => $adopted['adopted'],
					'queued'  => $adopted['queued'],
					'last'    => $adopted['last'],
					'done'    => $adopted['done'],
				];
			}
			else
			{
				$result = $this->metadataService->stampUnstampedAfter( $after, self::PAGE_SIZE, $continue );
			}
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
				JobStatsService::JOB_STAMP_CHECK,
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
			JobStatsService::JOB_STAMP_CHECK,
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
			$walk === self::WALK_ETAG
				? 'FCIAS StampCheck: gave the stamps of {stamped} files their ETag, and queued {queued} to be computed again{rest}'
				: 'FCIAS StampCheck: stamped the hashes of {stamped} files, {queued} of them queued to be computed again{rest}',
			[
				'app'     => Application::APP_ID,
				'stamped' => $result['stamped'],
				'queued'  => $result['queued'],
				'rest'    => match ( true )
				{
					! $result['done']           => sprintf( ', up to file %d; continuing on the next run', $result['last'] ),
					$walk === self::WALK_ETAG   => '; every stamp that can carry an ETag does',
					default                     => '; every stored hash carries a stamp',
				},
			],
		);
	}
}
