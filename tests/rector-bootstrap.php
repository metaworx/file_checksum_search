<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What Rector loads before it reads a line: every class the app's code
 * names, so that it never decides a class has no parent, or a method no
 * caller, because it could not see them.
 *
 * - PHPUnit, which lives in vendor-bin/phpunit, not in the root autoloader;
 *   without it every test case "has no parent" and loses parent::setUp().
 * - The server's autoloaders, found as tests/bootstrap.php finds a server:
 *   the one the app sits in, the container's, a nextcloud-v* tree beside the
 *   checkout, or FCIAS_NC_ROOT. Only the autoloaders: lib/base.php would
 *   boot the server, which a rewrite has no use for.
 *
 * Without a server it stops, rather than letting Rector rewrite against
 * what it cannot see.
 */

$appRoot = dirname( __DIR__ );

require_once $appRoot . '/vendor/autoload.php';
require_once $appRoot . '/vendor-bin/phpunit/vendor/autoload.php';

$candidates = [
	dirname( $appRoot, 2 ),
	'/var/www/html',
];

$override = getenv( 'FCIAS_NC_ROOT' );

if ( is_string( $override ) && $override !== '' )
{
	array_unshift( $candidates, $override );
}

$beside = glob( $appRoot . '/nextcloud-v*', GLOB_ONLYDIR ) ?: [];
rsort( $beside, SORT_NATURAL );

foreach ( array_merge( $candidates, $beside ) as $ncRoot )
{
	if ( file_exists( $ncRoot . '/3rdparty/autoload.php' ) && file_exists( $ncRoot . '/lib/composer/autoload.php' ) )
	{
		require_once $ncRoot . '/3rdparty/autoload.php';
		require_once $ncRoot . '/lib/composer/autoload.php';

		return;
	}
}

fwrite( STDERR, "rector-bootstrap: no Nextcloud server found; run Rector in the harness container, or set FCIAS_NC_ROOT.\n" );

exit( 1 );
