<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

return [
	'routes' => [
		// REST API
		[
			'name' => 'lookup#byHash',
			'url'  => '/api/1.0/lookup',
			'verb' => 'GET',
		],
		[
			'name' => 'lookup#getHashesByFileId',
			'url'  => '/api/1.0/file/{fileId}/hashes',
			'verb' => 'GET',
		],
		[
			'name' => 'lookup#recalcHash',
			'url'  => '/api/1.0/file/{fileId}/recalc',
			'verb' => 'POST',
		],

		// Admin settings AJAX
		[
			'name' => 'settings#getStatus',
			'url'  => '/settings/status',
			'verb' => 'GET',
		],
		[
			'name' => 'settings#runCompatibilityTest',
			'url'  => '/settings/compatibility',
			'verb' => 'GET',
		],
		[
			'name' => 'settings#purgeIndex',
			'url'  => '/settings/purge',
			'verb' => 'POST',
		],
		[
			'name' => 'settings#rebuildIndex',
			'url'  => '/settings/rebuild',
			'verb' => 'POST',
		],
		[
			'name' => 'settings#teardownTriggers',
			'url'  => '/settings/teardown',
			'verb' => 'POST',
		],
		[
			'name' => 'settings#removeTable',
			'url'  => '/settings/remove-table',
			'verb' => 'POST',
		],
		[
			'name' => 'settings#deployTriggers',
			'url'  => '/settings/deploy-triggers',
			'verb' => 'POST',
		],
		[
			'name' => 'settings#createTable',
			'url'  => '/settings/create-table',
			'verb' => 'POST',
		],
	],
];
