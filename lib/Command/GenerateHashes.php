<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\User\Exceptions\UserNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class GenerateHashes
	extends
	Command
{

	public function __construct(
		private readonly HashIndexService       $hashIndexService,
		private readonly HashCalculationService $hashCalc,
		private readonly MetadataService        $metadataService,
		private readonly FilecacheService       $filecacheService,
		private readonly RuleService            $ruleService,
		private readonly LoggerInterface        $logger,
	) {

		parent::__construct();
	}

	/**
	 * Configure the generate command.
	 *
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:generate' )
		     ->setDescription( 'Generate checksums for user files or mark them for background processing' )
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
		     ->addOption(
			     'algo',
			     null,
			     InputOption::VALUE_OPTIONAL,
			     'Hash algorithm',
			     HashIndexService::getDefaultAlgo(),
		     )
		     ->addOption(
			     'batch-size',
			     null,
			     InputOption::VALUE_OPTIONAL,
			     'Maximum files to process per run',
		     )
		     ->addOption(
			     'mark',
			     null,
			     InputOption::VALUE_NONE,
			     'Mark files as pending:auto instead of computing hashes immediately',
		     )
		;
	}

	/**
	 * Execute the generate command.
	 *
	 * @noinspection PhpUnused
	 */
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$userScope   = $input->getOption( 'user' );
		$pathPattern = $input->getOption( 'path' );
		$algo        = $input->getOption( 'algo' );
		$batchSize   = $input->getOption( 'batch-size' );
		$batchSize   = $batchSize !== null
			? (int) $batchSize
			: null;
		$markOnly    = (bool) $input->getOption( 'mark' );

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

		if ( $markOnly )
		{
			return $this->executeMarkOnly( $users, $pathPattern, $batchSize, $output );
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

	/**
	 * Mark-only mode: walk user folders and mark matching files as pending:auto.
	 *
	 * @param  string[]     $users
	 * @param  string|null  $pathPattern
	 * @param  int|null     $batchSize
	 * @param  OutputInterface  $output
	 *
	 * @return int
	 */
	private function executeMarkOnly(
		array           $users,
		?string         $pathPattern,
		?int            $batchSize,
		OutputInterface $output,
	): int {

		$output->writeln(
			sprintf(
				'Marking files as pending:auto for %d user(s) …',
				count( $users ),
			),
		);

		if ( $batchSize !== null )
		{
			$output->writeln( sprintf( '  Batch size: %d', $batchSize ) );
		}

		$totalMarked = 0;
		$remaining   = $batchSize;

		foreach ( $users as $userId )
		{
			if ( $remaining !== null && $remaining <= 0 )
			{
				break;
			}

			$output->writeln( sprintf( '  User: %s', $userId ) );

			try
			{
				$userFolder = $this->filecacheService->getUserFolder( $userId );
			}
			catch ( UserNotFoundException )
			{
				$output->writeln( '    User folder not found, skipping.' );

				continue;
			}

			$marked = $this->markFolder(
				$userFolder,
				$pathPattern,
				$remaining,
			);

			$totalMarked += $marked;

			if ( $remaining !== null )
			{
				$remaining -= $marked;
			}

			$output->writeln( sprintf( '    Marked %d files.', $marked ) );
		}

		$limitReached = $batchSize !== null && $totalMarked >= $batchSize;

		$output->writeln(
			sprintf(
				'%s %d files marked as pending:auto.',
				$limitReached
					? 'Batch limit reached.'
					: 'Done.',
				$totalMarked,
			),
		);

		return Command::SUCCESS;
	}

	/**
	 * Mark files matching a path glob as pending:auto.
	 *
	 * Delegates file search to RuleService::searchFilesByGlob().
	 *
	 * @return int Number of files marked
	 */
	private function markFolder(
		Folder   $folder,
		?string  $pathPattern,
		?int     &$remaining,
	): int {

		$files = $this->ruleService->searchFilesByGlob(
			$folder,
			$pathPattern ?? '**',
			$remaining ?? 0,
		);

		$marked = 0;

		foreach ( $files as $file )
		{
			if ( $remaining !== null && $remaining <= 0 )
			{
				break;
			}

			$this->metadataService->markPending( $file->getId(), MetadataService::PENDING_AUTO );
			$marked ++;

			if ( $remaining !== null )
			{
				$remaining --;
			}
		}

		return $marked;
	}

}
