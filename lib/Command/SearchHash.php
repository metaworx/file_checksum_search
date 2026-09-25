<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class SearchHash
    extends
    Command
{

//  constructor

	public function __construct(
		private readonly HashIndexService $hashIndexService,
		private readonly LoggerInterface  $logger,
	)
	{
		parent::__construct();
	}


//  config/init/exe/run methods

	/** @noinspection PhpUnused */
	protected function configure(): void
	{
		$this->setName( 'file-checksum-search:search' )
		     ->setDescription( 'Search files by hash value or algo:hash pair' )
		     ->addArgument( 'query', InputArgument::REQUIRED, 'Hash value (hex) or algo:hash (e.g. sha1:abc123)' )
		     ->addOption(
			     'local-path',
			     null,
			     InputOption::VALUE_NONE,
			     "Also print each file's absolute path on this server's disk; (none) for a storage without local files, and for every file while server-side encryption is enabled",
		     )
		;
	}

	/** @noinspection PhpUnused */
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int
	{
		$term = trim( $input->getArgument( 'query' ) );

		$this->logger->info(
			'FCIAS SearchHash command: invoked',
			[ 'app' => Application::APP_ID ],
		);

		// Parse algo:hash or raw hash
		$parsed = MetadataService::parseQueryTerm( $term );

		if ( $parsed === null )
		{
			$output->writeln( "Invalid hash: $term" );

			return Command::FAILURE;
		}

		$withLocalPath = (bool) $input->getOption( 'local-path' );
		$rows          = $this->hashIndexService->findByHash( $parsed['hash'], $parsed['algo'], 100, null, $withLocalPath );

		if ( empty( $rows ) )
		{
			$output->writeln( 'No files found.' );

			return Command::FAILURE;
		}

		foreach ( $rows as $row )
		{
			$output->writeln(
				sprintf(
					'[%s] (ID: %d) -> %s%s',
					$row['algo'],
					(int) $row['fileid'],
					trim( (string) $row['path'], '/' ),
					$withLocalPath ? ' => ' . ( $row['local_path'] ?? '(none)' ) : '',
				),
			);
		}

		return Command::SUCCESS;
	}
}
