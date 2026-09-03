<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\AuthTokenRepository;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\DB\Exception;
use OCP\DB\IResult;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class AuthTokenRepositoryTest
	extends
	FciasUnitTestCase
{

	private AuthTokenRepository $repository;


	protected function setUp(): void
	{

		parent::setUp();

		$this->db = $this->createMock( IDBConnection::class );
		$this->setUpQueryBuilderMock();

		$this->repository = new AuthTokenRepository( $this->db, $this->createMock( LoggerInterface::class ) );
	}


	/**
	 * @param  list<array<string, mixed>>  $rows
	 */
	private function answering( array $rows ): void
	{

		$result = $this->createMock( IResult::class );
		$result->method( 'fetch' )
		       ->willReturnOnConsecutiveCalls( ...[ ...$rows, false ] )
		;
		$this->queryBuilder->method( 'executeQuery' )
		                   ->willReturn( $result )
		;
	}


	public function testRowsAreTypedAndTheScopeIsReadAsCoreReadsIt(): void
	{

		$this->answering( [
			[ 'id' => '7', 'uid' => 'alice', 'name' => 'backup', 'type' => '1', 'last_activity' => '1700000000', 'scope' => '{"filesystem":true}' ],
			[ 'id' => '8', 'uid' => 'alice', 'name' => 'no-files', 'type' => '1', 'last_activity' => '0', 'scope' => '{"filesystem":false}' ],
			[ 'id' => '9', 'uid' => 'alice', 'name' => 'Mozilla/5.0', 'type' => '0', 'last_activity' => '1', 'scope' => null ],
		] );

		$rows = $this->repository->listForUser( 'alice' );

		$this->assertSame( [ 7, 8, 9 ], array_column( $rows, 'id' ) );
		$this->assertSame( AuthTokenRepository::TYPE_APP_PASSWORD, $rows[0]['type'] );
		$this->assertTrue( $rows[0]['filesystem'] );
		$this->assertFalse( $rows[1]['filesystem'], 'a scope that says no' );
		$this->assertTrue( $rows[2]['filesystem'], 'no scope at all is unrestricted, as LockdownManager reads it' );
		$this->assertSame( AuthTokenRepository::TYPE_BROWSER, $rows[2]['type'] );
	}


	public function testByIdsIsKeyedByIdAndAsksNothingForNoIds(): void
	{

		$this->queryBuilder->expects( $this->never() )
		                   ->method( 'executeQuery' )
		;

		$this->assertSame( [], $this->repository->byIds( [] ) );
	}


	public function testByIdsKeysTheRows(): void
	{

		$this->answering( [
			[ 'id' => '42', 'uid' => 'bob', 'name' => 'ci', 'type' => '1', 'last_activity' => '5', 'scope' => '' ],
		] );

		$rows = $this->repository->byIds( [ 42, 43 ] );

		$this->assertSame( [ 42 ], array_keys( $rows ) );
		$this->assertSame( 'bob', $rows[42]['uid'] );
	}


	/**
	 * The one coupling to a core table is behind this class: a column that
	 * is gone makes the listing unavailable, not the app broken.
	 */
	public function testAnUnreadableTableAnswersWithNothing(): void
	{

		$this->queryBuilder->method( 'executeQuery' )
		                   ->willThrowException( new Exception( 'no such column' ) )
		;

		$this->assertSame( [], $this->repository->listForUser( 'alice' ) );
	}


	public function testTheScopeReadingMatchesCore(): void
	{

		$this->assertTrue( AuthTokenRepository::filesystemAllowed( '' ) );
		$this->assertTrue( AuthTokenRepository::filesystemAllowed( '{}' ) );
		$this->assertTrue( AuthTokenRepository::filesystemAllowed( '{"filesystem":true}' ) );
		$this->assertFalse( AuthTokenRepository::filesystemAllowed( '{"filesystem":false}' ) );
		$this->assertTrue( AuthTokenRepository::filesystemAllowed( 'not json' ), 'unparsable is unrestricted, as core would treat a missing key' );
	}

}
