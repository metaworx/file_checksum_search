<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\FilesMetadata\Model\IFilesMetadata;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands an overwritten file's checksums to the version Nextcloud keeps of
 * the content they describe, without reading it.
 *
 * Nextcloud copies a file's content to a version of its own just before a
 * write replaces it, named after the file's modification time
 * ({@see FileLocation::versionPathAt()}). Before the write, the hashes the
 * file holds can be proved to be that content's, by the test every writer
 * uses ({@see MetadataService::isCurrent()}); after it, the filecache holds
 * the new ETag, and nothing can. So the hashes are kept before the write,
 * where they are current, and saved on the version after it, stamped
 * against the version's own ETag: a version overwritten in the same second
 * then reads as outdated.
 *
 * One instance carries them from the one event to the other: Nextcloud's
 * container keeps the instance it made.
 */
class VersionCapture
{
	/**
	 * What a write is about to replace, by the file's id: where its version
	 * will be, and the hashes it gets.
	 *
	 * @var array<int, array{storageId: string, path: string, hashes: array<string, string>}>
	 */
	private array $replaced = [];


//  constructor

	public function __construct(
		private readonly FilecacheService $filecacheService,
		private readonly MetadataService  $metadataService,
		private readonly LoggerInterface  $logger,
	) {
	}


//  other non-static methods

	/**
	 * Hand the hashes $from holds to $toFileId, where they are proved to be
	 * its content's: their stamp vouches for the receiving row as it stands
	 * ({@see MetadataService::isCurrent()}). It does where the row carries
	 * the ETag they were read against, and Nextcloud copies a row's ETag
	 * with its content (`Cache::copyFromCache()`): to the version a write or
	 * a restore makes, and to each version copied with its file.
	 *
	 * What the receiving row holds and is current for it, it keeps.
	 *
	 * @return bool  Whether any were handed on.
	 * @throws \OCP\DB\Exception
	 * @throws \OCP\FilesMetadata\Exceptions\FilesMetadataException
	 */
	public function handOn(
		IFilesMetadata $from,
		int            $toFileId,
	): bool
	{
		$hashes = $this->metadataService->getHashes( $from );
		$state  = $hashes === []
			? null
			: $this->filecacheService->stateOf( $toFileId );

		if ( $state === null || ! $this->metadataService->isCurrent( $from, $state ) )
		{
			return false;
		}

		$this->metadataService->writeHashes( $toFileId, $hashes, time(), true, $state['etag'] );

		return true;
	}

	/**
	 * A file copied: each of its versions hands its hashes to the copy's
	 * version of the same time, which Nextcloud copied it to, ETag and all
	 * ({@see handOn()}). The caller has checked that no rule excludes the
	 * copy, and runs after the versions app has copied the versions.
	 *
	 * Never in the copy's way: a failure here is logged.
	 *
	 * @return int  The copy's versions that got hashes.
	 */
	public function copied(
		int $sourceId,
		int $targetId,
	): int
	{
		try
		{
			$located = $this->filecacheService->locateAll( [ $sourceId, $targetId ] );
			$sources = isset( $located[ $sourceId ], $located[ $targetId ] )
				? $this->filecacheService->versionsOf( $located[ $sourceId ] )
				: [];
			$targets = $sources === []
				? []
				: array_column( $this->filecacheService->versionsOf( $located[ $targetId ] ), 'fileid', 'timestamp' );
			$handed  = 0;

			foreach ( $sources as $version )
			{
				$target = $targets[ $version['timestamp'] ] ?? null;

				if ( $target !== null && $this->handOn( $this->metadataService->getMetadata( $version['fileid'] ), $target ) )
				{
					$handed ++;
				}
			}
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS VersionCapture: could not hand a copied file\'s versions\' hashes to the copy\'s',
				[
					'app'       => Application::APP_ID,
					'sourceId'  => $sourceId,
					'targetId'  => $targetId,
					'exception' => $e,
				],
			);

			return 0;
		}

		return $handed;
	}

	/**
	 * Before a write: keep the file's hashes, where they describe the
	 * content the write replaces, for the version Nextcloud makes of it.
	 * The caller has checked that no rule excludes the file: the hashes are
	 * right for the content whether a rule maintains them or not.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function beforeWrite( int $fileId ): void
	{
		unset( $this->replaced[ $fileId ] );

		$state    = $this->filecacheService->stateOf( $fileId );
		$location = $state === null
			? null
			: $this->filecacheService->locate( $fileId );
		$path     = $location?->versionPathAt( $state['mtime'] ?? 0 );

		if ( $location === null || $path === null )
		{
			return;
		}

		$metadata = $this->metadataService->getMetadata( $fileId );
		$hashes   = $this->metadataService->getHashes( $metadata );

		if ( $hashes === [] || ! $this->metadataService->isCurrent( $metadata, $state ) )
		{
			return;
		}

		$this->replaced[ $fileId ] = [
			'storageId' => $location->storageId,
			'path'      => $path,
			'hashes'    => $hashes,
		];
	}

	/**
	 * After the write: save the kept hashes on the version Nextcloud made.
	 * None made — versioning off, an empty file — and nothing is saved.
	 *
	 * Never in the write's way: a failure here is logged, and the write's
	 * own handling of the file goes on.
	 *
	 * @return int|null  The version's file id, where it got hashes.
	 */
	public function afterWrite( int $fileId ): ?int
	{
		$replaced = $this->replaced[ $fileId ] ?? null;
		unset( $this->replaced[ $fileId ] );

		if ( $replaced === null )
		{
			return null;
		}

		try
		{
			$version = $this->filecacheService->locateAllByPath( [ $replaced['storageId'] => [ $replaced['path'] ] ] )
				[ FilecacheService::identityKey( $replaced['storageId'], $replaced['path'] ) ] ?? null;
			$etag    = $version === null
				? null
				: $this->filecacheService->etagOf( $version->fileId );

			if ( $version === null || $etag === null )
			{
				return null;
			}

			$this->metadataService->writeHashes( $version->fileId, $replaced['hashes'], time(), false, $etag );
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS VersionCapture: could not hand an overwritten file\'s hashes to its version',
				[
					'app'       => Application::APP_ID,
					'fileId'    => $fileId,
					'exception' => $e,
				],
			);

			return null;
		}

		$this->logger->debug(
			'FCIAS VersionCapture: an overwritten file\'s hashes handed to its version',
			[
				'app'       => Application::APP_ID,
				'fileId'    => $fileId,
				'versionId' => $version->fileId,
				'algos'     => array_keys( $replaced['hashes'] ),
			],
		);

		return $version->fileId;
	}
}
