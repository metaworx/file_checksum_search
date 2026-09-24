<?php

declare( strict_types=1 );

/**
 * ECS configuration: the preset published as mwx/coding-standard, over this
 * app's PHP — lib/, tests/, appinfo/ and templates/. The frontend under src/
 * is TypeScript and Vue, and ESLint's.
 *
 * @example composer cs:check
 * @example composer cs:fix
 * @example vendor/bin/mwx-ecs check lib/Service/FileLocation.php --fix
 */

use mwx\ECS\Config\ComposedConfig;
use Symplify\EasyCodingStandard\Config\ECSConfig;

$preset = __DIR__ . '/vendor-bin/ecs/vendor/mwx/coding-standard/config/ecs';

return ComposedConfig::fromFiles( $preset . '/default.php' )
                     ->with( ECSConfig::configure()->withPaths( [
                         __DIR__ . '/lib',
                         __DIR__ . '/tests',
                         __DIR__ . '/appinfo',
                         __DIR__ . '/templates',
                     ] ) )
;
