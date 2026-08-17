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
		$this->assertCount( 5, $configs );

		$keys = array_map(
			static fn ( Entry $e ): string => $e->getKey(),
			$configs,
		);

		$this->assertContains( 'rule_definitions', $keys );
		$this->assertContains( 'rule_processing_interval', $keys );
		$this->assertContains( 'rule_editors_all_users', $keys );
		$this->assertContains( 'rule_editors_groups', $keys );
		$this->assertContains( 'rule_editors_users', $keys );
	}


	public function testGetUserConfigsReturnsEmpty(): void
	{

		$this->assertSame( [], $this->lexicon->getUserConfigs() );
	}

}
