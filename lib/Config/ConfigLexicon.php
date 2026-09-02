<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Config;

use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
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
				key: AlgorithmCatalogue::CONFIG_KEY,
				type: ValueType::ARRAY,
				defaultRaw: AlgorithmCatalogue::DEFAULT_ALLOWLIST,
				definition: 'Hash algorithms this instance computes, from what PHP offers. Empty falls back to the shipped default.',
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
			new Entry(
				key: 'process_pending_interval',
				type: ValueType::INT,
				defaultRaw: 60,
				definition: 'Interval in seconds between ProcessPendingUpdates runs.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'orphan_purge_interval',
				type: ValueType::INT,
				defaultRaw: 86400,
				definition: 'Seconds between purges of metadata for files that no longer exist. The purge rides RuleProcessingJob and keeps its own clock.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'orphan_purge_last_run',
				type: ValueType::INT,
				defaultRaw: 0,
				definition: 'When the orphan purge last emptied its backlog, epoch seconds. Zero means due now; deleting a user sets it.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'pending_batch_limit',
				type: ValueType::INT,
				defaultRaw: 50,
				definition: 'How many queued files ProcessPendingUpdates drains per run.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'idle_banner_ack',
				type: ValueType::BOOL,
				defaultRaw: false,
				definition: 'Administrator acknowledged the idle banner (no enabled include rule). '
				. 'Cleared automatically when an include rule is enabled.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'stats_rule_sweep_last_run',
				type: ValueType::INT,
				defaultRaw: 0,
				definition: 'Unix timestamp of the rule sweep\'s last completed run.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'stats_rule_sweep_last_counts',
				type: ValueType::STRING,
				defaultRaw: '[]',
				definition: 'JSON counts of the rule sweep\'s last run (matched/marked).',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'stats_pending_drain_last_run',
				type: ValueType::INT,
				defaultRaw: 0,
				definition: 'Unix timestamp of the pending-queue drain\'s last completed run.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'stats_pending_drain_last_counts',
				type: ValueType::STRING,
				defaultRaw: '[]',
				definition: 'JSON counts of the pending-queue drain\'s last run (processed/failed/total).',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'stats_orphan_purge_last_run',
				type: ValueType::INT,
				defaultRaw: 0,
				definition: 'Unix timestamp of the orphan purge\'s last run.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'stats_orphan_purge_last_counts',
				type: ValueType::STRING,
				defaultRaw: '[]',
				definition: 'JSON counts of the orphan purge\'s last run (purged/batches).',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'rule_editors_all_users',
				type: ValueType::BOOL,
				defaultRaw: false,
				definition: 'Whether all users may edit rules.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'rule_editors_groups',
				type: ValueType::STRING,
				defaultRaw: '[]',
				definition: 'JSON array of group IDs allowed to edit rules.',
				lazy: false,
				flags: IAppConfig::FLAG_INTERNAL,
			),
			new Entry(
				key: 'rule_editors_users',
				type: ValueType::STRING,
				defaultRaw: '[]',
				definition: 'JSON array of user IDs allowed to edit rules.',
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
