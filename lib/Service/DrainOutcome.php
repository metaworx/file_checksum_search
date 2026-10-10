<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

/**
 * What the queue drain did with one queued file
 * ({@see HashCalculationService::processFile()}): its kind, which says
 * whether the file left the queue, and why, where that is not plain.
 *
 * The reason is English: it goes to the command line and the log.
 */
readonly class DrainOutcome
{

//  constants

	/** The rule's hashes computed and saved. */
	public const HASHED = 'hashed';

	/** Its MIME type read alone, for `pending:mime`. */
	public const TYPED = 'typed';

	/** Nothing to compute: its hashes current, the stamp written. */
	public const STAMPED = 'stamped';

	/** `lazy`: its hashes deleted, nothing computed. */
	public const EMPTIED = 'emptied';

	/** The mark dropped unread: what it asked for is not wanted any more. */
	public const DROPPED = 'dropped';

	/**
	 * Off the queue as `stale:gone`: the filecache lists it, and its storage
	 * does not have it.
	 */
	public const GONE = 'gone';

	/** It stays queued, behind the files that failed fewer times. */
	public const FAILED = 'failed';

	/** Every kind, in the order a summary lists them. */
	public const KINDS = [
		self::HASHED,
		self::TYPED,
		self::STAMPED,
		self::EMPTIED,
		self::DROPPED,
		self::GONE,
		self::FAILED,
	];


//  constructor

	public function __construct(
		public string  $kind,
		public ?string $reason = null,
	) {
	}


//  static methods

	public static function hashed(): self
	{
		return new self( self::HASHED );
	}

	public static function typed(): self
	{
		return new self( self::TYPED, 'its MIME type read' );
	}

	public static function stamped(): self
	{
		return new self( self::STAMPED, 'its hashes current, nothing to compute' );
	}

	public static function emptied(): self
	{
		return new self( self::EMPTIED, '`lazy` deletes its hashes and computes none' );
	}

	public static function dropped( string $reason ): self
	{
		return new self( self::DROPPED, $reason );
	}

	public static function gone(): self
	{
		return new self( self::GONE, 'the filecache lists it, and its storage does not have it; `occ files:scan` brings the filecache in line' );
	}

	public static function failed( string $reason ): self
	{
		return new self( self::FAILED, $reason );
	}


//  getters / setters / is* / has*

	/** Whether the file left the queue: everything but a failure. */
	public function hasLeftTheQueue(): bool
	{
		return $this->kind !== self::FAILED;
	}


//  other non-static methods

	/**
	 * The line `fcias:queue:drain -v` prints for the file: what happened, and
	 * why.
	 */
	public function describe( int $fileId ): string
	{
		return match ( $this->kind )
		{
			self::HASHED  => sprintf( 'fileId %d hashed.', $fileId ),
			self::FAILED  => sprintf( 'fileId %d could not be hashed: %s.', $fileId, $this->reason ?? 'unknown' ),
			self::DROPPED => sprintf( 'fileId %d dropped unread: %s.', $fileId, $this->reason ?? 'unknown' ),
			self::GONE    => sprintf( 'fileId %d gone from its storage: %s.', $fileId, $this->reason ?? '' ),
			default       => sprintf( 'fileId %d %s: %s.', $fileId, $this->kind, $this->reason ?? '' ),
		};
	}
}
