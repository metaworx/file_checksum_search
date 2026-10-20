<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

/**
 * Which file a version row is a version of, and when it was the file.
 *
 * Nextcloud keeps a file's earlier content as rows of their own, each with a
 * file id of its own, named after the file and the modification time the
 * content had while it was the file's:
 *
 * - a home's `files_versions/<path>.v<mtime>`, beside `files/<path>`: the
 *   file is named by its internal path, in the same storage;
 * - a team folder's `versions/<fileId>/<mtime>`, in the folder's own
 *   storage, or `__groupfolders/versions/<id>/<fileId>/<mtime>` in the root
 *   storage (the legacy layout): the file is named by its id.
 *
 * Read from the version's own row ({@see FileLocation::fromRow()}); whether
 * the file still exists is the filecache's to say
 * ({@see FilecacheService::filesOfVersions()}).
 */
readonly class VersionOf
{

//  constants

	/**
	 * What a version's path and location begin with in a result, before
	 * its file's: a path in a files area begins with `/`, and an address
	 * with its area, so neither is ever taken for one.
	 */
	public const SCHEME = 'versions://';


//  constructor

	public function __construct(
		/** The file's modification time while it held this content. */
		public int     $timestamp,
		/** The file's internal path, in the version's storage: a home's. */
		public ?string $fileInternalPath = null,
		/** The file's id: a team folder's. */
		public ?int    $fileId = null,
		/**
		 * The file, where it has been located already
		 * ({@see FileLocation::withVersionFile()}).
		 */
		public ?FileLocation $file = null,
	) {
	}
}
