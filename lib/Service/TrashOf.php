<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

/**
 * Which trash item a row is, or is inside, and when it was deleted.
 *
 * Nextcloud moves a deleted file or folder into a trash of its storage,
 * renamed `<name>.d<time>`, the time of the deletion, its id and its content
 * kept:
 *
 * - a home's `files_trashbin/files/<name>.d<time>`;
 * - a team folder's `trash/<name>.d<time>`, in the folder's own storage, or
 *   `__groupfolders/trash/<id>/<name>.d<time>` in the root storage (the
 *   legacy layout).
 *
 * A row inside a trashed folder continues below it, by its path in that
 * folder ($subPath). Where the item was before the deletion is not in the
 * row: the trash keeps it in its own records
 * ({@see FilecacheService::originsOfTrash()}).
 */
readonly class TrashOf
{

//  constants

	/**
	 * What a trashed file's path and location begin with in a result, before
	 * the path it had: a path in a files area begins with `/`, and an
	 * address with its area, so neither is ever taken for one.
	 */
	public const SCHEME = 'trash://';


//  constructor

	public function __construct(
		/** The trash item's name, as it was before the deletion. */
		public string $name,
		/** When the item was deleted: the `.d<time>` of its name. */
		public int    $deletedAt,
		/** The row's path inside a trashed folder; '' for the item itself. */
		public string $subPath = '',
		/**
		 * The location the row had before the deletion, where it has been
		 * located already ({@see FileLocation::withTrashOrigin()}).
		 */
		public ?FileLocation $origin = null,
	) {
	}
}
