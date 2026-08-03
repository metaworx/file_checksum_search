<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowStatus
	extends
	Command
{

	private IDBConnection $db;

	private TableNameService $tables;


	public function __construct( IDBConnection $db, TableNameService $tables )
	{

		parent::__construct();
		$this->db     = $db;
		$this->tables = $tables;
	}


	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:status' )
		     ->setDescription( 'Display FCIAS app status and compatibility information' )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$prefix    = $this->tables->getPrefix();
		$hashTable = $this->tables->getHashTableName();

		$output->writeln( '=== FCIAS Status ===' );
		$output->writeln( '' );

		// App version
		$appVersion = \OCP\Server::get( \OCP\App\IAppManager::class )
		                         ->getAppVersion( 'file_checksum_search' )
		;
		$output->writeln( sprintf( 'App version:     %s', $appVersion ) );

		// MariaDB version
		$versionRow = $this->db->executeQuery( 'SELECT VERSION() AS version' )
		                       ->fetch()
		;
		$output->writeln( sprintf( 'MariaDB version: %s', $versionRow['version'] ?? 'unknown' ) );

		// TRIGGER privilege
		try
		{
			$this->db->executeStatement( "CREATE TEMPORARY TABLE IF NOT EXISTS `{$prefix}fcias_priv_check` (x INT)" );
			$this->db->executeStatement(
				"CREATE TRIGGER `{$prefix}fcias_priv_check_t` BEFORE INSERT ON `{$prefix}fcias_priv_check` FOR EACH ROW BEGIN END",
			);
			$this->db->executeStatement( "DROP TRIGGER `{$prefix}fcias_priv_check_t`" );
			$this->db->executeStatement( "DROP TEMPORARY TABLE `{$prefix}fcias_priv_check`" );
			$output->writeln( 'TRIGGER priv:    OK' );
		}
		catch ( \Throwable )
		{
			$output->writeln( 'TRIGGER priv:    MISSING' );
		}

		// Shadow table row count
		try
		{
			$count = (int) $this->db->executeQuery( "SELECT COUNT(*) FROM `{$hashTable}`" )
			                        ->fetchOne()
			;
			$output->writeln( sprintf( 'Hash rows:       %d', $count ) );
		}
		catch ( \Throwable )
		{
			$output->writeln( 'Hash rows:       TABLE NOT FOUND' );
		}

		// Stored procedure
		try
		{
			$sp = $this->db->executeQuery(
				"SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ?",
				[ $prefix . 'fcias_parse_file_hashes' ],
			)
			               ->fetchOne()
			;
			$output->writeln(
				sprintf(
					'SP installed:    %s',
					$sp > 0
						? 'YES'
						: 'NO',
				),
			);
		}
		catch ( \Throwable )
		{
			$output->writeln( 'SP installed:    NO' );
		}

		// Triggers
		try
		{
			$trigCount = (int) $this->db->executeQuery(
				"SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE ?",
				[ $prefix . 't_fcias_after_%' ],
			)
			                            ->fetchOne()
			;
			$output->writeln( sprintf( 'Triggers:        %d/3 installed', $trigCount ) );
		}
		catch ( \Throwable )
		{
			$output->writeln( 'Triggers:        0/3 installed' );
		}

		return Command::SUCCESS;
	}

}
