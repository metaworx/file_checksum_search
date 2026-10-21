<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\Files_Versions\Versions\IMetadataVersion;
use OCA\Files_Versions\Versions\IVersion;
use OCA\Files_Versions\Versions\IVersionBackend;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use OCP\Files\Storage\IStorage;
use OCP\IUser;

/**
 * A version of a file in a home's trash, as the versions app's importer
 * takes one ({@see DuplicateActionService}).
 *
 * The trash keeps such a version as a file of its own, which no versions
 * backend lists: the importer asks a version's backend for its file, so
 * this version is its own backend, and answers with that file. It is
 * never registered, and serves nothing else.
 *
 * It carries no metadata, and says so: the home backend's importer writes a
 * version's metadata only for one that has some to give, and its table
 * takes no row without.
 *
 * Loaded only where the versions app is there, since it implements that
 * app's interfaces.
 */
final class TrashedVersion
    implements
    IVersion
    , IVersionBackend
    , IMetadataVersion
{

//  constructor

	/**
	 * @param  File      $file       The version's own file in the trash.
	 * @param  FileInfo  $source     The trashed file it is a version of.
	 * @param  int       $timestamp  When the file held it.
	 */
	public function __construct(
		private readonly File     $file,
		private readonly FileInfo $source,
		private readonly int      $timestamp,
		private readonly IUser    $user,
	) {
	}


//  getters / setters / is* / has*

	public function getBackend(): IVersionBackend
	{
		return $this;
	}

	public function getSourceFile(): FileInfo
	{
		return $this->source;
	}

	public function getRevisionId()
	{
		return $this->timestamp;
	}

	public function getTimestamp(): int
	{
		return $this->timestamp;
	}

	public function getSize(): int|float
	{
		return $this->file->getSize();
	}

	public function getSourceFileName(): string
	{
		return $this->source->getName();
	}

	public function getMimeType(): string
	{
		return $this->source->getMimetype();
	}

	public function getVersionPath(): string
	{
		return $this->file->getPath();
	}

	public function getUser(): IUser
	{
		return $this->user;
	}

	public function getVersionFile(
		IUser    $user,
		FileInfo $sourceFile,
		$revision,
	): File
	{
		return $this->file;
	}

	public function getRevision( Node $node ): int
	{
		return $this->timestamp;
	}

	public function getMetadata(): array
	{
		return [];
	}

	public function getMetadataValue( string $key ): ?string
	{
		return null;
	}


//  other non-static methods

	public function useBackendForStorage( IStorage $storage ): bool
	{
		return false;
	}

	public function getVersionsForFile(
		IUser    $user,
		FileInfo $file,
	): array
	{
		return [ $this ];
	}

	public function createVersion(
		IUser    $user,
		FileInfo $file,
	)
	{
		throw new NotPermittedException( 'A version in the trash makes no versions.' );
	}

	public function rollback( IVersion $version )
	{
		throw new NotPermittedException( 'A version in the trash is restored by importing it.' );
	}

	public function read( IVersion $version )
	{
		return $this->file->fopen( 'r' );
	}
}
