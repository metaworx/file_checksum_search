<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * @var array $_ Template parameters (unused)
 */

use OCP\Util;

Util::addScript( 'file_checksum_search', 'duplicates' );
Util::addStyle( 'file_checksum_search', 'style' );

?>

<fcias-duplicate-browser></fcias-duplicate-browser>
