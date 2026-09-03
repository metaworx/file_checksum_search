<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\SudoConfirmation;
use OCA\FileChecksumSearch\Service\SudoTokens;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\Authentication\Token\IToken;
use OCP\ISession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SudoConfirmationTest
	extends
	TestCase
{

	private const NOW = 1_700_000_000;

	private ISession&MockObject       $session;

	private ITokenProvider&MockObject $tokens;

	private SudoTokens&MockObject     $grants;

	private SudoConfirmation          $confirmation;


	protected function setUp(): void
	{

		parent::setUp();

		$this->session = $this->createMock( ISession::class );
		$this->tokens  = $this->createMock( ITokenProvider::class );
		$this->grants  = $this->createMock( SudoTokens::class );

		$time = $this->createMock( ITimeFactory::class );
		$time->method( 'getTime' )
		     ->willReturn( self::NOW )
		;

		$this->confirmation = new SudoConfirmation( $this->session, $this->tokens, $this->grants, $time );
	}


	/**
	 * @param  array<string, mixed>  $values
	 */
	private function sessionHolding( array $values ): void
	{

		$this->session->method( 'get' )
		              ->willReturnCallback( static fn ( string $key ): mixed => $values[ $key ] ?? null );
		// No getId() stub, on purpose: an app-password request has no
		// session token, so the session id is nothing to look a token up by.
		// The check once did, and every granted token was refused for it.
	}


	public function testAPasswordConfirmedWithinTheWindowCounts(): void
	{

		$this->sessionHolding( [ 'last-password-confirm' => self::NOW - SudoConfirmation::WINDOW + 60 ] );

		$this->assertTrue( $this->confirmation->isConfirmed( 'alice' ) );
	}


	public function testAConfirmationOlderThanTheWindowDoesNot(): void
	{

		$this->sessionHolding( [ 'last-password-confirm' => self::NOW - SudoConfirmation::WINDOW - 1 ] );

		$this->assertFalse( $this->confirmation->isConfirmed( 'alice' ) );
	}


	public function testABrowserSessionWithNoConfirmationIsNot(): void
	{

		$this->sessionHolding( [] );
		$this->grants->expects( $this->never() )
		             ->method( 'isGranted' )
		;

		$this->assertFalse( $this->confirmation->isConfirmed( 'alice' ) );
	}


	/**
	 * The non-interactive half: the token behind the request carries a
	 * grant, and that stands in for the prompt.
	 */
	public function testAGrantedAppPasswordCountsWithoutAnyConfirmation(): void
	{

		$this->sessionHolding( [ 'app_password' => 'secret' ] );
		$token = $this->createMock( IToken::class );
		$token->method( 'getId' )
		      ->willReturn( 7 )
		;
		$this->tokens->method( 'getToken' )
		             ->with( 'secret' )
		             ->willReturn( $token )
		;
		$this->grants->method( 'isGranted' )
		             ->with( 'alice', 7 )
		             ->willReturn( true )
		;

		$this->assertTrue( $this->confirmation->isConfirmed( 'alice' ) );
	}


	public function testAnUngrantedAppPasswordIsNot(): void
	{

		$this->sessionHolding( [ 'app_password' => 'secret' ] );
		$token = $this->createMock( IToken::class );
		$token->method( 'getId' )
		      ->willReturn( 7 )
		;
		$this->tokens->method( 'getToken' )
		             ->willReturn( $token )
		;
		$this->grants->method( 'isGranted' )
		             ->willReturn( false )
		;

		$this->assertFalse( $this->confirmation->isConfirmed( 'alice' ) );
	}


	public function testATokenCoreCannotFindHoldsNoGrant(): void
	{

		$this->sessionHolding( [ 'app_password' => 'secret' ] );
		$this->tokens->method( 'getToken' )
		             ->willThrowException( new \RuntimeException( 'invalid token' ) )
		;
		$this->grants->expects( $this->never() )
		             ->method( 'isGranted' )
		;

		$this->assertFalse( $this->confirmation->isConfirmed( 'alice' ) );
	}

}
