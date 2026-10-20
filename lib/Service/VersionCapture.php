<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
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
	 * Before a write: keep the file's hashes, where they describe the
	 * content the write replaces, for the version Nextcloud makes of it.
	 * The caller has checked that a rule maintains the file's hashes.
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
