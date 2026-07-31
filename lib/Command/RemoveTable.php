<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCP\IDBConnection;
use OCP\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveTable
	extends
	Command
{

	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:remove-table' )
		     ->setDescription( 'Drop the FCIAS hash table (run teardown first)' )
		     ->addOption( 'force', 'f', InputOption::VALUE_NONE, 'Required safety flag to confirm table removal' )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		if ( ! $input->getOption( 'force' ) )
		{
			$output->writeln( '<error>This will permanently delete the hash table. Use --force to confirm.</error>' );

			return Command::FAILURE;
		}

		$db     = Server::get( IDBConnection::class );
		$prefix = $db->getPrefix();

		// Warn if triggers or SP still exist
		$trigCount = (int) $db->executeQuery(
			"SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE ?",
			[ $prefix . 't_fcias_after_%' ],
		)
		                      ->fetchOne()
		;

		$spCount = (int) $db->executeQuery(
			"SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ?",
			[ $prefix . 'fcias_parse_file_hashes' ],
		)
		                    ->fetchOne()
		;

		if ( $trigCount > 0 )
		{
			$output->writeln(
				sprintf(
					'<comment>Warning: %d FCIAS trigger(s) still exist. Run teardown first.</comment>',
					$trigCount,
				),
			);
		}

		if ( $spCount > 0 )
		{
			$output->writeln(
				'<comment>Warning: fcias_parse_file_hashes SP still exists. Run teardown first.</comment>',
			);
		}

		LifecycleHandler::purgeShadowTable();
		$output->writeln( 'Hash table dropped.' );

		return Command::SUCCESS;
	}

}
