<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command\Rules;

use OCA\FileChecksumSearch\Command\Rules\AddRule;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCA\FileChecksumSearch\Command\Rules\ApplyRule;
use OCA\FileChecksumSearch\Command\Rules\DeleteRule;
use OCA\FileChecksumSearch\Command\Rules\ListRules;
use OCA\FileChecksumSearch\Command\Rules\ModifyRule;
use OCA\FileChecksumSearch\Service\HintedInvalidArgumentException;
use OCA\FileChecksumSearch\Service\RuleDefinitionValidator;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Unit\EnglishL10n;
use OCP\IGroupManager;
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The commands are thin API layers over RuleService and the shared
 * validator; these tests pin the layer contract — what is parsed, what is
 * delegated with which arguments, what the operator reads back — not the
 * semantics, which live in the services' own tests.
 */
class RulesCommandsTest
    extends
    TestCase
{
	use EnglishL10n;


//  private properties

	private MockObject|RuleService  $ruleService;

	private RuleDefinitionValidator $validator;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->ruleService = $this->createMock( RuleService::class );

		$groupManager = $this->createMock( IGroupManager::class );
		$groupManager->method( 'groupExists' )
		             ->willReturn( true )
		;
		$userManager = $this->createMock( IUserManager::class );
		$userManager->method( 'userExists' )
		            ->willReturn( true )
		;

		$this->validator = new RuleDefinitionValidator( $groupManager, $userManager, new AlgorithmCatalogue( $this->createMock( IAppConfig::class ) ), $this->englishL10n() );
	}


