<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command\Rules;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * @noinspection PhpUnused
 */
class DeleteRule
	extends
	RulesCommandBase
{

	/**
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:rules:delete' )
		     ->setAliases( [ 'fcias:rules:delete' ] )
		     ->setDescription( 'Delete a hash generation rule' )
		     ->addArgument( 'id', InputArgument::REQUIRED, 'The rule ID (see rules:list)' )
		     ->addOption( 'yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation prompt' )
		;
	}


	/**
	 * A JsonException from the rule store bubbles to Symfony's error
	 * handler, which prints it and exits non-zero — the right report.
	 *
	 * @noinspection PhpUnused
	 * @noinspection PhpUnhandledExceptionInspection
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

		if ( ! $input->getOption( 'yes' ) )
		{
			$row       = $this->ruleRow( $rule );
			$confirmed = $this->getHelper( 'question' )
			                  ->ask(
				                  $input,
				                  $output,
				                  new ConfirmationQuestion(
					                  sprintf(
						                  'Delete rule %s (%s %s on %s for %s)? [y/N] ',
						                  $id,
						                  $row['enforced'] === 'yes'
							                  ? 'enforced'
							                  : 'plain',
						                  $row['type'],
						                  $row['path'],
						                  $row['selector'],
					                  ),
					                  false,
				                  ),
			                  )
			;

			if ( ! $confirmed )
			{
				$output->writeln( 'Aborted.' );

				return self::FAILURE;
			}
		}

		$this->ruleService->ruleDelete( $id, self::ACTOR );
		$output->writeln( sprintf( 'Deleted rule %s.', $id ) );

		return self::SUCCESS;
	}

}
