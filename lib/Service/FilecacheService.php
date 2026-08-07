<?php

namespace OCA\FileChecksumSearch\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;

/**
 * Filecache checksum read/write bridge.
 *
 * Reads and writes hash values in Nextcloud's native filecache.checksum
 * column (format: "ALGO:hex ALGO:hex ...").  Also provides Node resolution
 * helpers (by filecache ID), batch path lookups via filecache+storages join,
 * and checksum-copy support for NodeCopiedEvent.
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class FilecacheService
{

	public function __construct(
		private readonly IRootFolder  $rootFolder,
		private readonly IDBConnection $db,
	) {
	}


	/**
	 * @param  int|\OCP\Files\File  $file
	 *
	 * @return File
	 * @noinspection PhpDocMissingThrowsInspection
	 * @throws \OCP\Files\NotFoundException
	 */
	public function getFile( int|File $file ): File
	{

		/** @noinspection PhpUnhandledExceptionInspection */
		if ( $file instanceof File )
		{
			return $file;
		}

		$node = $this->rootFolder->getFirstNodeById( $file );

		if ( $node instanceof File )
		{
			return $node;
		}

		throw  new NotFoundException( "Invalid Filecache ID: $file" );
	}


	public function getChecksums(
		int|File $file,
		?array   $hashFilter = null,
	): array {

		$file             = $this->getFile( $file );
		$existingChecksum = $file->getChecksum() ?? '';
		$hashes           = [];
		$count            = $hashFilter === null
			? 0
			: count( $hashFilter );

		foreach ( explode( ' ', $existingChecksum ) as $pair )
		{
			if ( $pair === '' )
			{
				continue;
			}

			[
				$algoUpper,
				$hash,
			]
				= explode( ':', $pair );

			$algo = strtolower( $algoUpper );

			if ( $hashFilter === null || in_array( $algo, $hashFilter ) )
			{
				$hashes[ $algo ] = $hash;
			}

			if ( count( $hashes ) === $count )
			{
				break;
			}
		}

		return $hashes;
	}


	public function getNodeById( int $fileId ): Node
	{

		$nodes = $this->rootFolder->getById( $fileId );

		if ( empty( $nodes ) )
		{
			throw new NotFoundException( "Invalid file ID: $fileId" );
		}

		return $nodes[0];

	}


	/**
	 * @param  string  $userId
	 *
	 * @return \OCP\Files\Folder
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OCP\User\Exceptions\UserNotFoundException
	 */
	public function getUserFolder( string $userId ): Folder
	{

		return $this->rootFolder->getUserFolder( $userId );
	}


	/**
	 * @param  string  $userId
	 *
	 * @return \OCP\Files\Folder
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OCP\User\Exceptions\UserNotFoundException
	 */
	public function getUserFolderPath( string $userId ): string
	{

		$userFolder = $this->getUserFolder( $userId );

		return $userFolder->getPath();
	}


	public function setHashes(
		int|File $file,
		?array   $hashes = null,
		bool     $keepAdditional = false,
	): void {

		$file = $this->getFile( $file );

		$existingHashes = $keepAdditional
			? $this->getChecksums( $file )
			: [];

		$newHashes = [];
		$hashes    ??= [];

		foreach ( $hashes as $algo => $hash )
		{
			$algoUpper   = strtoupper( $algo );
			$newHashes[] = "$algoUpper:$hash";
			if ( $keepAdditional )
			{
				unset( $existingHashes[ $algo ] );
			}
		}

		if ( $keepAdditional )
		{
			foreach ( $existingHashes as $algo => $hash )
			{
				$algoUpper   = strtoupper( $algo );
				$newHashes[] = "$algoUpper:$hash";
			}
		}

		$file->getStorage()
		     ->getCache()
		     ->update( $file->getId(), [ 'checksum' => implode( ' ', $newHashes ) ] )
		;
	}


	/**
	 * Copy the filecache checksum from source to target file.
	 *
	 * Used by NodeCopiedEvent to preserve NC's native checksum
	 * on copied files.
	 */
	public function copyFilecacheChecksum(
		File $source,
		File $target,
	): void {

		/** @noinspection PhpUnhandledExceptionInspection */
		$checksum = $source->getChecksum();

		if ( $checksum === null || $checksum === '' )
		{
			return;
		}

		/** @noinspection PhpUnhandledExceptionInspection */
		$targetStorage = $target->getStorage();
		$targetCache   = $targetStorage->getCache();

		/** @noinspection PhpUnhandledExceptionInspection */
		$targetCache->update( $target->getId(), [ 'checksum' => $checksum ] );
	}


	/**
	 * @param  int|\OCP\Files\File  $file
	 *
	 * @return int
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	public static function getFileId( int|File $file ): int
	{

		/** @noinspection PhpUnhandledExceptionInspection */
		return $file instanceof File
			? $file->getId()
			: $file;
	}


	/**
	 * Batch-lookup filecache paths for a list of file IDs.
	 *
	 * Joins storages to resolve the storage ID for each file.
	 * When $userName is provided, only files from that user's home
	 * storage are returned (matched via storages.id = 'home::{uid}').
	 *
	 * @param  int[]        $fileIds
	 * @param  string|null  $userName
	 *
	 * @return array<int, array{path: string, name: string, storage_id: string, user: string}>
	 */
	public function batchLookupFilecachePaths(
		array   $fileIds,
		?string $userName = null,
	): array {

		if ( empty( $fileIds ) )
		{
			return [];
		}

		$qb = $this->db->getQueryBuilder();

		$qb->select( 'fc.fileid', 'fc.path', 'fc.name', 's.id' )
		   ->from( 'filecache', 'fc' )
		   ->innerJoin(
			   'fc',
			   'storages',
			   's',
			   'fc.storage = s.numeric_id',
		   )
		;

		if ( $userName !== null )
		{
			$qb->where(
				$qb->expr()
				   ->eq(
					   's.id',
					   $qb->createNamedParameter( 'home::' . $userName ),
				   ),
			)
			   ->andWhere(
				   $qb->expr()
				      ->in(
					      'fc.fileid',
					      $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
				      ),
			   )
			;
		}
		else
		{
			$qb->where(
				$qb->expr()
				   ->in(
					   'fc.fileid',
					   $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
				   ),
			);
		}

		$result = $qb->executeQuery();
		$paths  = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$sid  = (string) $row['id'];
			$user = $sid;

			if ( str_starts_with( $sid, 'home::' ) )
			{
				$user = substr( $sid, 6 );
			}
			elseif ( str_starts_with( $sid, 'local::' ) )
			{
				$user = basename( $sid );
			}

			$paths[ (int) $row['fileid'] ] = [
				'path'       => (string) $row['path'],
				'name'       => (string) $row['name'],
				'storage_id' => $sid,
				'user'       => $user,
			];
		}
		$result->closeCursor();

		return $paths;
	}

}
