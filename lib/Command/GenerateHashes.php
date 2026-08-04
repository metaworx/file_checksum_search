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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateHashes
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

		$this->setName( 'file-checksum-search:generate' )
		     ->setDescription( 'Generate checksums for user files' )
		     ->addOption(
			     'user',
			     null,
			     InputOption::VALUE_OPTIONAL,
			     'User whose files to process (omit for all users)',
			     'all',
		     )
		     ->addOption(
			     'path',
			     null,
			     InputOption::VALUE_OPTIONAL,
			     'Glob pattern for file paths (e.g. **/*.pdf)',
			     null,
		     )
		     ->addOption( 'algo', null, InputOption::VALUE_OPTIONAL, 'Hash algorithm', HashIndexService::getDefaultAlgo() )
		     ->addOption( 'batch-size', null, InputOption::VALUE_OPTIONAL, 'Maximum files to process per run' )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$userScope  = $input->getOption( 'user' );
		$pathPattern = $input->getOption( 'path' );
		$algo        = $input->getOption( 'algo' );
		$batchSize   = $input->getOption( 'batch-size' );
		$batchSize   = $batchSize !== null
			? (int) $batchSize
			: null;

		$users = $this->hashIndexService->resolveUsers( $userScope );

		if ( empty( $users ) )
		{
			$output->writeln(
				sprintf(
					'<error>No users found for scope "%s".</error>',
					$userScope,
				),
			);

			return Command::FAILURE;
		}

		$output->writeln(
			sprintf(
				'Generating %s hashes for %d user(s) …',
				$algo,
				count( $users ),
			),
		);

		if ( $batchSize !== null )
		{
			$output->writeln( sprintf( '  Batch size: %d', $batchSize ) );
		}

		$this->logger->debug(
			'FCIAS: generate command starting',
			[
				'app'         => Application::APP_ID,
				'userScope'   => $userScope,
				'users'       => $users,
				'algo'        => $algo,
				'pathPattern' => $pathPattern,
				'batchSize'   => $batchSize,
			],
		);

		$totalProcessed = 0;
		$totalSkipped   = 0;
		$remaining      = $batchSize;

		foreach ( $users as $userId )
		{
			if ( $remaining !== null && $remaining <= 0 )
			{
				break;
			}

			$output->writeln( sprintf( '  User: %s', $userId ) );

			$result = $this->hashIndexService->generateMissingHashes(
				$userId,
				$algo,
				$pathPattern,
				$remaining ?? 0,
				$output,
			);

			$totalProcessed += $result['processed'];
			$totalSkipped   += $result['skipped'];

			if ( $remaining !== null )
			{
				$remaining -= $result['processed'];
			}
		}

		$limitReached = $batchSize !== null && $totalProcessed >= $batchSize;

		$output->writeln(
			sprintf(
				'%s %d files hashed, %d skipped.',
				$limitReached
					? 'Batch limit reached.'
					: 'Done.',
				$totalProcessed,
				$totalSkipped,
			),
		);

		return Command::SUCCESS;
	}

}
