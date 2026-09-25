<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\State;

use InvalidArgumentException;

/**
 * How an import treats what it is given.
 *
 * The stamp policy is the load-bearing decision here, because freshness is
 * decided by `updated_at >= mtime` everywhere in the app: **a hash stamped
 * later than the content it describes is invisible to every correction path
 * there is**. The sweep will call the file fresh forever, and no rule will
 * ever recompute it. So the default is the conservative one — take the
 * source's own timestamp, and refuse records older than the file.
 */
readonly class ImportPolicy
{

//  constants

	/** Use the record's own timestamp; refuse records older than the file. */
	public const STAMP_SOURCE = 'source';

	/** Claim the hash matches the file as it is now — what a fresh sumfile means. */
	public const STAMP_MTIME = 'mtime';

	/** Stamp the moment of import. Asserts more than the data supports. */
	public const STAMP_NOW = 'now';

	public const STAMPS
		 = [
			self::STAMP_SOURCE,
			self::STAMP_MTIME,
			self::STAMP_NOW,
		];


//  constructor

	public function __construct(
		/** Write only what is absent, rather than replacing what is there. */
		public bool   $merge = true,
		public string $stamp = self::STAMP_SOURCE,
		/** Write records older than the file anyway, warning about each. */
		public bool   $allowOutdated = false,
		/** A path this instance does not have fails the run instead of being counted. */
		public bool   $strict = false,
		/** Report what would happen; write nothing. */
		public bool   $dryRun = false,
	)
	{
		if ( ! in_array( $this->stamp, self::STAMPS, true ) )
		{
			throw new InvalidArgumentException(
				sprintf(
					'Unknown stamp policy "%s"; expected one of %s.',
					$this->stamp,
					implode( ', ', self::STAMPS ),
				),
			);
		}
	}


//  other non-static methods

	/**
	 * Whether this policy asserts something the data does not support, and
	 * so deserves saying out loud.
	 */
	public function warrantsWarning(): bool
	{
		return $this->stamp === self::STAMP_NOW || $this->allowOutdated;
	}
}
