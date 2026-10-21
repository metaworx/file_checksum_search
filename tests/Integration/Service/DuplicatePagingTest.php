<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Server;
use Throwable;

/**
 * The duplicate listing's pages: groups of one size in one order on every
 * query, and a walk from `next_offset` to `next_offset` that shows each
 * group once.
 */
class DuplicatePagingTest
    extends
    DatabaseTestCase
{

//  private properties

	private ChecksumApi            $api;

	private HashCalculationService $hashCalc;

	/** @var list<File> */
	private array $cleanupFiles = [];

	/** @var list<int> */
	private array $cleanupFileIds = [];


//  getters / setters / is* / has*

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->api      = Server::get( ChecksumApi::class );
		$this->hashCalc = Server::get( HashCalculationService::class );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		foreach ( $this->cleanupFiles as $file )
		{
			try
			{
				$file->delete();
			}
			catch ( Throwable )
			{
			}
		}

		foreach ( $this->cleanupFileIds as $fileId )
		{
			foreach ( [ 'files_metadata_index', 'files_metadata' ] as $table )
			{
				try
				{
					$this->getRawConnection()
					     ->executeStatement( "DELETE FROM `*PREFIX*$table` WHERE `file_id` = ?", [ $fileId ] )
					;
				}
				catch ( Throwable )
				{
				}
			}
		}

		parent::tearDown();
	}

	/**
	 * Pairs, which most groups are, come in the order of their hashes: an
	 * offset into an order the database picks afresh on each query shows
	 * some groups twice and others never.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testGroupsOfOneSizeComeInTheOrderOfTheirHashes(): void
	{
		$hashes = $this->pairs( 3 );

		$listed = array_column( Server::get( MetadataService::class )->queryDuplicates( 'sha1', 2, 500, 0 ), 'meta_value_string' );
		$ours   = array_values( array_intersect( $listed, $hashes ) );

		$sorted = $hashes;
		sort( $sorted );

		$this->assertSame( $sorted, $ours );
	}

	/**
	 * A page at a time, one group to a page, each page starting at the last
	 * one's `next_offset`: every group once, and the walk ends.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAWalkByNextOffsetShowsEachGroupOnce(): void
	{
		$hashes = $this->pairs( 3 );
		$seen   = [];
		$offset = 0;

		for ( $page = 0; $offset !== null && $page < 1000; $page ++ )
		{
			$answer = $this->api->findDuplicatesFor( [ 'admin' ], 'sha1', 2, 1, $offset );

			foreach ( $answer['duplicates'] as $group )
			{
				$seen[] = $group['hash_value'];
			}

			$offset = $answer['pagination']['next_offset'];
		}

		$this->assertNull( $offset, 'The walk ends.' );
		$this->assertSame( array_unique( $seen ), $seen, 'No group twice.' );

		foreach ( $hashes as $hash )
		{
			$this->assertContains( $hash, $seen );
		}
	}

	/**
	 * $count pairs of files hashed in sha1, each pair its own content.
	 *
	 * @return list<string>  Their hashes.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function pairs( int $count ): array
	{
		$hashes = [];

		for ( $i = 0; $i < $count; $i ++ )
		{
			$content = 'fcias paging ' . bin2hex( random_bytes( 8 ) );

			foreach ( [ 'a', 'b' ] as $copy )
			{
				$file = Server::get( IRootFolder::class )
				              ->getUserFolder( 'admin' )
				              ->newFile( "fcias_paging_{$i}_{$copy}_" . bin2hex( random_bytes( 4 ) ) . '.dat', $content )
				;

				$this->cleanupFiles[]   = $file;
				$this->cleanupFileIds[] = $file->getId();

				$this->assertTrue( $this->hashCalc->recalcFileHash( $file, 'sha1' )['success'] );
			}

			$hashes[] = sha1( $content );
		}

		return $hashes;
	}
}
