<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

if ( ! defined( 'PHPUNIT_RUN' ) )
{
	define( 'PHPUNIT_RUN', 1 );
}

// Load Nextcloud test bootstrap if available
$ncBootstrap = __DIR__ . '/../../../tests/bootstrap.php';
if ( file_exists( $ncBootstrap ) )
{
	require_once $ncBootstrap;
}
