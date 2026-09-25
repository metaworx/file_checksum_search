<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command\Rules;

use OCA\FileChecksumSearch\Service\RuleService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class ListRules
    extends
    RulesCommandBase
{

//  config/init/exe/run methods

	/**
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{
		$this->setName( 'file-checksum-search:rules:list' )
		     ->setAliases( [ 'fcias:rules:list' ] )
		     ->setDescription( 'List the hash generation rules in evaluation order' )
		     ->addOption(
			     'output',
			     'o',
			     InputOption::VALUE_REQUIRED,
			     'Output format: plain, json, json_pretty',
			     'plain',
		     )
		;
	}

	/**
	 * @noinspection PhpUnused
	 */
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int
	{
		$rows      = [];
		$positions = [];

		// loadRules() is band-sorted, and this numbers within each band.
		// The settings pages number within each *segment* — band plus
		// selector — so a band holding two selectors shows two rules
		// numbered 1 there and 1 and 2 here. Same order either way; only
		// the ordinal differs.
		foreach ( $this->ruleService->loadRules() as $rule )
		{
			$band               = RuleService::bandOf( $rule );
			$positions[ $band ] = ( $positions[ $band ] ?? 0 ) + 1;
			$rule['position']   = $positions[ $band ];

			$rows[] = $this->ruleRow( $rule );
		}

		$format = (string) $input->getOption( 'output' );

		if ( $format === 'json' || $format === 'json_pretty' )
		{
			$output->writeln(
				(string) json_encode(
					$rows,
					$format === 'json_pretty'
						? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
						: JSON_UNESCAPED_SLASHES,
				),
			);

			return self::SUCCESS;
		}

		if ( $rows === [] )
		{
			$output->writeln( 'No rules defined.' );

			return self::SUCCESS;
		}

		$table = new Table( $output );
		$table->setHeaders( array_keys( $rows[0] ) )
		      ->setRows( $rows )
		      ->render()
		;

		return self::SUCCESS;
	}
}
