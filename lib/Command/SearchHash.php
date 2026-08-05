<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SearchHash
	extends
	Command
{

	public function __construct(
		private readonly HashIndexService $hashIndexService,
		private readonly LoggerInterface  $logger,
	) {

		parent::__construct();
	}


	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:search' )
		     ->setDescription( 'Search files by hash value or algo:hash pair' )
		     ->addArgument( 'query', InputArgument::REQUIRED, 'Hash value (hex) or algo:hash (e.g. sha1:abc123)' )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$term = trim( $input->getArgument( 'query' ) );

		$this->logger->info(
			'FCIAS SearchHash command: invoked',
			[ 'app' => Application::APP_ID ],
		);

		$algo = null;

		if ( preg_match( '/^([a-z0-9]+):([a-f0-9]{32,64})$/i', $term, $matches ) )
		{
			$algo = strtolower( $matches[1] );
			$hash = strtolower( $matches[2] );
		}
		else
		{
			$hash = strtolower( $term );
		}

		$rows = $this->hashIndexService->findByHash( $hash, $algo );

		if ( empty( $rows ) )
		{
			$output->writeln( 'No files found.' );

			return Command::FAILURE;
		}

		foreach ( $rows as $row )
		{
			$output->writeln(
				sprintf(
					'[%s] (ID: %d) -> %s/%s',
					$row['algo'],
					(int) $row['fileid'],
					trim( (string) $row['path'], '/' ),
					$row['name'],
				),
			);
		}

		return Command::SUCCESS;
	}

}
