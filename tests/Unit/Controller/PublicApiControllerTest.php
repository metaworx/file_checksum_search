<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\PublicApiController;
use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PublicApiControllerTest
	extends
	TestCase
{

// private properties
	private MockObject|ChecksumApi     $api;

	private MockObject|LoggerInterface $logger;

	private PublicApiController        $controller;


	protected function setUp(): void
	{

		parent::setUp();

		$this->api    = $this->createMock( ChecksumApi::class );
		$this->logger = $this->createMock( LoggerInterface::class );
		$request      = $this->createMock( IRequest::class );

		$this->controller = new PublicApiController(
			'file_checksum_search',
			$request,
			$this->api,
			$this->logger,
		);
	}


	// ─── lookup ─────────────────────────────────────────────────────

	public function testFindAllDuplicatesPassesAllParams(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findDuplicates' )
		          ->with( 'sha256', 3, 10, 20 )
		          ->willReturn( [
			          'duplicates'   => [],
			          'total_groups' => 0,
			          'pagination'   => [
				          'offset' => 20,
				          'limit'  => 10,
			          ],
		          ] )
		;

		$response = $this->controller->findAllDuplicates( 'sha256', 3, 10, 20 );

		$this->assertInstanceOf( DataResponse::class, $response );
	}


	public function testFindAllDuplicatesReturnsGroups(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findDuplicates' )
		          ->with( null, 2, 50, 0 )
		          ->willReturn( [
			          'duplicates'   => [],
			          'total_groups' => 0,
			          'pagination'   => [
				          'offset' => 0,
				          'limit'  => 50,
			          ],
		          ] )
		;

		$response = $this->controller->findAllDuplicates();

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertSame( 0, $data['total_groups'] );
	}


	public function testFindAllDuplicatesReturnsServerErrorOnException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findDuplicates' )
		          ->willThrowException( new \RuntimeException( 'DB error' ) )
		;

		$response = $this->controller->findAllDuplicates();

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	public function testFindDuplicatesReturnsGroups(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findSameHash' )
		          ->with( 42 )
		          ->willReturn( [
			          'duplicates' => [
				          [
					          'algo'       => 'sha1',
					          'hash_value' => 'abc',
					          'files'      => [
						          [
							          'fileid' => 108,
							          'path'   => 'Backup',
							          'name'   => 'copy.jpg',
						          ],
					          ],
				          ],
			          ],
		          ] )
		;

		$response = $this->controller->findDuplicates( 42 );

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertCount( 1, $data['duplicates'] );
	}


	// ─── getHashes ──────────────────────────────────────────────────

	public function testFindDuplicatesReturnsServerErrorOnException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findSameHash' )
		          ->willThrowException( new \RuntimeException( 'DB error' ) )
		;

		$response = $this->controller->findDuplicates( 42 );

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	public function testGetHashesReturnsFileHashes(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'getHashesByFileId' )
		          ->with( 42 )
		          ->willReturn( [
			          'fileid' => 42,
			          'hashes' => [
				          [
					          'algo' => 'sha1',
					          'hash' => 'abc',
				          ],
			          ],
		          ] )
		;

		$response = $this->controller->getHashes( 42 );

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertSame( 42, $data['fileid'] );
		$this->assertCount( 1, $data['hashes'] );
	}


	// ─── findDuplicates (per-file) ──────────────────────────────────

	public function testGetHashesReturnsServerErrorOnException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'getHashesByFileId' )
		          ->willThrowException( new \RuntimeException( 'DB error' ) )
		;

		$response = $this->controller->getHashes( 42 );

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	public function testGetStatusReturnsHealthInfo(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'getStatus' )
		          ->willReturn( [
			          'version'     => '1.0.0',
			          'dbVersion'   => '10.11.6-MariaDB',
			          'rowCount'    => 5000,
			          'pendingRows' => 3,
		          ] )
		;

		$response = $this->controller->getStatus();

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertSame( '1.0.0', $data['version'] );
		$this->assertSame( 5000, $data['rowCount'] );
		$this->assertSame( 3, $data['pendingRows'] );
	}


	// ─── recalcHash ─────────────────────────────────────────────────

	public function testGetStatusReturnsServerErrorOnException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'getStatus' )
		          ->willThrowException( new \RuntimeException( 'DB error' ) )
		;

		$response = $this->controller->getStatus();

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	public function testLookupPassesAlgoParameter(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findByHash' )
		          ->with( 'abc123', 'md5', 100 )
		          ->willReturn( [ 'results' => [] ] )
		;

		$response = $this->controller->lookup( 'abc123', 'md5' );

		$this->assertInstanceOf( DataResponse::class, $response );
	}


	public function testLookupReturnsBadRequestOnInvalidArgument(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findByHash' )
		          ->willThrowException( new \InvalidArgumentException( 'Hash parameter is required.' ) )
		;

		$response = $this->controller->lookup( '' );

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
		$this->assertArrayHasKey( 'error', $response->getData() );
	}


	// ─── findAllDuplicates ──────────────────────────────────────────

	public function testLookupReturnsResults(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findByHash' )
		          ->with( 'abc123', null, 100 )
		          ->willReturn( [
			          'results' => [
				          [
					          'fileid' => 42,
					          'algo'   => 'sha1',
					          'hash'   => 'abc123',
					          'path'   => 'Docs',
					          'name'   => 'report.pdf',
				          ],
			          ],
		          ] )
		;

		$response = $this->controller->lookup( 'abc123' );

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertArrayHasKey( 'results', $data );
		$this->assertCount( 1, $data['results'] );
	}


	public function testLookupReturnsServerErrorOnRuntimeException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'findByHash' )
		          ->willThrowException( new \RuntimeException( 'DB failure' ) )
		;

		$response = $this->controller->lookup( 'abc123' );

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	public function testRecalcHashReturnsBadRequestOnFailure(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'recalcHash' )
		          ->with( 99999, null )
		          ->willReturn( [
			          'success' => false,
			          'error'   => 'File not found.',
		          ] )
		;

		$response = $this->controller->recalcHash( 99999 );

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
	}


	// ─── getStatus ──────────────────────────────────────────────────

	public function testRecalcHashReturnsServerErrorOnException(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'recalcHash' )
		          ->willThrowException( new \RuntimeException( 'IO error' ) )
		;

		$response = $this->controller->recalcHash( 42 );

		$this->assertSame( Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus() );
	}


	public function testRecalcHashReturnsSuccess(): void
	{

		$this->api->expects( $this->once() )
		          ->method( 'recalcHash' )
		          ->with( 42, null )
		          ->willReturn( [
			          'success' => true,
			          'algo'    => 'sha1',
			          'hash'    => 'abc',
			          'fileid'  => 42,
		          ] )
		;

		$response = $this->controller->recalcHash( 42 );

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertTrue( $data['success'] );
	}

}
