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
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\FilesMetadata\Event\MetadataBackgroundEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Listens for metadata background events to mark files for deferred
 * hash computation via the pending queue (ProcessPendingUpdates).
 *
 * Registered via IRegistrationContext::registerEventListener() in Application::register().
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class MetadataListener
	implements
	IEventListener
{

	public function __construct(
		private readonly MetadataService $metadataService,
		private readonly LoggerInterface $logger,
	) {
	}


	public static function register( IRegistrationContext $context ): void
	{

		$context->registerEventListener( MetadataBackgroundEvent::class, self::class );
	}


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

			// If no hash keys exist for this file, mark as pending:new.
			// If hash keys exist but may be stale or incomplete, mark as pending:missing.
			$count = $this->metadataService->countByFileId( $fileId );
			$mode  = $count > 0
				? MetadataService::PENDING_PREFIX . 'missing'
				: MetadataService::PENDING_PREFIX . 'new';

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
