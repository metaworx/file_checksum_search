<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\Util;

Util::addScript( Application::APP_ID, Application::APP_ID . '-settings-personal' );
Util::addStyle( Application::APP_ID, Application::APP_ID . '-settings-personal' );

/** @var \OCP\IL10N $l is auto-injected by NC's TemplateResponse renderer via \OCP\Util::getL10N('file_checksum_search'). */
?>

<?php
require __DIR__ . '/partials/settings-header.php'; ?>

<div id="fcias-personal-settings"></div>