//  other non-static methods

	private function tester( Command $command ): CommandTester
	{
		// The question helper (delete's confirm) needs an application.
		( new Application() )->add( $command );

		return new CommandTester( $command );
	}

	public function testListRendersEvaluationOrderWithPositions(): void
	{
		$this->ruleService->method( 'loadRules' )
		                  ->willReturn( [
			                  [
				                  'id'       => 'u1',
				                  'enabled'  => true,
				                  'path'     => '/docs/**',
				                  'selector' => 'home:alice',
			                  ],
			                  [
				                  'id'       => 'u2',
				                  'enabled'  => false,
				                  'path'     => '/img/**',
				                  'selector' => 'home:alice',
			                  ],
			                  [
				                  'id'       => 'd1',
				                  'enabled'  => true,
				                  'path'     => '**',
				                  'selector' => 'home:*',
			                  ],
		                  ] )
		;

		$tester = $this->tester( new ListRules( $this->ruleService, $this->validator ) );
		$tester->execute( [ '--output' => 'json' ] );

		$rows = json_decode( $tester->getDisplay(), true );

		// Ordinal within each band, matching the <band>.<position> the
		// settings pages show — the ids feed --ignore-rule and rules:apply.
		$this->assertSame(
			[
				'5.1',
				'5.2',
				'7.1',
			],
			array_column( $rows, 'priority' ),
		);
		$this->assertSame(
			[
				'u1',
				'u2',
				'd1',
			],
			array_column( $rows, 'id' ),
		);

		// Selector and glob as one address, the glob below the area's top.
		$this->assertSame(
			[
				'home:alice//docs/**',
				'home:alice//img/**',
				'home:*//**',
			],
			array_column( $rows, 'scope' ),
		);
	}

	/**
	 * The question before a delete names the rule by its scope.
	 */
	public function testDeleteAsksAboutTheRuleByItsScope(): void
	{
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( [ 'id' => 'r1', 'selector' => 'home:alice', 'path' => '/docs/**' ] )
		;
		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleDelete' )
		;

		$tester = $this->tester( new DeleteRule( $this->ruleService, $this->validator ) );
		$tester->setInputs( [ 'n' ] );
		$tester->execute( [ 'id' => 'r1' ] );

		$this->assertStringContainsString( 'Delete rule r1 (plain include on home:alice//docs/**)?', $tester->getDisplay() );
	}

	/**
	 * A scope is split at its last `//`, so a storage's id may hold `//`
	 * of its own.
	 */
	public function testAddTakesAScopeAsSelectorAndGlob(): void
	{
		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleAdd' )
		                  ->with(
			                  $this->callback(
				                  static fn( array $definition ): bool => $definition['selector'] === 'storage:smb::u@h//share'
					                  && $definition['path'] === 'Docs/**',
			                  ),
			                  'cli',
		                  )
		                  ->willReturn( 'newid' )
		;

		$tester = $this->tester( new AddRule( $this->ruleService, $this->validator ) );

		$this->assertSame( Command::SUCCESS, $tester->execute( [ '--scope' => 'storage:smb::u@h//share//Docs/**' ] ) );
	}

	/**
	 * A scope without its `//`, or beside --selector or --path, is refused
	 * before anything is written.
	 */
	public function testAScopeThatIsNoneIsRefused(): void
	{
		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleAdd' )
		;
		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleUpdate' )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( [ 'id' => 'r1', 'selector' => 'home:alice', 'path' => '**' ] )
		;

		$add = $this->tester( new AddRule( $this->ruleService, $this->validator ) );

		$this->assertSame( Command::FAILURE, $add->execute( [ '--scope' => 'home:alice' ] ) );
		$this->assertStringContainsString( 'has no "//"', $add->getDisplay() );

		$modify = $this->tester( new ModifyRule( $this->ruleService, $this->validator ) );

		$this->assertSame( Command::FAILURE, $modify->execute( [ 'id' => 'r1', '--scope' => 'home:alice//**', '--path' => 'x/**' ] ) );
		$this->assertStringContainsString( 'without --selector and --path', $modify->getDisplay() );
	}

	public function testAddDelegatesThroughTheSharedValidator(): void
	{
		// occ is trusted: scope and enforcement pass through as an admin's
		// would, and the definition reaching ruleAdd is the validator's
		// output, not the raw options.
		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleAdd' )
		                  ->with(
			                  $this->callback(
				                  static fn(
					                  array $definition,
				                  ): bool => $definition['selector'] === 'group:staff'
					                  && $definition['admin_enforced'] === true
					                  && $definition['enabled'] === false
					                  && $definition['algos'] === [ 'sha256' ],
			                  ),
			                  'cli',
		                  )
		                  ->willReturn( 'newid' )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn(
			                  [
				                  'id'      => 'newid',
				                  'enabled' => false,
			                  ],
		                  )
		;

		$tester = $this->tester( new AddRule( $this->ruleService, $this->validator ) );
		$exit   = $tester->execute(
			[
				'--path'     => '/legal/**',
				'--selector' => 'group:staff',
				'--algo'     => [ 'sha256' ],
				'--enforced' => true,
				'--disable'  => true,
			],
		);

		$this->assertSame( Command::SUCCESS, $exit );
		$this->assertStringContainsString( 'Created rule newid.', $tester->getDisplay() );
		$this->assertStringContainsString( 'The rule is disabled', $tester->getDisplay() );
	}

	public function testAddRejectsAnInvalidPayloadBeforeTouchingTheRules(): void
	{
		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleAdd' )
		;

		$tester = $this->tester( new AddRule( $this->ruleService, $this->validator ) );
		$exit   = $tester->execute( [ '--type' => 'blocklist' ] );

		$this->assertSame( Command::FAILURE, $exit );
		$this->assertStringContainsString( 'Unknown rule type.', $tester->getDisplay() );
	}

	public function testModifyRoutesABareToggleThroughRuleToggle(): void
	{
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn(
			                  [
				                  'id'      => 'r1',
				                  'enabled' => false,
			                  ],
		                  )
		;

		// The audit trail names the operation for what it is.
		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleToggle' )
		                  ->with( 'r1', true, 'cli' )
		;
		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleUpdate' )
		;

		$tester = $this->tester( new ModifyRule( $this->ruleService, $this->validator ) );
		$tester->execute(
			[
				'id'       => 'r1',
				'--enable' => true,
			],
		);

		$this->assertStringContainsString( 'Rule r1 enabled.', $tester->getDisplay() );
	}

	public function testModifyMergesOntoTheExistingRule(): void
	{
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( [
			                  'id'        => 'r1',
			                  'enabled'   => true,
			                  'path'      => '/docs/**',
			                  'userScope' => 'alice',
			                  'algos'     => [ 'sha1' ],
			                  'mode'      => 'auto',
		                  ] )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleUpdate' )
		                  ->with(
			                  'r1',
			                  $this->callback(
				                  static fn(
					                  array $definition,
				                  ): bool => $definition['mode'] === 'force'
					                  && $definition['path'] === '/docs/**'
					                  && $definition['algos'] === [ 'sha1' ],
			                  ),
			                  'cli',
		                  )
		;

		$tester = $this->tester( new ModifyRule( $this->ruleService, $this->validator ) );
		$tester->execute(
			[
				'id'     => 'r1',
				'--mode' => 'force',
			],
		);
	}

	public function testUnknownIdFailsWithTheOneConsistentMessage(): void
	{
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( null )
		;

		$commands = [
			[
				new ModifyRule( $this->ruleService, $this->validator ),
				[],
			],
			[
				new DeleteRule( $this->ruleService, $this->validator ),
				[ '--yes' => true ],
			],
			[
				new ApplyRule( $this->ruleService, $this->validator ),
				[],
			],
		];

		foreach ( $commands as [$command, $extra] )
		{
			$tester = $this->tester( $command );
			$exit   = $tester->execute( array_merge( [ 'id' => 'nosuch' ], $extra ) );

			$this->assertSame( Command::FAILURE, $exit );
			$this->assertStringContainsString( 'No rule with ID "nosuch"', $tester->getDisplay() );
		}
	}

	public function testApplyReportsAllFourBuckets(): void
	{
		$rule = [
			'id'      => 'r1',
			'enabled' => true,
		];
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( $rule )
		;
		$this->ruleService->expects( $this->once() )
		                  ->method( 'applyRule' )
		                  ->with( $rule, 'force', $this->anything(), 'cli' )
		                  ->willReturn( [
			                  'matched' => 9,
			                  'marked'  => 3,
			                  'skipped' => 2,
			                  'fresh'   => 4,
		                  ] )
		;

		$tester = $this->tester( new ApplyRule( $this->ruleService, $this->validator ) );
		$exit   = $tester->execute(
			[
				'id'     => 'r1',
				'--mode' => 'force',
			],
		);

		$this->assertSame( Command::SUCCESS, $exit );
		// The arithmetic presents itself: 9 = 3 + 4 + 2.
		$this->assertStringContainsString(
			'9 files matched — 3 queued, 4 already fresh, 2 claimed by other rules.',
			$tester->getDisplay(),
		);
	}

	/**
	 * A refusal reads in the server's language, as the command's other
	 * refusals do: the hint, not the English kept for a log.
	 */
	public function testApplyPrintsARefusalsHint(): void
	{
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( [ 'id' => 'r1' ] )
		;
		$this->ruleService->method( 'applyRule' )
		                  ->willThrowException(
			                  new HintedInvalidArgumentException( 'A disabled rule cannot be applied — enable it first.', '«translated»' ),
		                  )
		;

		$tester = $this->tester( new ApplyRule( $this->ruleService, $this->validator ) );

		$this->assertSame( Command::FAILURE, $tester->execute( [ 'id' => 'r1' ] ) );
		$this->assertStringContainsString( '«translated»', $tester->getDisplay() );
		$this->assertStringNotContainsString( 'enable it first', $tester->getDisplay() );
	}
}
