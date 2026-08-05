<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Config;

use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\ILexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;
use OCP\IAppConfig;

/**
 * Config lexicon for file_checksum_search app config keys.
 *
 * Registered via IRegistrationContext::registerConfigLexicon() in Application::register().
 * Eliminates "not defined in the config lexicon" info log entries from NC core.
 */
class ConfigLexicon
	implements
	ILexicon
{

	public function getStrictness(): Strictness
	{

		return Strictness::WARNING;
	}


	/**
	 * @return Entry[]
	 */
	public function getAppConfigs(): array
	{

		return [
			new Entry(
				key: 'triggers_deployed',
				type: ValueType::BOOL,
				defaultRaw: false,
				definition: 'Tracks whether DB triggers/SP have been deployed for file checksum indexing.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'cron_job_definitions',
				type: ValueType::STRING,
				defaultRaw: '[]',
				definition: 'JSON array of cron job definitions for scheduled hash generation.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'update_hash_on_file_write',
				type: ValueType::STRING,
				defaultRaw: 'auto',
				definition: 'Behavior when a file is written: off, force, lazy, auto.',
				lazy: false,
			),
			new Entry(
				key: 'update_hash_on_file_create',
				type: ValueType::STRING,
				defaultRaw: 'off',
				definition: 'Behavior when a file is created: off, lazy, force.',
				lazy: false,
			),
			new Entry(
				key: 'update_hash_on_file_delete',
				type: ValueType::STRING,
				defaultRaw: 'off',
				definition: 'Behavior when a file is deleted: off, on.',
				lazy: false,
			),
		];
	}


	/**
	 * @return array
	 */
	public function getUserConfigs(): array
	{

		return [];
	}

}
