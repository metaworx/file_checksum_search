<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit;

use mwx\Nextcloud\Testing\EnglishL10n;
use mwx\Nextcloud\Testing\MemoryAppConfig;
use mwx\Nextcloud\Testing\MemorySystemConfig;
use mwx\Nextcloud\Testing\MemoryUserConfig;

/**
 * The doubles of mwx/nextcloud-testing this app's tests use: an English or
 * marking `IL10N`, and app, user and system values in `$this->appValues`,
 * `$this->userValues` and `$this->systemValues`. For a class extending
 * PHPUnit's `TestCase`, whose `createMock()` the doubles use.
 *
 * A test's own `expects()` on a double is counted and its `with()` checked,
 * but the double answers: a value the unit is to read goes into the store.
 */
trait AppDoubles
{
	use EnglishL10n;
	use MemoryAppConfig;
	use MemorySystemConfig;
	use MemoryUserConfig;
}
