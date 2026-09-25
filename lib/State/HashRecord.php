<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\State;

/**
 * One stored checksum, addressed the way it survives leaving the instance.
 *
 * File ids are meaningless anywhere but the instance that issued them, so a
 * record names its file the way {@see \OCA\FileChecksumSearch\Service\FileLocation}
 * does: by storage and the path inside it. An import resolves that back to an
 * id through the filecache, and a path with no row there is a file this
 * instance does not have — reported, never invented.
 *
 * `$updatedAt` is when the hash was computed, as far as the source knows.
 * Null means the source did not say, which is the normal case for a `sha1sum`
 * listing and the reason `--stamp` exists.
 */
readonly class HashRecord
{

//  constructor

	public function __construct(
		public string $storageId,
		public string $path,
		public string $algo,
		public string $hash,
		public ?int   $updatedAt = null,
	) {
	}


//  static methods

	/**
	 * @param  array<string, mixed>  $row
	 */
	public static function fromArray( array $row ): self
	{
		return new self(
			(string) ( $row['storage'] ?? '' ),
			(string) ( $row['path'] ?? '' ),
			strtolower( (string) ( $row['algo'] ?? '' ) ),
			strtolower( (string) ( $row['hash'] ?? '' ) ),
			isset( $row['updated_at'] ) && $row['updated_at'] !== ''
				? (int) $row['updated_at']
				: null,
		);
	}


//  other non-static methods

	/**
	 * @return array<string, string|int|null>
	 */
	public function toArray(): array
	{
		return [
			'storage'    => $this->storageId,
			'path'       => $this->path,
			'algo'       => $this->algo,
			'hash'       => $this->hash,
			'updated_at' => $this->updatedAt,
		];
	}

	/**
	 * What this record names, for a message a person has to act on.
	 */
	public function describe(): string
	{
		return $this->storageId === ''
			? $this->path
			: $this->storageId . '/' . ltrim( $this->path, '/' );
	}


//  getters / setters / is* / has*

	/**
	 * Whether this record says enough to be written anywhere.
	 */
	public function isComplete(): bool
	{
		return $this->path !== '' && $this->algo !== '' && $this->hash !== '';
	}
}
