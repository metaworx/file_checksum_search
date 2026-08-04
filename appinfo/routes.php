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
		[
			'name' => 'lookup#sameHash',
			'url'  => '/api/1.0/file/{fileId}/same-hash',
			'verb' => 'GET',
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

		// Cron job management
		[
			'name' => 'settings#listJobDefinitions',
			'url'  => '/settings/cron/definitions',
			'verb' => 'GET',
		],
		[
			'name' => 'settings#saveJobDefinition',
			'url'  => '/settings/cron/save',
			'verb' => 'POST',
		],
		[
			'name' => 'settings#deleteJobDefinition',
			'url'  => '/settings/cron/delete',
			'verb' => 'POST',
		],
		[
			'name' => 'settings#toggleJobDefinition',
			'url'  => '/settings/cron/toggle',
			'verb' => 'POST',
		],
		[
			'name' => 'settings#getCrontabSnippet',
			'url'  => '/settings/cron/snippet',
			'verb' => 'GET',
		],
	],
];
