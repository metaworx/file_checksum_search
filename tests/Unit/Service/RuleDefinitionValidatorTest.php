<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\FileChecksumSearch\Service\RuleDefinitionValidator;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCP\IGroupManager;
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The controller-level payload tests keep exercising this logic through the
 * REST door; these cover the contract points every surface shares.
 */
class RuleDefinitionValidatorTest
	extends
	TestCase
{

	private MockObject|IGroupManager $groupManager;

	private MockObject|IUserManager  $userManager;

	private RuleDefinitionValidator  $validator;


	protected function setUp(): void
	{

		parent::setUp();

		$this->groupManager = $this->createMock( IGroupManager::class );
		$this->userManager  = $this->createMock( IUserManager::class );
		$this->validator    = new RuleDefinitionValidator( $this->groupManager, $this->userManager, new AlgorithmCatalogue( $this->createMock( IAppConfig::class ) ) );
	}


	public function testANonAdminsRuleIsAlwaysTheirOwnAndNeverEnforced(): void
	{

		// Whatever the payload claims: scope, enforcement and pinning are
		// trust, and trust is the caller's property, not the payload's.
		$definition = $this->validator->definitionFrom(
			[
				'path'           => '/docs/**',
				'selector'       => '*',
				'admin_enforced' => true,
			],
			'alice',
			false,
		);

		$this->assertSame( 'home:alice', $definition['selector'] );
		$this->assertFalse( $definition['admin_enforced'] );
		$this->assertArrayNotHasKey( 'pinned', $definition );
	}


	public function testAnAdminScopeNamingAMissingGroupIsRejected(): void
	{

		$this->groupManager->method( 'groupExists' )
		                   ->willReturn( false )
		;

		// Otherwise the rule would sit in the list matching nothing, with
		// no indication why.
		$this->expectException( InvalidArgumentException::class );

		$this->validator->definitionFrom(
			[
				'path'     => '**',
				'selector' => 'group:nosuch',
			],
			'cli',
			true,
		);
	}


	public function testANonIncludeRuleStoresNothingAboutHowToCompute(): void
	{

		$definition = $this->validator->definitionFrom(
			[
				'path'  => '/metered/**',
				'type'  => 'exclude',
				'algos' => [ 'sha1' ],
				'mode'  => 'force',
			],
			'alice',
			false,
		);

		$this->assertArrayNotHasKey( 'algos', $definition );
		$this->assertArrayNotHasKey( 'mode', $definition );
	}


	public function testOmittedFieldsFallBackToTheExistingRule(): void
	{

		$this->userManager->method( 'userExists' )
		                  ->willReturn( true )
		;

		$definition = $this->validator->definitionFrom(
			[ 'mode' => 'force' ],
			'cli',
			true,
			[
				'path'      => '/docs/**',
				'userScope' => 'alice',
				'enabled'   => false,
				'algos'     => [ 'sha256' ],
				'mode'      => 'auto',
			],
		);

		$this->assertSame( '/docs/**', $definition['path'] );
		// The legacy stored form canonicalises on the way through.
		$this->assertSame( 'home:alice', $definition['selector'] );
		$this->assertFalse( $definition['enabled'] );
		$this->assertSame( [ 'sha256' ], $definition['algos'] );
		$this->assertSame( 'force', $definition['mode'] );
	}


	public function testAnIncludeRuleWithNoValidAlgorithmIsRejected(): void
	{

		$this->expectException( InvalidArgumentException::class );

		$this->validator->definitionFrom(
			[
				'path'  => '**',
				'algos' => [ 'rot13' ],
			],
			'alice',
			false,
		);
	}

}
