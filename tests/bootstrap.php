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

// Load Nextcloud autoloader (OCP classes)
$ncVendorAutoload = __DIR__ . '/../../../3rdparty/autoload.php';
if ( ! file_exists( $ncVendorAutoload ) )
{
	$ncVendorAutoload = '/var/www/html/3rdparty/autoload.php';
}
if ( file_exists( $ncVendorAutoload ) )
{
	require_once $ncVendorAutoload;
}

// Load NC lib base (OCP interfaces and classes)
$ncLibBase = __DIR__ . '/../../../lib/base.php';
if ( ! file_exists( $ncLibBase ) )
{
	$ncLibBase = '/var/www/html/lib/base.php';
}
if ( file_exists( $ncLibBase ) )
{
	require_once $ncLibBase;
}
