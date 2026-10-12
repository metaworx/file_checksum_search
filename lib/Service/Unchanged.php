<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

/**
 * "Leave it as it is", where null already means something.
 *
 * A partial update takes it for a value it is not to touch
 * ({@see MetadataService::setSelfIndexed()}), so that null can mean NULL.
 *
 * An enum rather than an object: a case is a constant expression, so it can
 * be a parameter's default and a class constant, and it is the one instance
 * there is, so `===` and `instanceof` agree.
 */
enum Unchanged
{

//  cases

	case Unchanged;
}
