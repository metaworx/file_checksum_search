<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\LookupController;
use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
class LookupControllerTest
	extends
	TestCase
{

// private properties
	private MockObject|ChecksumApi     $api;

	private MockObject|IRequest        $request;

	private MockObject|IUserSession    $userSession;

	private MockObject|IGroupManager   $groupManager;

	private MockObject|LoggerInterface $logger;

	private LookupController           $controller;


	protected function setUp(): void
	{

		parent::setUp();

		$this->api          = $this->createMock( ChecksumApi::class );
		$this->request      = $this->createMock( IRequest::class );
		$this->userSession  = $this->createMock( IUserSession::class );
		$this->groupManager = $this->createMock( IGroupManager::class );
		$this->logger       = $this->createMock( LoggerInterface::class );

		// Default to an authenticated admin (unrestricted scope: null) so
		// existing expectations that predate the ownership scoping fix
		// keep passing unchanged; tests exercising non-admin scoping
		// override this per-test.
		$adminUser = $this->createMock( IUser::class );
		$adminUser->method( 'getUID' )
		          ->willReturn( 'admin' )
		;
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $adminUser )
		;
		$this->groupManager->method( 'isAdmin' )
		                   ->with( 'admin' )
		                   ->willReturn( true )
		;

		$this->controller = new LookupController(
			'file_checksum_search',
			$this->request,
			$this->api,
			$this->userSession,
			$this->groupManager,
			$this->logger,
		);
	}


	// ─── byHash ──────────────────────────────────────────────────────

	public function testByHashWithEmptyHashDelegatesToApi(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findByHash' )
		          ->with( '', null, 100, null )
		          ->willReturn( [ 'results' => [] ] )
		;

		$response = $this->controller->byHash( '' );

		$this->assertInstanceOf( DataResponse::class, $response );
		$this->assertArrayHasKey( 'results', $response->getData() );
	}


	public function testByHashWithValidHashReturnsResults(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findByHash' )
		          ->with( 'da39a3ee5e6b4b0d3255bfef95601890afd80709', null, 100, null )
		          ->willReturn( [
			          'results' => [
				          [
					          'fileid' => 42,
					          'algo'   => 'sha1',
					          'hash'   => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
					          'path'   => 'Documents',
					          'name'   => 'report.pdf',
				          ],
			          ],
		          ] )
		;

		$response = $this->controller->byHash( 'da39a3ee5e6b4b0d3255bfef95601890afd80709' );

		$this->assertInstanceOf( DataResponse::class, $response );
		$this->assertSame( Http::STATUS_OK, $response->getStatus() );

		$data = $response->getData();
		$this->assertArrayHasKey( 'results', $data );
		$this->assertCount( 1, $data['results'] );
		$this->assertSame( 42, $data['results'][0]['fileid'] );
	}


	public function testByHashWithAlgoPassesFilter(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findByHash' )
		          ->with( 'abc123', 'sha256', 100, null )
		          ->willReturn( [ 'results' => [] ] )
		;

		$response = $this->controller->byHash( 'abc123', 'sha256' );

		$this->assertSame( Http::STATUS_OK, $response->getStatus() );
	}


	public function testByHashReturnsServerErrorOnException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findByHash' )
		          ->willThrowException( new \RuntimeException( 'DB error' ) )
		;

		$response = $this->controller->byHash( 'abc123' );

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	// ─── getHashesByFileId ───────────────────────────────────────────

	public function testGetHashesByFileIdReturnsHashArray(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'getHashesByFileId' )
		          ->with( 42, null )
		          ->willReturn( [
			          'hashes' => [
				          [
					          'algo'       => 'sha1',
					          'hash'       => 'abc123',
					          'updated_at' => '2026-08-05 12:00:00',
				          ],
				          [
					          'algo'       => 'sha256',
					          'hash'       => 'def456',
					          'updated_at' => '2026-08-05 12:00:00',
				          ],
			          ],
			          'fileid' => 42,
		          ] )
		;

		$response = $this->controller->getHashesByFileId( 42 );

		$this->assertInstanceOf( DataResponse::class, $response );
		$this->assertSame( Http::STATUS_OK, $response->getStatus() );

		$data = $response->getData();
		$this->assertArrayHasKey( 'hashes', $data );
		$this->assertCount( 2, $data['hashes'] );
		$this->assertSame( 'sha1', $data['hashes'][0]['algo'] );
		$this->assertSame( 'abc123', $data['hashes'][0]['hash'] );
	}


	public function testGetHashesByFileIdReturnsEmptyForUnknownFile(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'getHashesByFileId' )
		          ->with( 99999, null )
		          ->willReturn( [
			          'hashes' => [],
			          'fileid' => 99999,
		          ] )
		;

		$response = $this->controller->getHashesByFileId( 99999 );

		$data = $response->getData();
		$this->assertEmpty( $data['hashes'] );
	}


	public function testGetHashesByFileIdReturnsServerErrorOnException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'getHashesByFileId' )
		          ->willThrowException( new \RuntimeException( 'DB error' ) )
		;

		$response = $this->controller->getHashesByFileId( 42 );

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	public function testGetHashesByFileIdReturnsNotFoundWhenFileInaccessibleToCaller(): void
	{

		// Regression test for FCIAS Review §6, Finding 1.
		$this->api->method( 'getHashesByFileId' )
		          ->willThrowException( new NotFoundException( 'Invalid file ID: 42' ) )
		;

		$response = $this->controller->getHashesByFileId( 42 );

		$this->assertSame( Http::STATUS_NOT_FOUND, $response->getStatus() );
	}


	public function testGetHashesByFileIdReturnsUnauthorizedWhenNoUser(): void
	{

		// Regression test for FCIAS Review §6, Finding 1.
		$this->userSession = $this->createMock( IUserSession::class );
		$this->userSession->method( 'getUser' )
		                  ->willReturn( null )
		;
		$this->controller = new LookupController(
			'file_checksum_search',
			$this->request,
			$this->api,
			$this->userSession,
			$this->groupManager,
			$this->logger,
		);

		$this->api->expects( $this->never() )
		          ->method( 'getHashesByFileId' )
		;

		$response = $this->controller->getHashesByFileId( 42 );

		$this->assertSame( Http::STATUS_UNAUTHORIZED, $response->getStatus() );
	}


	// ─── recalcHash ──────────────────────────────────────────────────

	public function testRecalcHashDelegatesToApi(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'recalcHash' )
		          ->with( 42, 'sha1', null )
		          ->willReturn( [
			          'success' => true,
			          'algo'    => 'sha1',
			          'hash'    => 'abc',
			          'fileid'  => 42,
		          ] )
		;

		$response = $this->controller->recalcHash( 42, 'sha1' );

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertTrue( $data['success'] );
	}


	public function testRecalcHashReturnsBadRequestOnFailure(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'recalcHash' )
		          ->with( 99999, 'sha1', null )
		          ->willReturn( [
			          'success' => false,
			          'error'   => 'File not found.',
		          ] )
		;

		$response = $this->controller->recalcHash( 99999, 'sha1' );

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
	}


	public function testRecalcHashReturnsServerErrorOnException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'recalcHash' )
		          ->willThrowException( new \RuntimeException( 'IO error' ) )
		;

		$response = $this->controller->recalcHash( 42 );

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	public function testRecalcHashScopesNonAdminCallerToOwnFiles(): void
	{

		// Regression test for FCIAS Review §6, Finding 1.
		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( 'alice' )
		;
		$this->userSession = $this->createMock( IUserSession::class );
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;
		$this->groupManager = $this->createMock( IGroupManager::class );
		$this->groupManager->method( 'isAdmin' )
		                   ->with( 'alice' )
		                   ->willReturn( false )
		;
		$this->controller = new LookupController(
			'file_checksum_search',
			$this->request,
			$this->api,
			$this->userSession,
			$this->groupManager,
			$this->logger,
		);

		$this->api->expects( $this->once() )
		          ->method( 'recalcHash' )
		          ->with( 42, 'sha1', 'alice' )
		          ->willReturn( [ 'success' => true ] )
		;

		$this->controller->recalcHash( 42 );
	}

}
