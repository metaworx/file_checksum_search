<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use OCP\Migration\IOutput;

/**
 * A repair step's output that can say more than Nextcloud's `IOutput`: lines
 * at the console's verbosity levels, `-v` to `-vvv`.
 *
 * What `occ fcias:repair` hands a step, and only it: a step run by
 * Nextcloud's own repair — installing, upgrading, `occ maintenance:repair` —
 * gets a plain `IOutput`, and says what it says at the default level. A
 * warning shows at every level, `-q` included.
 */
interface VerboseOutput
    extends
    IOutput
{

//  constants

	/** `-v`: what a run found, and how far it has got. */
	public const VERBOSE = 1;

	/** `-vv`: each file it acts on. */
	public const VERY_VERBOSE = 2;

	/** `-vvv`: each file with the values written. */
	public const DEBUG = 3;


//  other non-static methods

	/**
	 * Whether a line at $level would be shown, for a step to skip the work
	 * of saying it.
	 */
	public function shows( int $level ): bool;

	/**
	 * A line shown from $level on.
	 */
	public function line(
		int    $level,
		string $message,
	): void;
}
