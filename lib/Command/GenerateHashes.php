<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateHashes
	extends
	Command
{

	public function __construct(
		private readonly IRootFolder     $rootFolder,
		private readonly LoggerInterface $logger,
	) {

		parent::__construct();
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
		$output->writeln( sprintf( 'Generating %s hashes for user "%s" …', $algo, $userId ) );

		$this->logger->debug(
			'FCIAS: generate command starting',
			[
				'app'            => Application::APP_ID,
				'userId'         => $userId,
				'algo'           => $algo,
				'pathPattern'    => $pathPattern,
				'userFolderPath' => $userFolder->getPath(),
			],
		);

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
		$relPath    = $this->relativePath( $folderPath, $userFolderPath );

		$this->logger->debug(
			'FCIAS: traverseFolder entry',
			[
				'app'            => Application::APP_ID,
				'userId'         => $userId,
				'folderPath'     => $folderPath,
				'userFolderPath' => $userFolderPath,
				'relativePath'   => $relPath,
			],
		);

		try
		{
			$node = $userFolder->get( $relPath );
		}
		catch ( NotFoundException $e )
		{
			$this->logger->debug(
				'FCIAS: traverseFolder — node not found, returning',
				[
					'app'          => Application::APP_ID,
					'userId'       => $userId,
					'relativePath' => $relPath,
					'error'        => $e->getMessage(),
				],
			);

			return;
		}

		if ( ! $node instanceof Folder )
		{
			$this->logger->debug(
				'FCIAS: traverseFolder — node is not a Folder',
				[
					'app'          => Application::APP_ID,
					'userId'       => $userId,
					'relativePath' => $relPath,
					'nodeType'     => get_debug_type( $node ),
				],
			);

			return;
		}

		if ( $output->isVeryVerbose() )
		{
			$output->writeln(
				sprintf(
					'  Entering folder: %s',
					$relPath === ''
						? '/'
						: $relPath,
				),
			);
		}

		$directoryIterator = $node->getDirectoryListing();
		$childCount        = 0;

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
				$this->logger->debug(
					'FCIAS: traverseFolder — child is not a File, skipping',
					[
						'app'       => Application::APP_ID,
						'userId'    => $userId,
						'childPath' => $childPath,
						'nodeType'  => get_debug_type( $child ),
					],
				);

				continue;
			}

			$childCount ++;

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

			try
			{
				// Compute hash
				$hash = $this->hashFile( $child, $algo );

				// Format checksum as NC standard uppercase algo:hash
				$formattedChecksum = strtoupper( $algo ) . ':' . $hash;

				// Append to existing checksums
				$newChecksum = $existingChecksum === ''
					? $formattedChecksum
					: $existingChecksum . ' ' . $formattedChecksum;

				$storage = $child->getStorage();
				$cache   = $storage->getCache();

				$cache->update( $child->getId(), [ 'checksum' => $newChecksum ] );
				$processed ++;
			}
			catch ( \Throwable $e )
			{
				$output->warning( 'FCIAS: setChecksum() failed: ' . $e->getMessage() );
				$this->logger->error(
					'FCIAS GenerateHashes ERROR: ' . $e->getMessage(),
					[
						'app'       => Application::APP_ID,
						'exception' => $e,
					],
				);
				continue;
			}

			$this->logger->debug(
				'FCIAS: file hashed',
				[
					'app'          => Application::APP_ID,
					'userId'       => $userId,
					'relativePath' => $relativePath,
					'algo'         => $algo,
					'hash'         => $hash,
				],
			);

			if ( $output->isDebug() )
			{
				$output->writeln( sprintf( '    Hashed: %s (%s)', $relativePath, $hash ) );
			}
			elseif ( $processed % 10 == 0 )
			{
				$output->writeln( sprintf( '    %d files processed …', $processed ) );
			}
		}

		$this->logger->debug(
			'FCIAS: traverseFolder complete',
			[
				'app'            => Application::APP_ID,
				'userId'         => $userId,
				'folderPath'     => $folderPath,
				'childFileCount' => $childCount,
			],
		);
	}


	private function hashFile(
		File   $file,
		string $algo,
	): string {

		$storage = $file->getStorage();

		if ( $storage->isLocal() )
		{
			$absolutePath = $storage->getLocalFile( $file->getInternalPath() );

			return hash_file( $algo, $absolutePath );
		}

		// Fallback for external/encrypted storage: stream-based hashing
		$handle = $file->fopen( 'rb' );
		$ctx    = hash_init( $algo );

		hash_update_stream( $ctx, $handle );

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

		$basePath = rtrim( $basePath, '/' );

		if ( $path === $basePath )
		{
			return '';
		}

		$basePath .= '/';

		if ( str_starts_with( $path, $basePath ) )
		{
			return substr( $path, strlen( $basePath ) );
		}

		return ltrim( $path, '/' );
	}

}
