<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command\Rules;

use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class AddRule
	extends
	RulesCommandBase
{

	/**
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:rules:add' )
		     ->setAliases( [ 'fcias:rules:add' ] )
		     ->setDescription( 'Create a hash generation rule' )
		     ->addRuleFieldOptions()
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

		try
		{
			$definition = $this->definitionValidator->definitionFrom(
				$this->payloadFrom( $input ),
				self::ACTOR,
				true,
			);

			$id = $this->ruleService->ruleAdd( $definition, self::ACTOR );
		}
		catch ( InvalidArgumentException $e )
		{
			$output->writeln( sprintf( '<error>%s</error>', $e->getMessage() ) );

			return self::FAILURE;
		}

		$rule = $this->resolveRule( $id, $output );

		$output->writeln( sprintf( 'Created rule %s.', $id ) );

		if ( $rule !== null && empty( $rule['enabled'] ) )
		{
			$output->writeln(
				'<comment>The rule is disabled — enable it with '
				. 'rules:modify ' . $id . ' --enable.</comment>',
			);
		}

		return self::SUCCESS;
	}

}
