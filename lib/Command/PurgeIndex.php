<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PurgeIndex
	extends
	Command
{

	private IDBConnection $db;


	public function __construct( IDBConnection $db )
	{

		parent::__construct();
		$this->db = $db;
	}


	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:purge' )
		     ->setDescription( 'Truncate the checksum hash index table' )
		     ->addOption( 'force', 'f', InputOption::VALUE_NONE, 'Required safety flag to confirm purge' )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		if ( ! $input->getOption( 'force' ) )
		{
			$output->writeln( '<error>This will delete ALL checksum index data. Use --force to confirm.</error>' );

			return Command::FAILURE;
		}

		$prefix    = $this->db->getPrefix();
		$hashTable = $prefix . 'file_checksum_search_hashes';

		$before = (int) $this->db->executeQuery( "SELECT COUNT(*) FROM `{$hashTable}`" )
		                         ->fetchOne()
		;
		$this->db->executeStatement( "TRUNCATE TABLE `{$hashTable}`" );
		$after = (int) $this->db->executeQuery( "SELECT COUNT(*) FROM `{$hashTable}`" )
		                        ->fetchOne()
		;

		$output->writeln( sprintf( 'Rows before purge: %d', $before ) );
		$output->writeln( sprintf( 'Rows after purge:  %d', $after ) );
		$output->writeln( sprintf( 'Purged:            %d rows', $before - $after ) );

		return Command::SUCCESS;
	}

}
