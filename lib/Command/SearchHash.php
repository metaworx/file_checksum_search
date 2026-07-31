<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SearchHash
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

		$algo = null;
		$hash = $term;

		if ( preg_match( '/^([a-z0-9]+):([a-f0-9]{32,64})$/i', $term, $matches ) )
		{
			$algo = strtolower( $matches[1] );
			$hash = strtolower( $matches[2] );
		}
		else
		{
			$hash = strtolower( $term );
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'h.fileid', 'h.algo', 'h.hash_value', 'fc.path', 'fc.name' )
		   ->from( 'file_checksum_search_hashes', 'h' )
		   ->innerJoin( 'h', 'filecache', 'fc', 'h.fileid = fc.fileid' )
		   ->where(
			   $qb->expr()
			      ->eq( 'h.hash_value', $qb->createNamedParameter( $hash ) ),
		   )
		;

		if ( $algo !== null )
		{
			$qb->andWhere(
				$qb->expr()
				   ->eq( 'h.algo', $qb->createNamedParameter( $algo ) ),
			);
		}

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

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
