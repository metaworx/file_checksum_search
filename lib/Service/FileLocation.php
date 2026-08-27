<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

/**
 * A file's canonical identity: where it really lives.
 *
 * Every path a user sees is a view — a share mount the recipient may have
 * renamed, a group folder mounted into each member's tree — but a file has
 * exactly one filecache row: (storage, internal path). This class is that
 * row, classified into the namespace the selectors speak:
 *
 * - home: the storage id is `home::<uid>` or `object::user:<uid>`; the
 *   owner is the uid, and the relative path is the part below `files/`.
 * - groupfolder: a dedicated jail rooted at `__groupfolders/<id>` — either
 *   its own storage (`local::…/__groupfolders/<id>/`, the current layout)
 *   or a path inside the root storage (the legacy layout). Group folders
 *   have no owner: `getOwner()` on their storage answers "whoever is
 *   looking", which is exactly why identity here is storage-based.
 * - other: everything else (external mounts, the root storage, …),
 *   addressable via `storage:<raw id>` and `*`.
 *
 * $relativePath is null for rows outside a files area (trash bins,
 * versions, appdata). Verdicts and sweeps skip those entirely: no rule
 * matches them, and no glob can reach them — the app hashes files, not
 * infrastructure.
 */
readonly class FileLocation
{

	public const NS_HOME = 'home';

	public const NS_GROUPFOLDER = 'groupfolder';

	public const NS_OTHER = 'other';


	public function __construct(
		public int     $fileId,
		public string  $storageId,
		public string  $internalPath,
		public int     $mtime,
		public string  $namespace,
		public ?string $owner,
		public ?int    $groupFolderId,
		public ?string $relativePath,
	) {
	}


	/**
	 * Classify a filecache row into its namespace.
	 */
	public static function fromRow(
		int    $fileId,
		string $storageId,
		string $internalPath,
		int    $mtime,
	): self {

		// Home storages: home::<uid>, or object::user:<uid> when the primary
		// storage is an object store.
		foreach (
			[
				'home::',
				'object::user:',
			] as $prefix
		)
		{
			if ( str_starts_with( $storageId, $prefix ) )
			{
				return new self(
					$fileId,
					$storageId,
					$internalPath,
					$mtime,
					self::NS_HOME,
					substr( $storageId, strlen( $prefix ) ),
					null,
					self::filesRelative( $internalPath ),
				);
			}
		}

		// Current groupfolders layout: one Local storage per folder, rooted
		// at <datadir>/__groupfolders/<id> — by prefix it looks like a local
		// external mount, so the jail root in the id is the tell.
		if ( preg_match( '#^local::.*/__groupfolders/(\d+)/?$#', $storageId, $m ) === 1 )
		{
			return new self(
				$fileId,
				$storageId,
				$internalPath,
				$mtime,
				self::NS_GROUPFOLDER,
				null,
				(int) $m[1],
				self::filesRelative( $internalPath ),
			);
		}

		// Legacy layout: group folders jailed inside the root storage.
		if ( preg_match( '#^__groupfolders/(\d+)/(.*)$#', $internalPath, $m ) === 1 )
		{
			return new self(
				$fileId,
				$storageId,
				$internalPath,
				$mtime,
				self::NS_GROUPFOLDER,
				null,
				(int) $m[1],
				self::filesRelative( $m[2] ),
			);
		}

		return new self(
			$fileId,
			$storageId,
			$internalPath,
			$mtime,
			self::NS_OTHER,
			null,
			null,
			self::filesRelative( $internalPath ),
		);
	}


	/**
	 * A human-readable address for log and console lines.
	 *
	 * Home files print as the owner's absolute view path (the spelling every
	 * earlier log line used); everything else prints selector-style, since
	 * there is no user whose view could name it.
	 */
	public function describe(): string
	{

		return match ( $this->namespace )
		{
			self::NS_HOME => '/' . $this->owner . '/' . $this->internalPath,
			self::NS_GROUPFOLDER => 'groupfolder:' . $this->groupFolderId
				. ( $this->relativePath ?? '/' . $this->internalPath ),
			default => 'storage:' . $this->storageId
				. ( $this->relativePath ?? '/' . $this->internalPath ),
		};
	}


	/**
	 * The '/…' path below the files area, or null for rows outside it.
	 */
	private static function filesRelative( string $internalPath ): ?string
	{

		if ( $internalPath === 'files' )
		{
			return '/';
		}

		if ( str_starts_with( $internalPath, 'files/' ) )
		{
			return '/' . substr( $internalPath, strlen( 'files/' ) );
		}

		return null;
	}

}
