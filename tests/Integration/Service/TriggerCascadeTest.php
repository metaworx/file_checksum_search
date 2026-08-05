<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Server;

/**
 * Integration tests for MariaDB trigger cascade behaviour.
 *
 * Verifies that INSERT/UPDATE/DELETE on oc_filecache correctly
 * propagates to the file_checksum_search_hashes shadow table via
 * the stored procedure and triggers.
 *
 * Uses large positive file IDs to avoid collision with real data.
 */
class TriggerCascadeTest
	extends
	DatabaseTestCase
{

	private const TEST_FILE_ID = 99999001;

	private const TEST_FILE_ID_2 = 99999002;

	private string $hashTable;

	private string $fcTable;


	protected function setUp(): void
	{

		parent::setUp();

		$this->hashTable = $this->getHashTableName();
		$this->fcTable   = $this->getFilecacheTableName();

		// Ensure the hashes table + SP + triggers are deployed.
		/** @var LifecycleHandler $lifecycle */
		$lifecycle = Server::get( LifecycleHandler::class );
		$lifecycle->createTables();
		$lifecycle->deployTriggers();

		// Clean any leftovers from a previous aborted run.
		$this->cleanupTestRows();
	}


	protected function tearDown(): void
	{

		$this->cleanupTestRows();

		parent::tearDown();
	}


	private function cleanupTestRows(): void
	{

		try
		{
			$this->getRawConnection()
			     ->executeStatement(
				     "DELETE FROM `{$this->fcTable}` WHERE fileid IN (?, ?)",
				     [
					     self::TEST_FILE_ID,
					     self::TEST_FILE_ID_2,
				     ],
			     )
			;
		}
		catch ( \Throwable )
		{
		}

		try
		{
			$this->getRawConnection()
			     ->executeStatement(
				     "DELETE FROM `{$this->hashTable}` WHERE fileid IN (?, ?)",
				     [
					     self::TEST_FILE_ID,
					     self::TEST_FILE_ID_2,
				     ],
			     )
			;
		}
		catch ( \Throwable )
		{
		}
	}


	// ─── INSERT trigger ──────────────────────────────────────────────

	public function testInsertTriggerCreatesHashRow(): void
	{

		$checksum = 'sha1:abc123def456 md5:aaa111bbb222';

		$this->insertFilecacheRow( self::TEST_FILE_ID, $checksum );

		$hashes = $this->fetchHashes( self::TEST_FILE_ID );

		$this->assertCount( 2, $hashes, 'Two algo:hash pairs should produce two rows.' );

		$algos = array_column( $hashes, 'algo' );
		$this->assertContains( 'sha1', $algos );
		$this->assertContains( 'md5', $algos );
	}


	public function testInsertTriggerSkipsEmptyChecksum(): void
	{

		$this->insertFilecacheRow( self::TEST_FILE_ID, '' );

		$hashes = $this->fetchHashes( self::TEST_FILE_ID );

		$this->assertEmpty( $hashes, 'Empty checksum should not produce hash rows.' );
	}


	// ─── UPDATE trigger ──────────────────────────────────────────────

	public function testUpdateTriggerRefreshesHash(): void
	{

		// Insert with initial checksum
		$this->insertFilecacheRow( self::TEST_FILE_ID, 'sha1:oldhash111' );

		$oldHashes = $this->fetchHashes( self::TEST_FILE_ID );
		$this->assertCount( 1, $oldHashes );
		$this->assertSame( 'oldhash111', $oldHashes[0]['hash_value'] );

		// Update checksum
		$this->getRawConnection()
		     ->executeStatement(
			     "UPDATE `{$this->fcTable}` SET checksum = ? WHERE fileid = ?",
			     [
				     'sha1:newhash222',
				     self::TEST_FILE_ID,
			     ],
		     )
		;

		$newHashes = $this->fetchHashes( self::TEST_FILE_ID );
		$this->assertCount( 1, $newHashes );
		$this->assertSame( 'newhash222', $newHashes[0]['hash_value'] );
	}


	public function testUpdateTriggerRemovesHashesOnEmptyChecksum(): void
	{

		$this->insertFilecacheRow( self::TEST_FILE_ID, 'sha1:somehash' );
		$this->assertCount( 1, $this->fetchHashes( self::TEST_FILE_ID ) );

		// Update to empty checksum — trigger should clear hashes
		$this->getRawConnection()
		     ->executeStatement(
			     "UPDATE `{$this->fcTable}` SET checksum = '' WHERE fileid = ?",
			     [ self::TEST_FILE_ID ],
		     )
		;

		$this->assertEmpty(
			$this->fetchHashes( self::TEST_FILE_ID ),
			'Empty checksum update should clear all hashes.',
		);
	}


	// ─── DELETE trigger ──────────────────────────────────────────────

	public function testDeleteTriggerCascadesToHashTable(): void
	{

		$this->insertFilecacheRow( self::TEST_FILE_ID, 'sha1:abc sha256:def' );
		$this->assertCount( 2, $this->fetchHashes( self::TEST_FILE_ID ) );

		$this->getRawConnection()
		     ->executeStatement(
			     "DELETE FROM `{$this->fcTable}` WHERE fileid = ?",
			     [ self::TEST_FILE_ID ],
		     )
		;

		$this->assertEmpty(
			$this->fetchHashes( self::TEST_FILE_ID ),
			'DELETE on filecache should cascade-delete hash rows.',
		);
	}


	// ─── helpers ─────────────────────────────────────────────────────


	/**
	 * Insert a minimal row into oc_filecache for trigger testing.
	 *
	 * Only populates the columns needed by the trigger (fileid, checksum)
	 * plus a few mandatory NOT NULL columns.
	 */
	private function insertFilecacheRow(
		int    $fileid,
		string $checksum,
	): void {

		$this->getRawConnection()
		     ->executeStatement(
			     <<<SQL
INSERT INTO `{$this->fcTable}` (`fileid`, `storage`, `path`, `path_hash`, `parent`, `name`, `mimetype`,
                                `mimepart`, `size`, `mtime`, `storage_mtime`, `encrypted`, `unencrypted_size`,
                                `etag`, `checksum`)
VALUES (?, 1, ?, ?, -1, 'test.dat', 0, 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 0, ?, ?)
SQL,
			     [
				     $fileid,
				     'files/test_' . abs( $fileid ) . '.dat',
				     md5( 'test_' . $fileid ),
				     md5( 'etag_' . $fileid ),
				     $checksum,
			     ],
		     )
		;
	}


	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchHashes( int $fileid ): array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->automaticTablePrefix( false );

		$qb->select( 'algo', 'hash_value' )
		   ->from( $this->hashTable )
		   ->where(
			   $qb->expr()
			      ->eq( 'fileid', $qb->createNamedParameter( $fileid ) ),
		   )
		;

		$rows = $qb->executeQuery()
		           ->fetchAll()
		;

		// fetchAll returns array<int, array> but PHPStan sees array<array-key, mixed>.
		// The explicit @return above documents the real shape.

		/** @var array<int, array<string, mixed>> $rows */
		return $rows;
	}

}
