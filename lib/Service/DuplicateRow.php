<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\Files\File;

/**
 * One row of a duplicate group, opened for an account to act on
 * ({@see DuplicateActionService}): what kind of row it is, and what it was
 * opened through, or why it may not be acted on.
 *
 * `node` is the file for a file and for a file in the trash, the file a
 * version is of for a version, and for a version of a trashed file the
 * version's own file in a home, the trashed file in a team folder.
 * `version` and `trashItem` are the versions and trash apps' own objects,
 * typed loosely since either app may be absent.
 */
readonly class DuplicateRow
{

//  constructor

	public function __construct(
		public int     $fileId,
		public ?string $kind,
		public ?File   $node = null,
		/** @var \OCA\Files_Versions\Versions\IVersion|null */
		public ?object $version = null,
		/** @var \OCA\Files_Trashbin\Trash\ITrashItem|null */
		public ?object $trashItem = null,
		public ?string $refusal = null,
	) {
	}
}
