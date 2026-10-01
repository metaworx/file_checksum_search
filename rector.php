<?php

declare( strict_types=1 );

/**
 * Rector, as an upgrade tool only: Nextcloud's own rules for its deprecated
 * APIs, run when the app takes on a new Nextcloud version. No general rule
 * sets — ECS keeps the style and Psalm the types — and no CI step.
 *
 * The set is the one for the OLDEST Nextcloud the app supports, never a
 * newer one: a newer set rewrites to APIs the older server does not have.
 * Each set includes the ones before it. Move it up when the oldest
 * supported version moves.
 *
 * Runs where a server is (tests/rector-bootstrap.php): the harness container.
 *
 * @example composer rector:check
 * @example composer rector
 */

use Nextcloud\Rector\Set\NextcloudSets;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
	->withPaths(
		[
			__DIR__ . '/lib',
			__DIR__ . '/tests',
		],
	)
	->withSkip(
		[
			// Fixtures and stubs are what they are on purpose.
			__DIR__ . '/tests/e2e',
			__DIR__ . '/tests/stubs',
			__DIR__ . '/tests/rector-bootstrap.php',
		],
	)
	->withBootstrapFiles( [ __DIR__ . '/tests/rector-bootstrap.php' ] )
	->withSets( [ NextcloudSets::NEXTCLOUD_33 ] )
;
