<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command\Rules;

use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class ApplyRule
	extends
	RulesCommandBase
{

	/**
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:rules:apply' )
		     ->setAliases( [ 'fcias:rules:apply' ] )
		     ->setDescription(
			     'Queue every file this rule currently governs for background hashing '
			     . '(uncapped, unlike the periodic sweep)',
		     )
		     ->addArgument( 'id', InputArgument::REQUIRED, 'The rule ID (see rules:list)' )
		     ->addOption(
			     'mode',
			     'm',
			     InputOption::VALUE_REQUIRED,
			     'Queue with this processing mode instead of the rule\'s own '
			     . '(auto, missing, force, lazy). Logged.',
		     )
		;
	}


	/**
	 * @noinspection PhpUnused
	 */
	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$id   = (string) $input->getArgument( 'id' );
		$rule = $this->resolveRule( $id, $output );

		if ( $rule === null )
		{
			return self::FAILURE;
		}

		$modeOverride = $input->getOption( 'mode' ) !== null
			? strtolower( (string) $input->getOption( 'mode' ) )
			: null;

		try
		{
			$result = $this->ruleService->applyRule( $rule, $modeOverride, $output, self::ACTOR );
		}
		catch ( InvalidArgumentException $e )
		{
			$output->writeln( sprintf( '<error>%s</error>', $e->getMessage() ) );

			return self::FAILURE;
		}

		$output->writeln(
			sprintf(
				'Rule %s: %d files matched — %d queued, %d already fresh, %d claimed by '
				. 'other rules. The background job computes the hashes from here.',
				$id,
				$result['matched'],
				$result['marked'],
				$result['fresh'],
				$result['skipped'],
			),
		);

		return self::SUCCESS;
	}

}
