<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\IRootFolder;
use OCP\Server;

/**
 * Integration tests for hash computation against real files.
 *
 * Verifies that hash_file() produces correct results for all supported
 * algorithms and that HashCalculationService validates algo input.
 */
class HashCalculationServiceTest
	extends
	DatabaseTestCase
{

	private string                 $tempFile = '';

	private HashCalculationService $service;


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function setUp(): void
	{

		parent::setUp();

		$this->service = Server::get( HashCalculationService::class );

		// Create a temp file with known content.
		$this->tempFile = tempnam( sys_get_temp_dir(), 'fcias_test_' );
		file_put_contents( $this->tempFile, 'The quick brown fox jumps over the lazy dog.' );
	}


	protected function tearDown(): void
	{

		if ( $this->tempFile !== '' && file_exists( $this->tempFile ) )
		{
			unlink( $this->tempFile );
		}

		parent::tearDown();
	}


	// ─── HashCalculationService validation ───────────────────────────

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRecalcFileHashReturnsErrorForUnsupportedAlgo(): void
	{

		$rootFolder = Server::get( IRootFolder::class );
		$userFolder = $rootFolder->getUserFolder( 'admin' );

		$file = $userFolder->newFile( 'fcias_test_algo.dat', 'test content' );

		try
		{
			$result = $this->service->recalcFileHash( $file, 'blake2b' );

			$this->assertFalse( $result['success'] );
			$this->assertStringContainsString( 'Unsupported algorithm', $result['error'] ?? '' );
		}
		finally
		{
			$file->delete();
		}
	}


	public function testRecalcHashReturnsErrorForNonexistentFile(): void
	{

		// Use a file ID that almost certainly doesn't exist.
		$result = $this->service->recalcHash( - 99999999, 'sha1' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'File not found.', $result['error'] ?? '' );
	}






	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRecalcHashesMultiAlgoMatchesHashFile(): void
	{

		$rootFolder = Server::get( IRootFolder::class );
		$userFolder = $rootFolder->getUserFolder( 'admin' );

		$file = $userFolder->newFile( 'fcias_test_multi.dat', 'The quick brown fox jumps over the lazy dog.' );

		try
		{
			$result = $this->service->recalcHashes( $file, HashCalculationService::SUPPORTED_ALGOS, false );

			$this->assertFalse( $result['locked'] );

			foreach ( HashCalculationService::SUPPORTED_ALGOS as $algo )
			{
				$this->assertTrue( $result['results'][ $algo ]['success'], "recalcHashes($algo) should succeed." );
				$this->assertSame(
					hash_file( $algo, $this->tempFile ),
					$result['results'][ $algo ]['hash'],
					"recalcHashes($algo) should match hash_file($algo).",
				);
			}
		}
		finally
		{
			$file->delete();
		}
	}

}
