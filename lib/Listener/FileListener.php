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

		// The copy lands at a new location, which may be governed by a
		// different rule than the source was. Copying a checksum into a
		// location whose rule says not to hash would create exactly the
		// stored hash that rule exists to prevent. Resolved by file id —
		// identity, not the acting user's view path: a recipient copying
		// into a share is governed by the share's own rules.
		if ( ! RuleService::maintainsHashes(
			$this->ruleService->findFirstMatchingRule( $target->getId() ),
		) )
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

		$fileId = $node->getId();

		// By identity, not by the acting user's view: a share recipient's
		// edit of the owner's file is governed by the rules that govern the
		// owner's file, under the path the owner knows it by.
		$rule = $this->ruleService->findFirstMatchingRule( $fileId );

		if ( ! RuleService::maintainsHashes( $rule ) )
		{
			// Nothing will refresh this file's hashes, and its content just
			// changed — so anything stored for it is now provably wrong. A
			// wrong hash is worse than no hash: it makes a modified file look
			// intact and can pair it with unrelated files as a duplicate.
			// (Excluding a file does not purge it; modifying one does.)
			if ( $this->metadataService->countByFileId( $fileId ) > 0 )
			{
				// 'eroded' rather than a bare clear: the loss stays queryable
				// on the status page and heals itself when a rule covers the
				// file again.
				$this->metadataService->markEroded( $fileId );

				$this->logger->debug(
					'FCIAS FileListener: dropped stale hashes for an unmaintained file on write',
					[
						'app'    => Application::APP_ID,
						'fileId' => $fileId,
					],
				);
			}

			return;
		}

		$mode = $rule['mode'] ?? MetadataService::PENDING_MODE_AUTO;

		switch ( $mode )
		{
		case MetadataService::PENDING_MODE_OFF:
			break;

		case MetadataService::PENDING_MODE_FORCE:
			$this->metadataService->clearMetadata( $fileId );
			$this->metadataService->markPending(
				$fileId,
				MetadataService::PENDING_FORCE,
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

		$rule = $this->ruleService->findFirstMatchingRule( $node->getId() );

		if ( ! RuleService::maintainsHashes( $rule ) )
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
			$this->metadataService->markPending(
				$fileId,
				MetadataService::PENDING_FORCE,
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
			// Intentionally a no-op for a brand-new file: `auto` means
			// "recalculate EXISTING hashes only when stale" (README), and
			// a just-created file has none — countByFileId() is correctly
			// 0 here. Filling in a first hash for a new file under `auto`
			// is not this mode's job; use `missing` or `force` on the rule
			// if that's wanted. New files with genuinely no hash still get
			// one eventually via the independent MetadataBackgroundEvent →
			// MetadataListener → pending:missing → ProcessPendingUpdates
			// path, which resolves the matching rule at drain time.
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

		// Unconditional: by the time this event fires the filecache row is
		// gone or moved into a trash area, so no rule can be said to govern
		// the file any more — and this app's metadata rows only ever exist
		// for files it hashed, so clearing is a no-op for everything else.
		// Hashes describe content; content the user removed keeps none.
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
