<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\Util;

Util::addScript( Application::APP_ID, Application::APP_ID . '-settings-admin' );
Util::addStyle( Application::APP_ID, Application::APP_ID . '-settings-admin' );

/** @var \OCP\IL10N $l is auto-injected by NC's TemplateResponse renderer via \OCP\Util::getL10N('file_checksum_search'). */
?>

<h3>
	<?php
	echo str_replace( 'fill="#fff"', 'fill="currentColor"', file_get_contents( __DIR__ . '/../img/app.svg' ) ); ?>
	<?php
	p( $l->t( 'File Checksum Index & Search' ) ); ?>
</h3>

<div id="fcias-admin-settings"></div>
