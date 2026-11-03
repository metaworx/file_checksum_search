<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

/*
 * Where Nextcloud is, in one call: a server this app is deployed into,
 * booted through lib/base.php, as CI runs the suite; a server source tree
 * beside the app (nextcloud-v33, nextcloud-v34, ...) or wherever
 * FCIAS_NC_ROOT points, autoloaders only; or no server at all, the OCP API
 * from nextcloud/ocp and the package's stub of OC\Hooks\Emitter. The
 * package says the rest.
 */
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

\mwx\Nextcloud\Testing\Bootstrap::nextcloud( dirname( __DIR__ ), 'FCIAS_NC_ROOT' );
