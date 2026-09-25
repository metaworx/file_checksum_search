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
 * with `maintenance:repair --include-expensive`. `manualOnly` goes further:
 * a step that cannot even ask cheaply whether it has anything to do.
 */
#[Attribute( Attribute::TARGET_METHOD )]
readonly class RepairStep
{

//  constructor

	public function __construct(
		/** How `--step` names it: lower case, dashes, no spaces. */
		public string $name,
		/** One line, for the list. */
		public string $title,
		/** What it does, what it does not, and when to reach for it. */
		public string $description,
		/** Cost grows with the size of the instance. */
		public bool   $expensive = false,
		/**
		 * Never runs unless it is asked for by name, or by
		 * `--include-expensive`.
		 *
		 * For a step that cannot tell cheaply whether it has work, because
		 * finding the work *is* the expense. Every other expensive step can
		 * ask first — two counts, an empty subquery — and so is safe to run
		 * automatically; one that cannot would make every repair pay for a
		 * search that almost always finds nothing.
		 */
		public bool   $manualOnly = false,
	) {
	}
}
