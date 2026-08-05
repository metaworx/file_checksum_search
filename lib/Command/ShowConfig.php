<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ShowConfig
	extends
	Command
{

	public function __construct(
		private readonly IAppConfig      $appConfig,
		private readonly LoggerInterface $logger,
	) {

		parent::__construct();
	}


	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:show-config' )
		     ->setDescription( 'Display all app config key/value pairs' )
		     ->addOption(
			     'output',
			     'o',
			     InputOption::VALUE_REQUIRED,
			     'Output format: plain, json, json_pretty (default: plain)',
			     'plain',
		     )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$outFmt = $input->getOption( 'output' );

		$this->logger->info(
			'FCIAS ShowConfig command: invoked',
			[ 'app' => Application::APP_ID ],
		);
		$values = $this->appConfig->getAllValues( Application::APP_ID );

		if ( $outFmt === 'json' || $outFmt === 'json_pretty' )
		{
			$output->writeln(
				json_encode(
					$values,
					$outFmt === 'json_pretty'
						? JSON_PRETTY_PRINT
						: 0,
				),
			);

			return Command::SUCCESS;
		}

		if ( empty( $values ) )
		{
			$output->writeln( 'No config values found.' );

			return Command::SUCCESS;
		}

		foreach ( $values as $key => $value )
		{
			$displayValue = match ( true )
			{
				is_bool( $value ) => $value
					? 'true'
					: 'false',
				is_array( $value ) => json_encode( $value ),
				default => (string) $value,
			};

			$output->writeln(
				sprintf(
					'  %-40s %s',
					$key,
					$displayValue,
				),
			);
		}

		return Command::SUCCESS;
	}

}
