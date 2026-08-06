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
				key: 'rule_definitions',
				type: ValueType::STRING,
				defaultRaw: '[]',
				definition: 'JSON array of rule definitions for hash generation.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'rule_processing_interval',
				type: ValueType::INT,
				defaultRaw: 300,
				definition: 'Interval in seconds for RuleProcessingJob.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
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
