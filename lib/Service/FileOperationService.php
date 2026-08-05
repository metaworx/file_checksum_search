<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\Files\File;
use OCP\IDBConnection;

/**
 * Hash row CRUD operations and filecache checksum manipulation.
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class FileOperationService
{

	public function __construct(
		private readonly IDBConnection    $db,
		private readonly TableNameService $tables,
	) {
	}


	/**
	 * Copy all hash rows from a source file to a target file.
	 *
	 * Used by NodeCopiedEvent — identical content, identical hashes.
	 */
	public function copyHashes(
		int $sourceFileId,
		int $targetFileId,
	): void {

		$hashTable = $this->tables->getHashTableName();

		$this->db->executeStatement(
			"INSERT IGNORE INTO `$hashTable` (`fileid`, `algo`, `hash_value`, `updated_at`) SELECT ?, `algo`, `hash_value`, NOW() FROM `$hashTable` WHERE `fileid` = ?",
			[
				$targetFileId,
				$sourceFileId,
			],
		);
	}


	/**
	 * Delete all hash rows for a given fileid.
	 *
	 * @return int Number of deleted rows
	 */
	public function deleteHashes( int $fileId ): int
	{

		$hashTable = $this->tables->getHashTableName();

		return $this->db->executeStatement(
			"DELETE FROM `$hashTable` WHERE `fileid` = ?",
			[ $fileId ],
		);
	}


	/**
	 * Count hash rows for a given fileid.
	 */
	public function countHashes( int $fileId ): int
	{

		$qb = $this->db->getQueryBuilder();

		$qb->select(
			$qb->func()
			   ->count( '*', 'cnt' ),
		)
		   ->from( $this->tables->getHashTableName() )
		   ->where(
			   $qb->expr()
			      ->eq( 'fileid', $qb->createNamedParameter( $fileId, \PDO::PARAM_INT ) ),
		   )
		;

		return (int) $qb->executeQuery()
		                ->fetchOne()
		;
	}


	/**
	 * Copy the filecache checksum from source to target file.
	 *
	 * Used by NodeCopiedEvent. Updating the target's checksum triggers
	 * the AFTER UPDATE trigger on oc_filecache, which in turn calls the
	 * fcias_parse_file_hashes SP to populate the hash table.
	 */
	public function copyFilecacheChecksum(
		File $source,
		File $target,
	): void {

		$checksum = $source->getChecksum();

		if ( $checksum === null || $checksum === '' )
		{
			return;
		}

		$targetStorage = $target->getStorage();
		$targetCache   = $targetStorage->getCache();
		$targetCache->update( $target->getId(), [ 'checksum' => $checksum ] );
	}

}
