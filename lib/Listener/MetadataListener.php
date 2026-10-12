<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 * @author    metaworx
 * @author    Agent <roo-code@deepseek.com>
 */

namespace OCA\FileChecksumSearch\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\FilesMetadata\Event\MetadataBackgroundEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Listens for metadata background events to queue a file the app has not
 * hashed for the pending queue (ProcessPendingUpdates), in its rule's mode.
 *
 * Nextcloud's metadata generation reaches every file, a one-off job
 * included, and is no write: a file with a state — queued, gone, eroded,
 * disowned — is left as it is, and so is one whose hashes still vouch for
 * it. Overwriting a state undid a reset, and requeued what the drain had
 * given up on.
 *
 * Registered via IRegistrationContext::registerEventListener() in Application::register().
 *
 * @template-implements IEventListener<Event>
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class MetadataListener
    implements
    IEventListener
{

//  constructor

	public function __construct(
		private readonly MetadataService $metadataService,
		private readonly RuleService     $ruleService,
		private readonly LoggerInterface $logger,
	) {
	}


//  static methods

	public static function register( IRegistrationContext $context ): void
	{
		$context->registerEventListener( MetadataBackgroundEvent::class, self::class );
	}


//  other non-static methods

	#[\Override]
	public function handle( Event $event ): void
	{
		if ( ! $event instanceof MetadataBackgroundEvent )
		{
			return;
		}

		try
		{
			$node = $event->getNode();

			if ( ! $node instanceof File )
			{
				return;
			}

			$fileId = $node->getId();

			if ( $this->metadataService->getMarker( $fileId ) !== null || $this->metadataService->isVouched( $fileId ) )
			{
				return;
			}

			// In the mode its rule queues it in, as a write would; nothing
			// where no include rule maintains it.
			$queueMode = RuleService::queueModeOf( $this->ruleService->findFirstMatchingRule( $fileId ) );

			if ( $queueMode === null )
			{
				return;
			}

			$mode = MetadataService::PENDING_PREFIX . $queueMode;

			$this->metadataService->markPending( $fileId, $mode );

			$this->logger->debug(
				'FCIAS MetadataListener: marked {mode} on background event',
				[
					'app'    => Application::APP_ID,
					'fileId' => $fileId,
					'mode'   => $mode,
				],
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS MetadataListener: unhandled exception in handle()',
				[
					'app'       => Application::APP_ID,
					'event'     => $event::class,
					'exception' => $e,
				],
			);
		}
	}
}
