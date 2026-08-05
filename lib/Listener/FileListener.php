<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reacts to Nextcloud filesystem events to maintain the checksum hash index.
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
		private readonly HashIndexService $hashIndexService,
		private readonly IAppConfig       $appConfig,
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

		$this->hashIndexService->copyFilecacheChecksum( $source, $target );
		$this->hashIndexService->copyHashes( $source->getId(), $target->getId() );

		$this->logger->debug(
			'FCIAS FileListener: copied hashes for copied file',
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

		$behavior = $this->appConfig->getValueString(
			Application::APP_ID,
			'update_hash_on_file_write',
			'auto',
		);

		$fileId = $node->getId();

		switch ( $behavior )
		{
		case 'off':
			break;

		case 'force':
			$result = $this->hashIndexService->recalcAllExistingAlgos( $fileId );

			if ( $result['locked'] ?? false )
			{
				$this->hashIndexService->deleteHashes( $fileId );
				$this->hashIndexService->addPending( $fileId, HashIndexService::EVENT_TYPE_WRITE );

				$this->logger->debug(
					'FCIAS FileListener: file locked, queued for delayed retry on write',
					[
						'app'    => Application::APP_ID,
						'fileId' => $fileId,
					],
				);
			}
			else
			{
				$this->logger->debug(
					'FCIAS FileListener: force-recalculated hashes on write',
					[
						'app'    => Application::APP_ID,
						'fileId' => $fileId,
					],
				);
			}

			break;

		case 'lazy':
			$this->hashIndexService->deleteHashes( $fileId );
			$this->hashIndexService->addPending( $fileId, HashIndexService::EVENT_TYPE_WRITE );

			$this->logger->debug(
				'FCIAS FileListener: lazy-deleted hashes + queued on write',
				[
					'app'    => Application::APP_ID,
					'fileId' => $fileId,
				],
			);

			break;

		case 'auto':
			if ( $this->hashIndexService->countHashes( $fileId ) > 0 )
			{
				$this->hashIndexService->recalcAllExistingAlgos( $fileId );

				$this->logger->debug(
					'FCIAS FileListener: auto-recalculated hashes on write',
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

		$behavior = $this->appConfig->getValueString(
			Application::APP_ID,
			'update_hash_on_file_create',
			'off',
		);

		$fileId = $node->getId();

		switch ( $behavior )
		{
		case 'off':
			break;

		case 'force':
			$result = $this->hashIndexService->recalcFileHash(
				$node,
				HashIndexService::getDefaultAlgo(),
			);

			if ( $result['locked'] ?? false )
			{
				$this->hashIndexService->addPending( $fileId, HashIndexService::EVENT_TYPE_CREATE );

				$this->logger->debug(
					'FCIAS FileListener: file locked, queued for delayed retry on create',
					[
						'app'    => Application::APP_ID,
						'fileId' => $fileId,
					],
				);
			}
			else
			{
				$this->logger->debug(
					'FCIAS FileListener: force-hashed default algo on create',
					[
						'app'    => Application::APP_ID,
						'fileId' => $fileId,
					],
				);
			}

			break;

		case 'lazy':
			$this->hashIndexService->addPending( $fileId, HashIndexService::EVENT_TYPE_CREATE );

			$this->logger->debug(
				'FCIAS FileListener: queued on create',
				[
					'app'    => Application::APP_ID,
					'fileId' => $fileId,
				],
			);

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

		$behavior = $this->appConfig->getValueString(
			Application::APP_ID,
			'update_hash_on_file_delete',
			'off',
		);

		if ( $behavior === 'on' )
		{
			$fileId = $node->getId();

			$this->hashIndexService->deleteHashes( $fileId );

			$this->logger->debug(
				'FCIAS FileListener: deleted hashes on file delete',
				[
					'app'    => Application::APP_ID,
					'fileId' => $fileId,
				],
			);
		}
	}

}
