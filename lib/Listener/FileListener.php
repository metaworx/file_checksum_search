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
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
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
		$context->registerEventListener( NodeCopiedEvent::class, self::class );
		$context->registerEventListener( NodeWrittenEvent::class, self::class );
		$context->registerEventListener( NodeCreatedEvent::class, self::class );
		$context->registerEventListener( NodeDeletedEvent::class, self::class );
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
				$event instanceof NodeDeletedEvent       => $this->onDelete( $event ),
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

	private function onDelete( NodeDeletedEvent $event ): void
	{
		$node = $event->getNode();

		if ( ! $node instanceof File )
		{
			return;
		}

		$fileId = $node->getId();

		// Deleted for good: the filecache row is gone, and Nextcloud takes
		// the file's metadata with it (`MetadataDelete`; for a deleted
		// user's or storage's files from nextcloud/server#63998 on). A save
		// here would come after that: refused, where Nextcloud looks the
		// gone file's storage up, or, where it goes through, a fresh
		// document for a file that no longer exists. What Nextcloud does
		// leave, the orphan purge collects.
		if ( $this->filecacheService->locate( $fileId ) === null )
		{
			return;
		}

		// Moved into the trash. No rule governs it there, whatever governed
		// it before, and hashes describe content; content the user removed
		// keeps none. A file this app holds nothing for is left as it is:
		// clearing would write it a document of this app's.
		$metadata = $this->metadataService->getMetadata( $fileId );

		if ( ! $this->metadataService->holdsAppData( $metadata ) )
		{
			return;
		}

		$this->metadataService->clearMetadata( $metadata );

		$this->logger->debug(
			'FCIAS FileListener: cleared metadata on file delete',
			[
				'app'    => Application::APP_ID,
				'fileId' => $fileId,
			],
		);
	}
}
