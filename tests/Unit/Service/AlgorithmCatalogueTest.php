<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AlgorithmCatalogueTest
	extends
	TestCase
{

	private IAppConfig&MockObject $appConfig;


	protected function setUp(): void
	{

		parent::setUp();

		$this->appConfig = $this->createMock( IAppConfig::class );
	}


	private function withAllowlist( array $stored, string $default = '' ): AlgorithmCatalogue
	{

		$this->appConfig->method( 'getValueArray' )
		                ->with( Application::APP_ID, AlgorithmCatalogue::CONFIG_KEY, [] )
		                ->willReturn( $stored )
		;
		$this->appConfig->method( 'getValueString' )
		                ->with( Application::APP_ID, AlgorithmCatalogue::DEFAULT_KEY, '' )
		                ->willReturn( $default )
		;

		return new AlgorithmCatalogue( $this->appConfig );
	}


	public function testAnUntouchedInstanceComputesTheShippedDefault(): void
	{

		$catalogue = $this->withAllowlist( [] );

		$this->assertSame( AlgorithmCatalogue::DEFAULT_ALLOWLIST, $catalogue->algorithms() );
		$this->assertSame( 'sha1', $catalogue->default() );
	}


	public function testTheShippedDefaultIncludesTheTwo384BitMembers(): void
	{

		$this->assertContains( 'sha384', AlgorithmCatalogue::DEFAULT_ALLOWLIST );
		$this->assertContains( 'sha3-384', AlgorithmCatalogue::DEFAULT_ALLOWLIST );
	}


	/**
	 * Names become metadata keys and are parsed back by stripping a prefix,
	 * so a name PHP accepts but a key cannot carry is refused wherever it
	 * comes from.
	 */
	public function testNamesThatCannotBeKeysAreNeverAvailable(): void
	{

		$catalogue = $this->withAllowlist( [] );

		foreach ( $catalogue->available() as $name )
		{
			$this->assertMatchesRegularExpression( AlgorithmCatalogue::NAME_PATTERN, $name );
		}

		// PHP offers both of these; neither may appear.
		$this->assertNotContains( 'sha512/256', $catalogue->available() );
		$this->assertNotContains( 'tiger192,3', $catalogue->available() );
		$this->assertContains( 'sha384', $catalogue->available() );
	}


	public function testTheAllowlistIsHonouredInItsOwnOrder(): void
	{

		$catalogue = $this->withAllowlist( [ 'sha256', 'sha1' ] );

		$this->assertSame( [ 'sha256', 'sha1' ], $catalogue->algorithms() );
		$this->assertSame( 'sha256', $catalogue->default(), 'the first in force is the default' );
		$this->assertTrue( $catalogue->isValid( 'sha1' ) );
		$this->assertFalse( $catalogue->isValid( 'md5' ), 'allowed by PHP, not by this administrator' );
		$this->assertFalse( $catalogue->isValid( 42 ) );
	}


	public function testUnusableAllowlistEntriesAreDroppedNotFatal(): void
	{

		$catalogue = $this->withAllowlist( [ 'SHA256', ' md5 ', 'sha512/256', 'nonsense', 7, 'md5' ] );

		$this->assertSame( [ 'sha256', 'md5' ], $catalogue->algorithms(), 'lower-cased, trimmed, filtered, de-duplicated' );
	}


	public function testAnAllowlistThatKeepsNothingFallsBackToTheDefault(): void
	{

		$catalogue = $this->withAllowlist( [ 'nonsense', 'sha512/256' ] );

		$this->assertSame( AlgorithmCatalogue::DEFAULT_ALLOWLIST, $catalogue->algorithms() );
	}


	public function testSettingTheAllowlistStoresOnlyWhatIsUsable(): void
	{

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueArray' )
		                ->with( Application::APP_ID, AlgorithmCatalogue::CONFIG_KEY, [ 'sha384', 'sha1' ] )
		;

		$catalogue = new AlgorithmCatalogue( $this->appConfig );

		$this->assertSame( [ 'sha384', 'sha1' ], $catalogue->setAllowlist( [ 'sha384', 'bogus', 'sha1' ] ) );
	}


	/**
	 * A list that keeps nothing is refused rather than stored: storing it
	 * would silently fall back to the default, which is not what someone
	 * who typed a list asked for.
	 */
	public function testSettingAnUnusableAllowlistStoresNothing(): void
	{

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueArray' )
		;

		$catalogue = new AlgorithmCatalogue( $this->appConfig );

		$this->assertSame( [], $catalogue->setAllowlist( [ 'bogus', 'sha512/256' ] ) );
	}


	public function testADesignatedDefaultIsHonoured(): void
	{

		$catalogue = $this->withAllowlist( [ 'sha1', 'sha256' ], 'sha256' );

		$this->assertSame( 'sha256', $catalogue->default() );
	}


	/**
	 * A designation that is not in force — reached the config past
	 * setAllowlist(), or the PHP build lost the algorithm — is not an error
	 * and not a default: the first allowed applies.
	 */
	public function testADesignationNotInForceFallsBackToTheFirstAllowed(): void
	{

		$catalogue = $this->withAllowlist( [ 'sha1', 'sha256' ], 'md5' );

		$this->assertSame( 'sha1', $catalogue->default() );
	}


	public function testDesignatingTheDefaultStoresItAndAppliesAtOnce(): void
	{

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with( Application::APP_ID, AlgorithmCatalogue::DEFAULT_KEY, 'sha256' )
		;

		$catalogue = $this->withAllowlist( [ 'sha1', 'sha256' ] );

		$this->assertTrue( $catalogue->setDefault( ' SHA256 ' ) );
		$this->assertSame( 'sha256', $catalogue->default() );
	}


	public function testDesignatingAnAlgorithmNotInForceIsRefused(): void
	{

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueString' )
		;

		$catalogue = $this->withAllowlist( [ 'sha1' ] );

		$this->assertFalse( $catalogue->setDefault( 'sha256' ) );
		$this->assertSame( 'sha1', $catalogue->default() );
	}


	public function testTheEmptyNameClearsTheDesignation(): void
	{

		$this->appConfig->expects( $this->once() )
		                ->method( 'deleteKey' )
		                ->with( Application::APP_ID, AlgorithmCatalogue::DEFAULT_KEY )
		;
		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueString' )
		;

		$catalogue = $this->withAllowlist( [ 'sha1', 'sha256' ] );

		$this->assertTrue( $catalogue->setDefault( '' ) );
	}


	/**
	 * Dropping the default from the allowlist clears the designation rather
	 * than leaving it dormant, so re-allowing the algorithm later does not
	 * silently make it the default again.
	 */
	public function testAnAllowlistThatDropsTheDefaultClearsIt(): void
	{

		$this->appConfig->expects( $this->once() )
		                ->method( 'deleteKey' )
		                ->with( Application::APP_ID, AlgorithmCatalogue::DEFAULT_KEY )
		;

		$catalogue = $this->withAllowlist( [ 'sha1', 'sha256' ], 'sha256' );

		$this->assertSame( [ 'sha1' ], $catalogue->setAllowlist( [ 'sha1' ] ) );
		$this->assertSame( 'sha1', $catalogue->default() );
	}


	public function testAnAllowlistThatKeepsTheDefaultLeavesItAlone(): void
	{

		$this->appConfig->expects( $this->never() )
		                ->method( 'deleteKey' )
		;

		$catalogue = $this->withAllowlist( [ 'sha1', 'sha256' ], 'sha256' );

		$catalogue->setAllowlist( [ 'md5', 'sha256' ] );

		$this->assertSame( 'sha256', $catalogue->default() );
	}

}
