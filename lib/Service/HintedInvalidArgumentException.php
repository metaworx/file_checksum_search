<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use InvalidArgumentException;
use Throwable;

/**
 * An invalid argument whose message is English, for the log, and whose hint
 * says the same in the reader's language, for whoever is shown it.
 *
 * Nextcloud's own HintException makes the same split, but is no
 * InvalidArgumentException, which is what every caller of the rule services
 * catches. A log stays English (AGENTS.md §3.7); a person gets the hint.
 */
class HintedInvalidArgumentException
    extends
    InvalidArgumentException
{

//  constructor

	public function __construct(
		string                 $message,
		public readonly string $hint,
		int                    $code = 0,
		?Throwable             $previous = null,
	)
	{
		parent::__construct( $message, $code, $previous );
	}


//  static methods

	/**
	 * What to show a person for any invalid argument: the hint where there
	 * is one, the message otherwise.
	 */
	public static function textFor( InvalidArgumentException $e ): string
	{
		return $e instanceof self
			? $e->hint
			: $e->getMessage();
	}
}
