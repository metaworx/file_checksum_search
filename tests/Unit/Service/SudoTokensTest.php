<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCA\FileChecksumSearch\Service\AuthTokenRepository;
use OCA\FileChecksumSearch\Service\SudoTokens;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SudoTokensTest
	extends
	TestCase
{

	private IUserConfig&MockObject         $userConfig;

	private AuthTokenRepository&MockObject $tokens;

	private SudoTokens                     $sudoTokens;

	/** What the store holds per user, as the mock answers and records it. */
	private array $stored = [];


	protected function setUp(): void
	{

		parent::setUp();

		$this->userConfig = $this->createMock( IUserConfig::class );
		$this->userConfig->method( 'getValueArray' )
		                 ->willReturnCallback( fn ( string $uid ): array => $this->stored[ $uid ] ?? [] );
		$this->userConfig->method( 'setValueArray' )
		                 ->willReturnCallback( function ( string $uid, string $app, string $key, array $value ): bool {
			                 $this->stored[ $uid ] = $value;

			                 return true;
		                 } );
		$this->userConfig->method( 'deleteUserConfig' )
		                 ->willReturnCallback( function ( string $uid ): void {
			                 unset( $this->stored[ $uid ] );
		                 } );

		$this->tokens = $this->createMock( AuthTokenRepository::class );

		$time = $this->createMock( ITimeFactory::class );
		$time->method( 'getTime' )
		     ->willReturn( 1_700_000_000 )
		;

		$this->sudoTokens = new SudoTokens( $this->userConfig, $this->tokens, $time );
	}


	private function token(
		int    $id,
		int    $type = AuthTokenRepository::TYPE_APP_PASSWORD,
		bool   $filesystem = true,
		string $uid = 'alice',
	): array {

		return [
			'id'            => $id,
			'uid'           => $uid,
			'name'          => "token $id",
			'type'          => $type,
			'last_activity' => 1_699_999_000,
			'filesystem'    => $filesystem,
		];
	}


	public function testGrantingStoresWhoAndWhenUnderTheTokenId(): void
	{

		$this->tokens->method( 'listForUser' )
		             ->willReturn( [ $this->token( 7 ) ] )
		;

		$this->sudoTokens->grant( 'alice', 7, 'root' );

		$this->assertSame(
			[ 7 => [ 'granted_by' => 'root', 'granted_at' => 1_700_000_000 ] ],
			$this->stored['alice'],
		);
		$this->assertTrue( $this->sudoTokens->isGranted( 'alice', 7 ) );
		$this->assertFalse( $this->sudoTokens->isGranted( 'alice', 8 ) );
	}


	/**
	 * A browser session is made and discarded by a login: granting one
	 * would grant the next stranger who logs in from that browser.
	 */
	public function testABrowserSessionCannotBeGranted(): void
	{

		$this->tokens->method( 'listForUser' )
		             ->willReturn( [ $this->token( 7, AuthTokenRepository::TYPE_BROWSER ) ] )
		;

		$this->expectException( InvalidArgumentException::class );

		$this->sudoTokens->grant( 'alice', 7, 'root' );
	}


	/**
	 * Core keeps such a token out of every mount; the grant must not hand it
	 * the SQL-only routes that never touch one.
	 */
	public function testATokenKeptOutOfTheFilesystemCannotBeGranted(): void
	{

		$this->tokens->method( 'listForUser' )
		             ->willReturn( [ $this->token( 7, filesystem: false ) ] )
		;

		$this->expectException( InvalidArgumentException::class );

		$this->sudoTokens->grant( 'alice', 7, 'root' );
	}


	public function testATokenOfAnotherAccountCannotBeGranted(): void
	{

		$this->tokens->method( 'listForUser' )
		             ->with( 'alice' )
		             ->willReturn( [] )
		;

		$this->expectException( InvalidArgumentException::class );

		$this->sudoTokens->grant( 'alice', 7, 'root' );
	}


	public function testRevokingTheLastGrantRemovesTheKeyEntirely(): void
	{

		$this->stored['alice'] = [ 7 => [ 'granted_by' => 'root', 'granted_at' => 1 ] ];

		$this->sudoTokens->revoke( 'alice', 7 );

		$this->assertArrayNotHasKey( 'alice', $this->stored, 'nothing left means no key, not an empty one' );
		// And revoking what is not there is not an error.
		$this->sudoTokens->revoke( 'alice', 7 );
	}


	/**
	 * The personal list: app passwords only, with their grant, and a grant
	 * whose token is gone is dropped and the store tidied.
	 */
	public function testTheListingShowsAppPasswordsWithTheirGrantsAndTidiesLeftovers(): void
	{

		$this->stored['alice'] = [
			7  => [ 'granted_by' => 'root', 'granted_at' => 1 ],
			99 => [ 'granted_by' => 'root', 'granted_at' => 2 ],
		];
		$this->tokens->method( 'listForUser' )
		             ->willReturn( [
			             $this->token( 5, AuthTokenRepository::TYPE_BROWSER ),
			             $this->token( 7 ),
			             $this->token( 8 ),
		             ] )
		;

		$rows = $this->sudoTokens->listForUser( 'alice' );

		$this->assertSame( [ 7, 8 ], array_column( $rows, 'id' ), 'the browser session is not offered' );
		$this->assertTrue( $rows[0]['granted'] );
		$this->assertSame( 'root', $rows[0]['granted_by'] );
		$this->assertFalse( $rows[1]['granted'] );
		$this->assertSame( [ 7 ], array_keys( $this->stored['alice'] ), 'the grant on the deleted token 99 is gone' );
	}


	/**
	 * When the table cannot be read, every token looks gone; tidying then
	 * would wipe every grant, so nothing is tidied.
	 */
	public function testAnUnreadableTableDoesNotWipeTheGrants(): void
	{

		$this->stored['alice'] = [ 7 => [ 'granted_by' => 'root', 'granted_at' => 1 ] ];
		$this->tokens->method( 'listForUser' )
		             ->willReturn( [] )
		;

		$this->assertSame( [], $this->sudoTokens->listForUser( 'alice' ) );
		$this->assertArrayHasKey( 7, $this->stored['alice'] );
	}


	public function testTheAdministratorSeesEveryGrantAgainstTheLiveTable(): void
	{

		$this->userConfig->method( 'getValuesByUsers' )
		                 ->with( Application::APP_ID, ConfigLexicon::USER_SUDO_TOKENS )
		                 ->willReturn( [
			                 'alice' => [ 7 => [ 'granted_by' => 'root', 'granted_at' => 1 ] ],
			                 'bob'   => [ 42 => [ 'granted_by' => 'root', 'granted_at' => 2 ] ],
		                 ] )
		;
		$this->tokens->method( 'byIds' )
		             ->with( [ 7, 42 ] )
		             ->willReturn( [ 7 => $this->token( 7 ) ] )
		;

		$rows = $this->sudoTokens->allGrants();

		$this->assertCount( 2, $rows );
		$this->assertSame( [ 'alice', true, 'token 7' ], [ $rows[0]['uid'], $rows[0]['exists'], $rows[0]['name'] ] );
		$this->assertSame( [ 'bob', false, '' ], [ $rows[1]['uid'], $rows[1]['exists'], $rows[1]['name'] ], 'a leftover is shown, not hidden' );
	}

}
