<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class GenerateHashes
	extends
	Command
{

	private IRootFolder $rootFolder;


	public function __construct( IRootFolder $rootFolder )
	{

		parent::__construct();
		$this->rootFolder = $rootFolder;
	}


	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:generate' )
		     ->setDescription( 'Generate checksums for user files' )
		     ->addOption( 'user', null, InputOption::VALUE_REQUIRED, 'User whose files to process' )
		     ->addOption(
			     'path',
			     null,
			     InputOption::VALUE_OPTIONAL,
			     'Glob pattern for file paths (e.g. **/*.pdf)',
			     null,
		     )
		     ->addOption( 'algo', null, InputOption::VALUE_OPTIONAL, 'Hash algorithm', 'sha1' )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$userId      = $input->getOption( 'user' );
		$pathPattern = $input->getOption( 'path' );
		$algo        = $input->getOption( 'algo' );

		if ( $userId === null )
		{
			$output->writeln( '<error>The --user option is required.</error>' );

			return Command::FAILURE;
		}

		$userFolder = $this->rootFolder->getUserFolder( $userId );
		$output->writeln( sprintf( 'Generating %s hashes for user "%s"…', $algo, $userId ) );

		$processed = 0;
		$skipped   = 0;

		$this->traverseFolder(
			$userFolder->getPath(),
			$userId,
			$algo,
			$pathPattern,
			$userFolder->getPath(),
			$processed,
			$skipped,
			$output,
		);

		$output->writeln( sprintf( 'Done. %d files hashed, %d skipped.', $processed, $skipped ) );

		return Command::SUCCESS;
	}


	private function traverseFolder(
		string          $folderPath,
		string          $userId,
		string          $algo,
		?string         $pathPattern,
		string          $userFolderPath,
		int             &$processed,
		int             &$skipped,
		OutputInterface $output,
	): void {

		$userFolder = $this->rootFolder->getUserFolder( $userId );

		try
		{
			$node = $userFolder->get( $this->relativePath( $folderPath, $userFolderPath ) );
		}
		catch ( NotFoundException )
		{
			return;
		}

		if ( ! $node instanceof Folder )
		{
			return;
		}

		$directoryIterator = $node->getDirectoryListing();

		foreach ( $directoryIterator as $child )
		{
			$childPath    = $child->getPath();
			$relativePath = $this->relativePath( $childPath, $userFolderPath );

			if ( $child instanceof Folder )
			{
				$this->traverseFolder(
					$childPath,
					$userId,
					$algo,
					$pathPattern,
					$userFolderPath,
					$processed,
					$skipped,
					$output,
				);

				continue;
			}

			if ( ! ( $child instanceof File ) )
			{
				continue;
			}

			// Apply path glob filter
			if ( $pathPattern !== null && ! fnmatch( $pathPattern, $relativePath ) )
			{
				continue;
			}

			// Skip files that already have this algo
			$existingChecksum = $child->getChecksum();
			if ( $existingChecksum !== '' && $this->hasAlgo( $existingChecksum, $algo ) )
			{
				$skipped ++;

				continue;
			}

			// Compute hash in 8KB chunks
			$hash = $this->hashFile( $child, $algo );

			// Append to existing checksums
			$newChecksum = $existingChecksum === ''
				? sprintf( '%s:%s', $algo, $hash )
				: $existingChecksum . ' ' . sprintf( '%s:%s', $algo, $hash );

			$child->setChecksum( $newChecksum );
			$processed ++;

			if ( $processed % 100 === 0 )
			{
				$output->writeln( sprintf( '  %d files processed…', $processed ) );
			}
		}
	}


	private function hashFile(
		File   $file,
		string $algo,
	): string {

		$handle = $file->fopen( 'rb' );
		$ctx    = hash_init( $algo );

		while ( ! feof( $handle ) )
		{
			$chunk = fread( $handle, 8192 );
			hash_update( $ctx, $chunk );
		}

		fclose( $handle );

		return hash_final( $ctx );
	}


	private function hasAlgo(
		string $checksum,
		string $algo,
	): bool {

		$prefix = $algo . ':';

		foreach ( explode( ' ', $checksum ) as $pair )
		{
			if ( str_starts_with( $pair, $prefix ) )
			{
				return true;
			}
		}

		return false;
	}


	private function relativePath(
		string $path,
		string $basePath,
	): string {

		$basePath = rtrim( $basePath, '/' ) . '/';

		if ( str_starts_with( $path, $basePath ) )
		{
			return substr( $path, strlen( $basePath ) );
		}

		return ltrim( $path, '/' );
	}

}
