<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit;

use mwx\Nextcloud\Testing\EnglishL10n;

/**
 * The doubles of mwx/nextcloud-testing this app's tests use: an English or
 * marking `IL10N`. For a class extending PHPUnit's `TestCase`, whose
 * `createMock()` the doubles use.
 */
trait AppDoubles
{
	use EnglishL10n;
}
