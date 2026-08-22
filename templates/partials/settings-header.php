<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * App heading shared by the admin and personal settings pages, so the logo and
 * title stay identical on both.
 *
 * Included from within another template, and therefore relies on that
 * template's scope:
 *
 * @var \OCP\IL10N $l injected by NC's TemplateResponse renderer.
 */
?>

<h3 class="fcias-settings-title">
	<?php
	echo str_replace( 'fill="#fff"', 'fill="currentColor"', file_get_contents( __DIR__ . '/../../img/app.svg' ) ); ?>
	<?php
	p( $l->t( 'File Checksum Index & Search' ) ); ?>
</h3>
