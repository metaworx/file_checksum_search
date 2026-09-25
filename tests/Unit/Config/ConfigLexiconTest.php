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

//  private properties

	private ConfigLexicon $lexicon;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->lexicon = new ConfigLexicon();
	}


//  other non-static methods

	public function testGetStrictnessReturnsWarning(): void
	{
		$this->assertSame( Strictness::WARNING, $this->lexicon->getStrictness() );
	}

	public function testGetAppConfigsReturnsExpectedKeys(): void
	{
		$configs = $this->lexicon->getAppConfigs();

		$this->assertIsArray( $configs );
		$this->assertCount( 28, $configs );

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
		$this->assertContains( 'instance_view_all_users', $keys );
		$this->assertContains( 'instance_view_groups', $keys );
		$this->assertContains( 'instance_view_users', $keys );
		$this->assertContains( 'manual_recalc_all_users', $keys );
		$this->assertContains( 'manual_recalc_groups', $keys );
		$this->assertContains( 'manual_recalc_users', $keys );
		$this->assertContains( 'api_access_all_users', $keys );
		$this->assertContains( 'api_access_groups', $keys );
		$this->assertContains( 'api_access_users', $keys );
	}

	public function testGetUserConfigsDeclaresThePerUserKeys(): void
	{
		$userConfigs = $this->lexicon->getUserConfigs();
		$this->assertCount( 2, $userConfigs );
		$this->assertSame( 'preferred_algorithm', $userConfigs[0]->getKey() );
		$this->assertSame( 'sudo_tokens', $userConfigs[1]->getKey() );
	}
}
