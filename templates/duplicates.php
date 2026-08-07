<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * @var array $_ Template parameters (unused)
 */

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\Util;

Util::addScript( Application::APP_ID, Application::APP_ID . '-duplicates' );
Util::addStyle( Application::APP_ID, Application::APP_ID . '-duplicates' );

?>

<div id="fcias-duplicates"></div>
