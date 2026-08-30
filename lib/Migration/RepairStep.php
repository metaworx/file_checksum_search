<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use Attribute;

/**
 * One named piece of repair, declared on the method that carries it out.
 *
 * The description's only job is to be true about *that* implementation, and
 * anything that lets the two sit apart lets them drift — a list of steps kept
 * beside the code would be right on the day it was written and wrong later.
 * So the declaration goes on the method, the way this app already declares its
 * routes.
 *
 * `expensive` marks a step whose cost grows with the instance rather than
 * being a fixed handful of writes. Nextcloud draws the same line for itself
 * with `maintenance:repair --include-expensive`.
 */
#[Attribute( Attribute::TARGET_METHOD )]
readonly class RepairStep
{

	public function __construct(
		/** How `--step` names it: lower case, dashes, no spaces. */
		public string $name,
		/** One line, for the list. */
		public string $title,
		/** What it does, what it does not, and when to reach for it. */
		public string $description,
		/** Cost grows with the size of the instance. */
		public bool   $expensive = false,
	) {
	}

}
