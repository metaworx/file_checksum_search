<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\State\Format;

use InvalidArgumentException;
use OCA\FileChecksumSearch\State\HashRecord;

/**
 * What a format needs to know that the data itself does not say.
 *
 * All of it exists for the `sum` format, whose lines carry a hash and a path
 * and nothing else: which algorithm those hashes are, and what the paths are
 * relative to. Getting the anchor wrong is the worst failure this feature
 * has — it silently attaches hashes to the wrong files, which is worse than
 * importing nothing — so it is asked for explicitly rather than guessed.
 */
readonly class FormatOptions
{

	public function __construct(
		/** The algorithm a format cannot name for itself. */
		public ?string $algo = null,
		/** Paths are relative to this user's files/ directory. */
		public ?string $userId = null,
		/** Paths are relative to this storage's root. */
		public ?string $storageId = null,
		/** Pretty-print, where the format has an opinion about whitespace. */
		public bool    $pretty = false,
	) {

		if ( $this->userId !== null && $this->storageId !== null )
		{
			throw new InvalidArgumentException(
				'Anchor paths to a user or to a storage, not both — they would disagree.',
			);
		}
	}


	/**
	 * Whether paths in this stream are anchored to anything at all. An
	 * unanchored relative path is only usable where the record names its own
	 * storage, as json and csv do.
	 */
	public function isAnchored(): bool
	{

		return $this->userId !== null || $this->storageId !== null;
	}


	/**
	 * Give a record the storage and internal path the filecache would know it
	 * by — but only if it does not already carry one.
	 *
	 * **A record that names its own storage is already anchored**, and an
	 * anchor is never applied over it. That is what keeps a backup safe to
	 * re-import with `--user` set: the paths in a json or csv export are
	 * already internal paths (`files/Photos/a.jpg`), and re-anchoring them
	 * would bury every file one level deeper (`files/files/Photos/a.jpg`).
	 * Only a `sum` listing, which names no storage at all, is anchored.
	 *
	 * `--user` means *relative to that user's files directory*, which is what
	 * `sha1sum -r *` run from inside it produces, so `files/` is prepended.
	 * `--storage` means *relative to that storage's root*, where the internal
	 * path already starts, so nothing is prepended.
	 */
	public function anchor( HashRecord $record ): HashRecord
	{

		if ( $record->storageId !== '' )
		{
			return $record;
		}

		// `find . | xargs sha1sum` writes ./Photos/a.jpg; an absolute-looking
		// path in a listing is still relative to wherever it was run.
		$path = ltrim( $record->path, '/' );

		while ( str_starts_with( $path, './' ) )
		{
			$path = substr( $path, 2 );
		}

		if ( $this->userId !== null )
		{
			return new HashRecord(
				'home::' . $this->userId,
				'files/' . $path,
				$record->algo,
				$record->hash,
				$record->updatedAt,
			);
		}

		if ( $this->storageId !== null )
		{
			return new HashRecord(
				$this->storageId,
				$path,
				$record->algo,
				$record->hash,
				$record->updatedAt,
			);
		}

		return $record;
	}

}
