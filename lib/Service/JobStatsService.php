<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use JsonException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Last-run bookkeeping for the background jobs (D17).
 *
 * Three app-config values per job — the last successful run's timestamp,
 * its small JSON counts object, and the last attempt: when, whether it
 * ended normally or with an exception, how long it took, and the
 * exception's class and message when it failed — written by the job at the
 * end of each run and read by the status surface. App-config-sized by
 * design: no schema, no history, just "when did it last run and what did it
 * do". An old timestamp is itself the signal that a job stopped running; a
 * failed attempt is the signal that it runs and fails, which the last
 * successful run alone would hide.
 *
 * Nextcloud 34 keeps a history of its own (`occ background-job:history`),
 * but it sees no failure: Nextcloud catches what a job throws, and these
 * jobs catch their own.
 *
 * Not readonly: the jobs' tests double this class, and PHPUnit cannot
 * mock readonly classes (TESTING.md §6.1).
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class JobStatsService
{

//  constants

	/** The periodic rule sweep (RuleProcessingJob). */
	public const JOB_RULE_SWEEP = 'rule_sweep';

	/** One rule applied on request, its Reapply (ApplyRuleJob). */
	public const JOB_RULE_APPLY = 'rule_apply';

	/** The pending-queue drain (ProcessPendingUpdates). */
	public const JOB_PENDING_DRAIN = 'pending_drain';

	/** The daily purge of metadata for files that no longer exist (rides RuleProcessingJob). */
	public const JOB_ORPHAN_PURGE = 'orphan_purge';

	/** The queued copy of the filecache's checksums (FilecacheBackfill). */
	public const JOB_FILECACHE_BACKFILL = 'filecache_backfill';

	/** The queued check that every stored hash has its index row (HashIndexCheck). */
	public const JOB_HASH_INDEX_CHECK = 'hash_index_check';

	/** The queued walk that gives every stored hash its stamp (StampCheck). */
	public const JOB_STAMP_CHECK = 'stamp_check';

	/**
	 * The hourly count of indexed checksums (rides RuleProcessingJob). Its
	 * record is also where the count is kept: `counts.rows`, as of its last
	 * run ({@see StatusService::getHashRowCount()}).
	 */
	public const JOB_CHECKSUM_COUNT = 'checksum_count';

	public const JOBS
		 = [
			self::JOB_RULE_SWEEP,
			self::JOB_RULE_APPLY,
			self::JOB_PENDING_DRAIN,
			self::JOB_ORPHAN_PURGE,
			self::JOB_FILECACHE_BACKFILL,
			self::JOB_HASH_INDEX_CHECK,
			self::JOB_STAMP_CHECK,
			self::JOB_CHECKSUM_COUNT,
		];

	/**
	 * What `occ …:status` calls each job. English, as every console line is;
	 * the admin page names them through its own translations.
	 */
	public const LABELS
		 = [
			self::JOB_RULE_SWEEP         => 'Rule sweep',
			self::JOB_RULE_APPLY         => 'Rule reapplication',
			self::JOB_PENDING_DRAIN      => 'Queue drain',
			self::JOB_ORPHAN_PURGE       => 'Orphan purge',
			self::JOB_FILECACHE_BACKFILL => 'Checksum copy',
			self::JOB_HASH_INDEX_CHECK   => 'Checksum index check',
			self::JOB_STAMP_CHECK        => 'Checksum stamp check',
			self::JOB_CHECKSUM_COUNT     => 'Checksum count',
		];

	/**
	 * How long a failed run's reason may be: the exception's class and
	 * message, on one line of the status.
	 */
	private const REASON_MAX_LENGTH = 200;


//  constructor

	public function __construct(
		private readonly IAppConfig      $appConfig,
		private readonly ITimeFactory    $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}


//  other non-static methods

	/**
	 * Record one completed run. Never throws: bookkeeping must not be able
	 * to fail the job it books.
	 *
	 * @param  array<string, int>  $counts
	 * @param  int|null            $durationMs  How long the run took, {@see millisecondsSince()}.
	 */
	public function record(
		string $job,
		array  $counts,
		?int   $durationMs = null,
	): void
	{
		try
		{
			$now = $this->timeFactory->getTime();

			$this->appConfig->setValueInt(
				Application::APP_ID,
				sprintf( 'stats_%s_last_run', $job ),
				$now,
			);
			$this->appConfig->setValueString(
				Application::APP_ID,
				sprintf( 'stats_%s_last_counts', $job ),
				json_encode( $counts, JSON_THROW_ON_ERROR ),
			);
			$this->writeAttempt(
				$job,
				[
					'at'         => $now,
					'ok'         => true,
					'durationMs' => $durationMs,
				],
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS: could not record job stats for {job}.',
				[
					'app'       => Application::APP_ID,
					'job'       => $job,
					'exception' => $e,
				],
			);
		}
	}

	/**
	 * Record a run that ended with an exception: the attempt, with the
	 * exception's class and message as its reason. The last successful run
	 * and its counts stay as they were. Never throws, as {@see record()}.
	 *
	 * @param  int|null  $durationMs  How long the run took until it failed.
	 */
	public function recordFailure(
		string    $job,
		Throwable $failure,
		?int      $durationMs = null,
	): void
	{
		try
		{
			$this->writeAttempt(
				$job,
				[
					'at'         => $this->timeFactory->getTime(),
					'ok'         => false,
					'durationMs' => $durationMs,
					'reason'     => self::reasonFor( $failure ),
				],
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS: could not record the failure of {job}.',
				[
					'app'       => Application::APP_ID,
					'job'       => $job,
					'exception' => $e,
				],
			);
		}
	}

	/**
	 * Every job's last run, for the status surface: the last successful
	 * run and its counts, and the last attempt, successful or not, null
	 * where none was recorded — every run before attempts were.
	 *
	 * @return array<string, array{lastRun: int|null, counts: array<string, int>, attempt: array{at: int, ok: bool, durationMs: int|null, reason: string|null}|null}>
	 */
	public function lastRuns(): array
	{
		$runs = [];

		foreach ( self::JOBS as $job )
		{
			$lastRun = $this->appConfig->getValueInt(
				Application::APP_ID,
				sprintf( 'stats_%s_last_run', $job ),
			);

			try
			{
				$counts = json_decode(
					$this->appConfig->getValueString(
						Application::APP_ID,
						sprintf( 'stats_%s_last_counts', $job ),
						'[]',
					),
					true,
					flags: JSON_THROW_ON_ERROR,
				);
			}
			catch ( JsonException )
			{
				$counts = [];
			}

			$runs[ $job ] = [
				'lastRun' => $lastRun > 0
					? $lastRun
					: null,
				'counts'  => is_array( $counts )
					? $counts
					: [],
				'attempt' => $this->attemptOf( $job ),
			];
		}

		return $runs;
	}

	/**
	 * @param  array{at: int, ok: bool, durationMs: int|null, reason?: string}  $attempt
	 *
	 * @throws JsonException
	 */
	private function writeAttempt(
		string $job,
		array  $attempt,
	): void
	{
		$this->appConfig->setValueString(
			Application::APP_ID,
			sprintf( 'stats_%s_last_attempt', $job ),
			json_encode( $attempt, JSON_THROW_ON_ERROR ),
		);
	}

	/**
	 * A job's last attempt, or null where none was recorded or the record
	 * does not read as one.
	 *
	 * @return array{at: int, ok: bool, durationMs: int|null, reason: string|null}|null
	 */
	private function attemptOf( string $job ): ?array
	{
		try
		{
			$attempt = json_decode(
				$this->appConfig->getValueString(
					Application::APP_ID,
					sprintf( 'stats_%s_last_attempt', $job ),
					'',
				) ?: 'null',
				true,
				flags: JSON_THROW_ON_ERROR,
			);
		}
		catch ( JsonException )
		{
			return null;
		}

		if ( ! is_array( $attempt ) || ! is_int( $attempt['at'] ?? null ) || ! is_bool( $attempt['ok'] ?? null ) )
		{
			return null;
		}

		return [
			'at'         => $attempt['at'],
			'ok'         => $attempt['ok'],
			'durationMs' => is_int( $attempt['durationMs'] ?? null )
				? $attempt['durationMs']
				: null,
			'reason'     => is_string( $attempt['reason'] ?? null )
				? $attempt['reason']
				: null,
		];
	}


//  static methods

	/**
	 * Milliseconds since an `hrtime(true)` reading: how long a run took.
	 */
	public static function millisecondsSince( int $start ): int
	{
		return intdiv( hrtime( true ) - $start, 1_000_000 );
	}

	/**
	 * A failure's reason as the status shows it: the exception's class,
	 * without its namespace, and its message, on one line and at most
	 * {@see REASON_MAX_LENGTH} characters long.
	 */
	private static function reasonFor( Throwable $failure ): string
	{
		$class  = $failure::class;
		$reason = preg_replace(
			'/\s+/',
			' ',
			substr( $class, (int) strrpos( '\\' . $class, '\\' ) ) . ': ' . $failure->getMessage(),
		) ?? '';

		return mb_strlen( $reason ) > self::REASON_MAX_LENGTH
			? mb_substr( $reason, 0, self::REASON_MAX_LENGTH - 1 ) . '…'
			: $reason;
	}
}
