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
use OCA\FileChecksumSearch\Service\VersionCapture;
use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\Files_Versions\Events\VersionRestoredEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reacts to Nextcloud filesystem events by marking files as pending
 * for deferred hash processing by ProcessPendingUpdates.
 *
 * Registered via IRegistrationContext::registerEventListener() in Application::register().
 *
 * @template-implements IEventListener<Event>
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class FileListener
    implements
    IEventListener
{

//  constructor

	public function __construct(
		private readonly FilecacheService $filecacheService,
		private readonly MetadataService  $metadataService,
		private readonly RuleService      $ruleService,
		private readonly LoggerInterface  $logger,
		private readonly VersionCapture   $versionCapture,
	) {
	}


//  static methods

	public static function register( IRegistrationContext $context ): void
	{
		$context->registerEventListener( BeforeNodeWrittenEvent::class, self::class );
		// After the versions app's own listener, which copies a file's
		// versions beside the copy: their hashes follow them here.
		$context->registerEventListener( NodeCopiedEvent::class, self::class, -10 );
		$context->registerEventListener( NodeWrittenEvent::class, self::class );
		$context->registerEventListener( NodeCreatedEvent::class, self::class );
		// The versions app's and the trash app's own events, by name: with
		// the app off, nobody dispatches them.
		/** @psalm-suppress InvalidArgument The versions app's class, which the OCP package does not ship (psalm.xml). */
		$context->registerEventListener( VersionRestoredEvent::class, self::class );
		/** @psalm-suppress InvalidArgument The trash app's class, which the OCP package does not ship (psalm.xml). */
		$context->registerEventListener( NodeRestoredEvent::class, self::class );
	}


//  other non-static methods

	#[\Override]
	public function handle( Event $event ): void
	{
		try
		{
			match ( true )
			{
				$event instanceof BeforeNodeWrittenEvent => $this->onBeforeWrite( $event ),
				$event instanceof NodeCopiedEvent        => $this->onCopy( $event ),
				$event instanceof NodeWrittenEvent       => $this->onWrite( $event ),
				$event instanceof NodeCreatedEvent       => $this->onCreate( $event ),
				$event instanceof VersionRestoredEvent   => $this->onRestore( $event ),
				$event instanceof NodeRestoredEvent      => $this->onTrashRestore( $event ),
				default                                  => null,
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

	/**
	 * The content about to be replaced becomes a version: its hashes, where
	 * they are current and no rule excludes the file, are kept for it
	 * ({@see VersionCapture}) — right for that content whether a rule
	 * maintains them or someone computed them by hand. A file not written
	 * yet has no id, and no content to replace.
	 */
	private function onBeforeWrite( BeforeNodeWrittenEvent $event ): void
	{
		$node = $event->getNode();

		if ( ! $node instanceof File )
		{
			return;
		}

		try
		{
			$fileId = $node->getId();
		}
		catch ( NotFoundException )
		{
			return;
		}

		if ( $fileId <= 0 || $this->excluded( $fileId ) )
		{
			return;
		}

		$this->versionCapture->beforeWrite( $fileId );
	}

	private function onCopy( NodeCopiedEvent $event ): void
	{
		$source = $event->getSource();
		$target = $event->getTarget();

		if ( ! $source instanceof File || ! $target instanceof File )
		{
			return;
		}

		// The versions the versions app copied with the file keep their
		// hashes, proved by the ETag they were copied with: right for their
		// content wherever the copy lands, unless a rule excludes it.
		if ( ! $this->excluded( $target->getId() ) )
		{
			$this->versionCapture->copied( $source->getId(), $target->getId() );
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

		// The replaced content's hashes, kept before the write, go to the
		// version Nextcloud has made of it by now.
		$this->versionCapture->afterWrite( $fileId );

		$this->contentReplaced( $fileId );
	}

	/**
	 * A version restored: the content it replaced keeps its hashes on the
	 * version the restore made of it, and the file takes the restored
	 * version's ({@see VersionCapture::restored()}). Where the version holds
	 * none, the file is as written: Nextcloud fires no write for a restore,
	 * and the app would otherwise hear of it only at the daily ETag walk.
	 *
	 * The event is the versions app's own, named by its class, so the app
	 * loads without it.
	 */
	private function onRestore( VersionRestoredEvent $event ): void
	{
		$version = $event->getVersion();
		$fileId  = $version->getSourceFile()->getId();

		if ( $fileId === null || $this->excluded( $fileId ) )
		{
			return;
		}

		if ( ! $this->versionCapture->restored( $fileId, (int) $version->getRevisionId() ) )
		{
			$this->contentReplaced( $fileId );
		}
	}

	/**
	 * What a change of the file's content does to its hashes, by its rule:
	 * queued again where a rule maintains them, eroded where none does.
	 */
	private function contentReplaced( int $fileId ): void
	{
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
					'FCIAS FileListener: dropped outdated hashes for an unmaintained file on write',
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

		// The hashes now describe content the file no longer has, and an
		// outdated hash is worse than none: out of every search until the
		// queue has computed them again. A file without hashes is queued for
		// its first, which the rule wants as much.
		case MetadataService::PENDING_MODE_AUTO:
		case MetadataService::PENDING_MODE_MISSING:
			if ( $this->metadataService->markOutdated( $fileId, $mode ) )
			{
				$this->logger->debug(
					'FCIAS FileListener: hashes hidden and queued on write',
					[
						'app'    => Application::APP_ID,
						'fileId' => $fileId,
						'mode'   => $mode,
					],
				);

				break;
			}

			$this->metadataService->markPending( $fileId, MetadataService::PENDING_PREFIX . $mode );

			$this->logger->debug(
				'FCIAS FileListener: queued on write for its first hashes',
				[
					'app'    => Application::APP_ID,
					'fileId' => $fileId,
					'mode'   => $mode,
				],
			);

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

		// A new file has no hashes, and both modes want the rule's: the two
		// differ only in reading a current file to add an algorithm.
		case MetadataService::PENDING_MODE_AUTO:
		case MetadataService::PENDING_MODE_MISSING:
			$this->metadataService->markPending( $fileId, MetadataService::PENDING_PREFIX . $mode );

			$this->logger->debug(
				'FCIAS FileListener: queued on create',
				[
					'app'    => Application::APP_ID,
					'fileId' => $fileId,
					'mode'   => $mode,
				],
			);

			break;
		}
	}

	/**
	 * Whether a rule excludes the file: nothing may hash it by any route,
	 * so nothing hands it, or its versions, checksums either.
	 */
	private function excluded( int $fileId ): bool
	{
		$rule = $this->ruleService->findFirstMatchingRule( $fileId );

		return $rule !== null && RuleService::verdictOf( $rule ) === RuleService::TYPE_EXCLUDE;
	}

	/**
	 * A file restored from a home's trash: it comes back with its id and its
	 * ETag, so hashes it kept in the trash are still proved, and it is left
	 * as it is. One without current hashes is as written: Nextcloud fires no
	 * create or write for a restore, and the sweep would come to it only
	 * later. A restored folder's files are the sweep's.
	 *
	 * A deletion needs no listener: the trash keeps the file, its id and its
	 * ETag, and a file deleted for good leaves, its metadata taken by
	 * Nextcloud with its row. A team folder's restore fires no event.
	 *
	 * The event is the trash app's own, named by its class, so the app loads
	 * without it.
	 */
	private function onTrashRestore( NodeRestoredEvent $event ): void
	{
		$node = $event->getTarget();

		if ( ! $node instanceof File )
		{
			return;
		}

		$fileId   = $node->getId();
		$metadata = $this->metadataService->getMetadata( $fileId );

		if ( $this->metadataService->getHashes( $metadata ) !== []
			&& $this->metadataService->isCurrent( $metadata, $this->filecacheService->stateOf( $fileId ) ) )
		{
			return;
		}

		$this->contentReplaced( $fileId );
	}
}
