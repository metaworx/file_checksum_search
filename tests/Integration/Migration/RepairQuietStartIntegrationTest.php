<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Migration;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Migration\RepairQuietStart;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Server;

/**
 * The `selector-model` repair step against real stored rules.
 *
 * The unit tests for this step mock RuleService, so they assert that the
 * step calls it — not that a rule written by an older version comes out
 * the other side meaning the same thing. That is the only question an
 * upgrade actually asks, and answering it needs a real appconfig value,
 * a real decode, and the real canonicalisation.
 *
 * Everything here is seeded as the *old* shapes on purpose: `userScope`
 * where `selector` now is, the retired `pinned` flag, and `mode: off`
 * where a rule of type `ignore` now is. Those spellings are gone from
 * the code that writes rules, which is exactly why nothing but a test
 * like this can tell you they are still read.
 */
class RepairQuietStartIntegrationTest
	extends
	DatabaseTestCase
{

	private const CONFIG_KEY = 'rule_definitions';

	private RuleService   $ruleService;

	private IAppConfig    $appConfig;


	protected function setUp(): void
	{

		parent::setUp();

		$this->appConfig   = Server::get( IAppConfig::class );
		$this->ruleService = Server::get( RuleService::class );

		$this->preserveStoredRules();
	}


	/**
	 * With both defaults already present and canonical, and no `mode: off`
	 * rule to convert, nothing else in the step writes — so this is the one
	 * case where only `resaveCanonical()` can have done the rewriting.
	 *
	 * Worth its own test because the others do not pin it: recreating a
	 * missing default goes through the write path, which canonicalises the
	 * whole list on its way past. Every assertion below passed with
	 * `resaveCanonical()` removed until this case existed.
	 */
	public function testALegacyRuleIsCanonicalisedWithNothingElseToDo(): void
	{

		$this->givenStoredRules( [
			[
				'id'        => 'legacy_only',
				'enabled'   => true,
				'type'      => 'include',
				'path'      => '/Docs/**',
				'mode'      => 'auto',
				'algos'     => [ 'sha1' ],
				'userScope' => 'all',
			],
			$this->canonicalDefault( 'home:*' ),
			$this->canonicalDefault( '*' ),
		] );

		$this->runSelectorModel();

		$rule = $this->ruleById( 'legacy_only' );

		$this->assertSame( 'home:*', $rule['selector'] );
		$this->assertArrayNotHasKey( 'userScope', $rule );
	}


	public function testALegacyScopeBecomesASelector(): void
	{

		$this->givenStoredRules( [
			[
				'id'        => 'legacy_all',
				'enabled'   => true,
				'type'      => 'include',
				'path'      => '/Docs/**',
				'mode'      => 'auto',
				'algos'     => [ 'sha1' ],
				'userScope' => 'all',
			],
			[
				'id'        => 'legacy_user',
				'enabled'   => true,
				'type'      => 'include',
				'path'      => '/Photos/**',
				'mode'      => 'auto',
				'algos'     => [ 'sha1' ],
				'userScope' => 'someuser',
			],
		] );

		$this->runSelectorModel();

		// `all` addressed every user's files, which the selector model
		// spells `home:*`; a bare uid addressed that one user's, which is
		// `home:<uid>`. Neither is `*`, which reaches storages no
		// pre-selector rule could ever have named.
		$this->assertSame( 'home:*', $this->ruleById( 'legacy_all' )['selector'] );
		$this->assertSame( 'home:someuser', $this->ruleById( 'legacy_user' )['selector'] );

		foreach (
			[
				'legacy_all',
				'legacy_user',
			] as $id
		)
		{
			$this->assertArrayNotHasKey(
				'userScope',
				$this->ruleById( $id ),
				'the key it was read from is not written back',
			);
		}
	}


	public function testTheRetiredPinnedFlagIsDropped(): void
	{

		$this->givenStoredRules( [
			[
				'id'        => 'legacy_pinned',
				'enabled'   => true,
				'type'      => 'include',
				'path'      => '**',
				'mode'      => 'auto',
				'algos'     => [ 'sha1' ],
				'userScope' => 'all',
				'pinned'    => true,
			],
		] );

		$this->runSelectorModel();

		$rule = $this->ruleById( 'legacy_pinned' );

		$this->assertArrayNotHasKey( 'pinned', $rule );

		// What pinned used to assert is now derived from shape: a rule whose
		// glob is the bare catch-all trails its segment, whether or not
		// anybody ever flagged it.
		$this->assertTrue( RuleService::isDefaultShaped( $rule ) );
	}


	public function testTheRetiredOffModeBecomesAnIgnoreRule(): void
	{

		$this->givenStoredRules( [
			[
				'id'        => 'legacy_off',
				'enabled'   => true,
				'type'      => 'include',
				'path'      => '/Legacy/**',
				'mode'      => 'off',
				'algos'     => [ 'sha1' ],
				'userScope' => 'all',
			],
		] );

		$this->runSelectorModel();

		$rule = $this->ruleById( 'legacy_off' );

		// "Compute nothing here" was a mode; it is a verdict now. The
		// distinction matters because a mode belongs to include rules and a
		// verdict decides whether the rule is one.
		$this->assertSame( RuleService::TYPE_IGNORE, $rule['type'] );
		$this->assertArrayNotHasKey( 'mode', $rule );
		$this->assertArrayNotHasKey( 'algos', $rule );
	}


	public function testBothShippedDefaultsComeBackDisabled(): void
	{

		$this->givenStoredRules( [] );

		$this->runSelectorModel();

		$defaults = [];

		foreach ( $this->ruleService->loadRules( refresh: true ) as $rule )
		{
			if ( RuleService::isDefaultShaped( $rule ) )
			{
				$defaults[ RuleService::ruleSelector( $rule )
				                      ->canonical() ]
					= $rule;
			}
		}

		$this->assertArrayHasKey( 'home:*', $defaults, 'every home folder' );
		$this->assertArrayHasKey( '*', $defaults, 'every storage there is' );

		// The quiet start: an upgrade may put the decision in front of an
		// administrator, and may not take it for them.
		foreach ( $defaults as $selector => $rule )
		{
			$this->assertFalse( $rule['enabled'], "the $selector default is created disabled" );
		}
	}


	public function testAConfiguredRuleIsNotEnabledOrDisabledByTheRepair(): void
	{

		$this->givenStoredRules( [
			[
				'id'        => 'operator_disabled',
				'enabled'   => false,
				'type'      => 'include',
				'path'      => '**',
				'mode'      => 'auto',
				'algos'     => [ 'sha1' ],
				'userScope' => 'all',
			],
		] );

		$this->runSelectorModel();

		// It is default-shaped and addresses `home:*`, so it *is* the shipped
		// default's segment — and it stays off, because an upgrade never
		// turns on what an administrator turned off.
		$this->assertFalse( $this->ruleById( 'operator_disabled' )['enabled'] );
	}


	public function testRunningItTwiceChangesNothingTheSecondTime(): void
	{

		$this->givenStoredRules( [
			[
				'id'        => 'legacy_all',
				'enabled'   => true,
				'type'      => 'include',
				'path'      => '/Docs/**',
				'mode'      => 'auto',
				'algos'     => [ 'sha1' ],
				'userScope' => 'all',
			],
		] );

		$this->runSelectorModel();

		$afterFirst = $this->appConfig->getValueString( Application::APP_ID, self::CONFIG_KEY, '' );

		$this->runSelectorModel();

		// Every repair step is run again on every upgrade, so "idempotent"
		// is not a nicety here: a step that rewrote something each time
		// would churn an instance's configuration for ever.
		$this->assertSame(
			$afterFirst,
			$this->appConfig->getValueString( Application::APP_ID, self::CONFIG_KEY, '' ),
		);
	}


	// ─── helpers ─────────────────────────────────────────────────────

	/**
	 * Put rules into storage the way an older version would have, going
	 * around the write path so nothing canonicalises them on the way in.
	 *
	 * @param  list<array>  $rules
	 */
	private function givenStoredRules( array $rules ): void
	{

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY,
			json_encode( $rules, JSON_THROW_ON_ERROR ),
		);

		// The decoded list is memoised per process and invalidated only
		// through the write path this just went around.
		$this->ruleService->loadRules( refresh: true );
	}


	/**
	 * A shipped default as the current model writes it, so seeding it
	 * leaves the step with no default to recreate.
	 *
	 * @return array<string, mixed>
	 */
	private function canonicalDefault( string $selector ): array
	{

		return [
			'id'             => 'canonical_' . md5( $selector ),
			'enabled'        => false,
			'type'           => RuleService::TYPE_INCLUDE,
			'path'           => '**',
			'selector'       => $selector,
			'mode'           => 'auto',
			'algos'          => [
				'sha1',
				'md5',
			],
			'admin_enforced' => false,
		];
	}


	private function runSelectorModel(): void
	{

		Server::get( RepairQuietStart::class )
		      ->runSteps( $this->createMock( IOutput::class ), [ 'selector-model' ] )
		;
	}


	private function ruleById( string $id ): array
	{

		foreach ( $this->ruleService->loadRules( refresh: true ) as $rule )
		{
			if ( ( $rule['id'] ?? '' ) === $id )
			{
				return $rule;
			}
		}

		$this->fail( "No rule with id $id after the repair." );
	}
}
