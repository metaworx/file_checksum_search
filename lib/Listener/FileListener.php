<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reacts to Nextcloud filesystem events by marking files as pending
 * for deferred hash processing by ProcessPendingUpdates.
 *
 * Registered via IRegistrationContext::registerEventListener() in Application::register().
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class FileListener
	implements
	IEventListener
{

	public function __construct(
		private readonly FilecacheService $filecacheService,
		private readonly MetadataService  $metadataService,
		private readonly RuleService      $ruleService,
		private readonly LoggerInterface  $logger,
	) {
	}


	public static function register( IRegistrationContext $context ): void
	{

		$context->registerEventListener( NodeCopiedEvent::class, self::class );
		$context->registerEventListener( NodeWrittenEvent::class, self::class );
		$context->registerEventListener( NodeCreatedEvent::class, self::class );
		$context->registerEventListener( NodeDeletedEvent::class, self::class );
	}


	public function handle( Event $event ): void
	{

		try
		{
			match ( true )
			{
				$event instanceof NodeCopiedEvent => $this->onCopy( $event ),
				$event instanceof NodeWrittenEvent => $this->onWrite( $event ),
				$event instanceof NodeCreatedEvent => $this->onCreate( $event ),
				$event instanceof NodeDeletedEvent => $this->onDelete( $event ),
				default => null,
			};
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS FileListener: unhandled exception in handle()',
				[
					'app'       => Application::APP_ID,
					'event'     => $event::class,
					'exception' => $e,
				],
			);
		}
	}


	private function onCopy( NodeCopiedEvent $event ): void
	{

		$source = $event->getSource();
		$target = $event->getTarget();

		if ( ! $source instanceof File || ! $target instanceof File )
		{
			return;
		}

		$this->filecacheService->copyFilecacheChecksum( $source, $target );
		$this->metadataService->markPending( $target->getId(), MetadataService::PENDING_AUTO );

		$this->logger->debug(
			'FCIAS FileListener: copied checksum and marked pending for copied file',
			[
				'app'      => Application::APP_ID,
				'sourceId' => $source->getId(),
				'targetId' => $target->getId(),
			],
		);
	}


	private function onWrite( NodeWrittenEvent $event ): void
	{

		$node = $event->getNode();

		if ( ! $node instanceof File )
		{
			return;
		}

		$rule = $this->ruleService->findFirstMatchingRule( $node->getPath() );

		if ( $rule === null )
		{
			return;
		}

		$mode   = $rule['mode'] ?? MetadataService::PENDING_MODE_AUTO;
		$fileId = $node->getId();

		switch ( $mode )
		{
		case MetadataService::PENDING_MODE_OFF:
			break;

		case MetadataService::PENDING_MODE_FORCE:
			$this->metadataService->clearMetadata( $fileId );
			$this->metadataService->markPending( $fileId,
				MetadataService::PENDING_FORCE
			);

			$this->logger->debug(
				'FCIAS FileListener: force-cleared + queued on write',
				[
					'app'    => Application::APP_ID,
					'fileId' => $fileId,
				],
			);

			break;

		case MetadataService::PENDING_MODE_LAZY:
			$this->metadataService->clearMetadata( $fileId );
			$this->metadataService->markPending( $fileId, MetadataService::PENDING_LAZY );

			$this->logger->debug(
				'FCIAS FileListener: lazy-cleared + queued on write',
				[
					'app'    => Application::APP_ID,
					'fileId' => $fileId,
				],
			);

			break;

		case MetadataService::PENDING_MODE_AUTO:
			if ( $this->metadataService->countByFileId( $fileId ) > 0 )
			{
				$this->metadataService->markPending( $fileId, MetadataService::PENDING_AUTO );

				$this->logger->debug(
					'FCIAS FileListener: auto-queued on write',
					[
						'app'    => Application::APP_ID,
						'fileId' => $fileId,
					],
				);
			}

			break;
		}
	}


	private function onCreate( NodeCreatedEvent $event ): void
	{

		$node = $event->getNode();

		if ( ! $node instanceof File )
		{
			return;
		}

		$rule = $this->ruleService->findFirstMatchingRule( $node->getPath() );

		if ( $rule === null )
		{
			return;
		}

		$mode   = $rule['mode'] ?? MetadataService::PENDING_MODE_AUTO;
		$fileId = $node->getId();

		switch ( $mode )
		{
		case MetadataService::PENDING_MODE_OFF:
			break;

		case MetadataService::PENDING_MODE_FORCE:
			$this->metadataService->clearMetadata( $fileId );
			$this->metadataService->markPending( $fileId,
				MetadataService::PENDING_FORCE
			);

			$this->logger->debug(
				'FCIAS FileListener: force-cleared + queued on create',
				[
					'app'    => Application::APP_ID,
					'fileId' => $fileId,
				],
			);

			break;

		case MetadataService::PENDING_MODE_LAZY:
			$this->metadataService->markPending( $fileId, MetadataService::PENDING_LAZY );

			$this->logger->debug(
				'FCIAS FileListener: queued on create',
				[
					'app'    => Application::APP_ID,
					'fileId' => $fileId,
				],
			);

			break;

		case MetadataService::PENDING_MODE_AUTO:
			if ( $this->metadataService->countByFileId( $fileId ) > 0 )
			{
				$this->metadataService->markPending( $fileId, MetadataService::PENDING_AUTO );

				$this->logger->debug(
					'FCIAS FileListener: auto-queued on create',
					[
						'app'    => Application::APP_ID,
						'fileId' => $fileId,
					],
				);
			}

			break;
		}
	}


	private function onDelete( NodeDeletedEvent $event ): void
	{

		$node = $event->getNode();

		if ( ! $node instanceof File )
		{
			return;
		}

		$rule = $this->ruleService->findFirstMatchingRule( $node->getPath() );

		if ( $rule === null )
		{
			return;
		}

		$mode = $rule['mode'] ?? MetadataService::PENDING_MODE_AUTO;

		if ( $mode !== MetadataService::PENDING_MODE_OFF )
		{
			$fileId = $node->getId();

			$this->metadataService->clearMetadata( $fileId );

			$this->logger->debug(
				'FCIAS FileListener: cleared metadata on file delete',
				[
					'app'    => Application::APP_ID,
					'fileId' => $fileId,
				],
			);
		}
	}

}
