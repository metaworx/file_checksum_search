<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Migration;

use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Server;

/**
 * Integration test for the updated_at column migration.
 *
 * Verifies that the column exists after table creation (LifecycleHandler
 * already includes updated_at in its CREATE TABLE statement).
 */
class Version010001Test
	extends
	DatabaseTestCase
{

	private LifecycleHandler $lifecycle;


	protected function setUp(): void
	{

		parent::setUp();

		$this->lifecycle = Server::get( LifecycleHandler::class );
		$this->lifecycle->createTables();
	}


	protected function tearDown(): void
	{

		try
		{
			$this->getRawConnection()
			     ->executeStatement(
				     'DROP TABLE IF EXISTS ' . $this->getHashTableName(),
			     )
			;
		}
		catch ( \Throwable )
		{
		}

		parent::tearDown();
	}


	public function testUpdatedAtColumnExists(): void
	{

		$this->assertColumnExists( $this->getHashTableName(), 'updated_at' );
	}


	public function testUpdatedAtColumnIsNullable(): void
	{

		// Insert a row without updated_at — should default to NULL.
		$this->getRawConnection()
		     ->executeStatement(
			     "INSERT INTO {$this->getHashTableName()} (fileid, algo, hash_value) VALUES (?, ?, ?)",
			     [
				     99999001,
				     'sha1',
				     'test',
			     ],
		     )
		;

		$qb = $this->db->getQueryBuilder();
		$qb->automaticTablePrefix( false );
		$qb->select( 'updated_at' )
		   ->from( $this->getHashTableName() )
		   ->where(
			   $qb->expr()
			      ->eq( 'fileid', $qb->createNamedParameter( 99999001 ) ),
		   )
		;

		$value = $qb->executeQuery()
		            ->fetchOne()
		;

		$this->assertNull( $value, 'updated_at should default to NULL when not provided.' );

		// Cleanup
		$this->getRawConnection()
		     ->executeStatement(
			     "DELETE FROM {$this->getHashTableName()} WHERE fileid = ?",
			     [ 99999001 ],
		     )
		;
	}

}
