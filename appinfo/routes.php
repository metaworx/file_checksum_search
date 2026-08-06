<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

return [
	'routes' => [
		// Public API v1
		[
			'name' => 'public_api#lookup',
			'url'  => '/api/v1/lookup',
			'verb' => 'GET',
		],
		[
			'name' => 'public_api#getHashes',
			'url'  => '/api/v1/file/{fileId}/hashes',
			'verb' => 'GET',
		],
		[
			'name' => 'public_api#findDuplicates',
			'url'  => '/api/v1/file/{fileId}/duplicates',
			'verb' => 'GET',
		],
		[
			'name' => 'public_api#recalcHash',
			'url'  => '/api/v1/file/{fileId}/recalc',
			'verb' => 'POST',
		],
		[
			'name' => 'public_api#findAllDuplicates',
			'url'  => '/api/v1/duplicates',
			'verb' => 'GET',
		],
		[
			'name' => 'public_api#getStatus',
			'url'  => '/api/v1/status',
			'verb' => 'GET',
		],

		// Global duplicate browser
		[
			'name' => 'duplicates#index',
			'url'  => '/duplicates',
			'verb' => 'GET',
		],

		// Admin settings AJAX
		[
			'name' => 'settings#getStatus',
			'url'  => '/settings/status',
			'verb' => 'GET',
		],

		// Rule management
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
