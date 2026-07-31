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
use Symfony\Component\Console\Output\OutputInterface;

class RebuildIndex
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

		$this->setName( 'file-checksum-search:rebuild' )
		     ->setDescription( 'Rebuild the checksum index from existing filecache checksums' )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$prefix  = $this->db->getPrefix();
		$fcTable = $prefix . 'filecache';
		$spName  = $prefix . 'fcias_parse_file_hashes';

		$countQb = $this->db->getQueryBuilder();
		$countQb->select(
			$countQb->func()
			        ->count( '*', 'total' ),
		)
		        ->from( 'filecache' )
		        ->where(
			        $countQb->expr()
			                ->isNotNull( 'checksum' ),
			        $countQb->expr()
			                ->neq( 'checksum', $countQb->createNamedParameter( '' ) ),
		        )
		;
		$total = (int) $countQb->executeQuery()
		                       ->fetchOne()
		;

		$output->writeln( sprintf( 'Rebuilding checksum index for %d files…', $total ) );

		$selectQb = $this->db->getQueryBuilder();
		$selectQb->select( 'fileid', 'checksum' )
		         ->from( 'filecache' )
		         ->where(
			         $selectQb->expr()
			                  ->isNotNull( 'checksum' ),
			         $selectQb->expr()
			                  ->neq( 'checksum', $selectQb->createNamedParameter( '' ) ),
		         )
		;

		$rows      = $selectQb->executeQuery();
		$processed = 0;
		$statement = $this->db->prepare( "CALL `{$spName}`(?, ?)" );

		while ( ( $row = $rows->fetch() ) !== false )
		{
			$statement->execute(
				[
					(int) $row['fileid'],
					$row['checksum'],
				],
			);
			$processed ++;

			if ( $processed % 1000 === 0 )
			{
				$output->writeln( sprintf( '  %d / %d files processed…', $processed, $total ) );
			}
		}
		$rows->closeCursor();

		$output->writeln( sprintf( 'Done. %d files indexed.', $processed ) );

		return Command::SUCCESS;
	}

}
