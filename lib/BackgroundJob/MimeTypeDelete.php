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
 * Deletes the stored MIME types, a slice per cron run: each out of its
 * metadata document, then its row ({@see MetadataService::deleteMimeTypes()}).
 *
 * Runs while a deletion is requested and detection is off, which is the
 * only time one may be: nothing then shows the types, so they need no
 * hiding before they go, and nothing detects new ones for this to take
 * away. Queues itself again until no row is left, and then clears the
 * request, which is what lets detection be switched on again.
 *
 * A run that fails queues the next all the same: the request stands until
 * the deletion is done, and only this finishes it.
 */
class MimeTypeDelete
    extends
    QueuedJob
{

//  constants

	/** Seconds one run spends deleting before it hands over to the next. */
	public const TIME_BUDGET = 30;

	/** Files per page. */
	public const PAGE_SIZE = 500;


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

	/**
	 * Queue the deletion, unless it is queued already.
	 *
	 * @return bool  Whether this call queued it.
	 */
	public static function queue( IJobList $jobList ): bool
	{
		if ( $jobList->has( self::class, null ) )
		{
			return false;
		}

		$jobList->add( self::class );

		return true;
	}


//  config/init/exe/run methods

	/**
	 * @param  mixed  $argument  Unused.
	 */
	#[\Override]
	protected function run( $argument ): void
	{
		$requestedAt = $this->mimeTypes->getDeleteRequestedAt();

		if ( $requestedAt === null )
		{
			$this->logger->info(
				'FCIAS MimeTypeDelete: no deletion of the MIME types is requested; nothing to do.',
				[ 'app' => Application::APP_ID ],
			);

			return;
		}

		// Switching on is refused while a deletion is requested, so this is
		// a request someone wrote by hand: the types detected since are not
		// this request's to delete.
		if ( $this->mimeTypes->isEnabled() )
		{
			$this->logger->warning(
				'FCIAS MimeTypeDelete: MIME type detection is on; the deletion requested at {requestedAt} waits until it is off.',
				[
					'app'         => Application::APP_ID,
					'requestedAt' => $requestedAt,
				],
			);

			return;
		}

		$started = hrtime( true );

		try
		{
			$deadline = $this->time->getTime() + self::TIME_BUDGET;
			$result   = $this->metadataService->deleteMimeTypes(
				self::PAGE_SIZE,
				fn (): bool => $this->time->getTime() < $deadline,
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS MimeTypeDelete: the deletion of the MIME types stopped; the next run goes on',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			$this->jobStats->recordFailure(
				JobStatsService::JOB_MIMETYPE_DELETE,
				$e,
				JobStatsService::millisecondsSince( $started ),
			);

			$this->jobList->add( self::class );

			return;
		}

		if ( $result['done'] )
		{
			$this->mimeTypes->setDeleteRequestedAt( null );
		}
		else
		{
			$this->jobList->add( self::class );
		}

		$this->jobStats->record(
			JobStatsService::JOB_MIMETYPE_DELETE,
			[
				'deleted' => $result['deleted'],
				'done'    => $result['done']
					? 1
					: 0,
			],
			JobStatsService::millisecondsSince( $started ),
		);

		$this->logger->info(
			'FCIAS MimeTypeDelete: deleted the MIME types of {deleted} files{rest}',
			[
				'app'     => Application::APP_ID,
				'deleted' => $result['deleted'],
				'rest'    => $result['done']
					? '; none is left'
					: '; continuing on the next run',
			],
		);
	}
}
