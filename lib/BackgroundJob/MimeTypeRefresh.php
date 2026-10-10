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
use OCA\FileChecksumSearch\Service\MimeTypeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues the hashed files for their MIME type, `pending:mime`, a slice per
 * cron run: those without a type, or, asked for all, every one.
 *
 * It reads nothing: the queue drain reads each file's first 64 KiB, under
 * the same lock, failure count and skip of an unavailable storage as a
 * hash. A refresh over a large instance has no place in the request that
 * asked for it, so the request queues this and returns.
 *
 * Walks by file id until {@see TIME_BUDGET} seconds have passed, and queues
 * itself again with its place in the argument. Stops where detection is off:
 * what it was asked for is not wanted any more.
 */
class MimeTypeRefresh
    extends
    QueuedJob
{

//  constants

	/** Seconds one run spends walking before it hands over to the next. */
	public const TIME_BUDGET = 30;

	/** Files per page. */
	public const PAGE_SIZE = 1000;


//  constructor

	public function __construct(
		ITimeFactory                     $time,
		private readonly MetadataService $metadataService,
		private readonly MimeTypeService $mimeTypes,
		private readonly IJobList        $jobList,
		private readonly JobStatsService $jobStats,
		private readonly LoggerInterface $logger,
	)
	{
		parent::__construct( $time );
	}


//  static methods

	/** Queue a refresh from the first file: of every hashed file, or of those without a type. */
	public static function queue(
		IJobList $jobList,
		bool     $all,
	): void
	{
		$jobList->add(
			self::class,
			[
				'all'   => $all,
				'after' => 0,
			],
		);
	}


//  config/init/exe/run methods

	/**
	 * @param  array{all?: bool, after?: int}  $argument
	 */
	#[\Override]
	protected function run( $argument ): void
	{
		$all     = (bool) ( $argument['all'] ?? false );
		$after   = (int) ( $argument['after'] ?? 0 );
		$started = hrtime( true );

		if ( ! $this->mimeTypes->isActive() )
		{
			$this->logger->info(
				'FCIAS MimeTypeRefresh: MIME type detection is off; the refresh stops after file {after}.',
				[
					'app'   => Application::APP_ID,
					'after' => $after,
				],
			);

			return;
		}

		try
		{
			$deadline = $this->time->getTime() + self::TIME_BUDGET;
			$result   = $this->metadataService->markForMimeTypeAfter(
				$after,
				self::PAGE_SIZE,
				$all,
				fn (): bool => $this->time->getTime() < $deadline,
			);
		}
		catch ( Throwable $e )
		{
			// Not queued again: a refresh asked for anew passes the files
			// this one queued, and goes on to the rest.
			$this->logger->error(
				'FCIAS MimeTypeRefresh: the refresh stopped after file {after}',
				[
					'app'       => Application::APP_ID,
					'after'     => $after,
					'exception' => $e,
				],
			);

			$this->jobStats->recordFailure(
				JobStatsService::JOB_MIMETYPE_REFRESH,
				$e,
				JobStatsService::millisecondsSince( $started ),
			);

			return;
		}

		if ( ! $result['done'] )
		{
			$this->jobList->add(
				self::class,
				[
					'all'   => $all,
					'after' => $result['last'],
				],
			);
		}

		$this->jobStats->record(
			JobStatsService::JOB_MIMETYPE_REFRESH,
			[
				'marked' => $result['marked'],
				'done'   => $result['done']
					? 1
					: 0,
			],
			JobStatsService::millisecondsSince( $started ),
		);

		$this->logger->info(
			'FCIAS MimeTypeRefresh: queued {marked} files for their MIME type{rest}',
			[
				'app'    => Application::APP_ID,
				'marked' => $result['marked'],
				'rest'   => $result['done']
					? ''
					: sprintf( ', up to file %d; continuing on the next run', $result['last'] ),
			],
		);
	}
}
