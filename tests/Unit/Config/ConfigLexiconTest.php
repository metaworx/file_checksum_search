<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Config;

use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\Strictness;
use PHPUnit\Framework\TestCase;

class ConfigLexiconTest
	extends
	TestCase
{

	private ConfigLexicon $lexicon;


	protected function setUp(): void
	{

		parent::setUp();

		$this->lexicon = new ConfigLexicon();
	}


	public function testGetStrictnessReturnsWarning(): void
	{

		$this->assertSame( Strictness::WARNING, $this->lexicon->getStrictness() );
	}


	public function testGetAppConfigsReturnsExpectedKeys(): void
	{

		$configs = $this->lexicon->getAppConfigs();

		$this->assertIsArray( $configs );
		$this->assertCount( 17, $configs );

		$keys = array_map(
			static fn(
				Entry $e,
			): string => $e->getKey(),
			$configs,
		);

		$this->assertContains( 'rule_definitions', $keys );
		$this->assertContains( 'idle_banner_ack', $keys );
		$this->assertContains( 'stats_rule_sweep_last_run', $keys );
		$this->assertContains( 'stats_rule_sweep_last_counts', $keys );
		$this->assertContains( 'stats_pending_drain_last_run', $keys );
		$this->assertContains( 'stats_pending_drain_last_counts', $keys );
		$this->assertContains( 'rule_processing_interval', $keys );
		$this->assertContains( 'process_pending_interval', $keys );
		$this->assertContains( 'pending_batch_limit', $keys );
		$this->assertContains( 'allowed_algorithms', $keys );
		$this->assertContains( 'orphan_purge_interval', $keys );
		$this->assertContains( 'orphan_purge_last_run', $keys );
		$this->assertContains( 'stats_orphan_purge_last_run', $keys );
		$this->assertContains( 'stats_orphan_purge_last_counts', $keys );
		$this->assertContains( 'rule_editors_all_users', $keys );
		$this->assertContains( 'rule_editors_groups', $keys );
		$this->assertContains( 'rule_editors_users', $keys );
	}


	public function testGetUserConfigsReturnsEmpty(): void
	{

		$this->assertSame( [], $this->lexicon->getUserConfigs() );
	}

}
