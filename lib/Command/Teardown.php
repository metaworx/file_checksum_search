<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Teardown
	extends
	Command
{

	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:teardown' )
		     ->setDescription( 'Drop FCIAS triggers and stored procedure (preserves hash table)' )
		     ->addOption( 'force', 'f', InputOption::VALUE_NONE, 'Required safety flag to confirm teardown' )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		if ( ! $input->getOption( 'force' ) )
		{
			$output->writeln(
				'<error>This will remove FCIAS triggers and stored procedure. Use --force to confirm.</error>',
			);

			return Command::FAILURE;
		}

		LifecycleHandler::stripTriggers();

		$output->writeln( 'Triggers dropped:  t_fcias_after_insert, t_fcias_after_update, t_fcias_after_delete' );
		$output->writeln( 'SP dropped:        fcias_parse_file_hashes' );
		$output->writeln( 'Hash table preserved.' );

		return Command::SUCCESS;
	}

}
