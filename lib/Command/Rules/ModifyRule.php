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
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @noinspection PhpUnused
 */
class ModifyRule
	extends
	RulesCommandBase
{

	/**
	 * @noinspection PhpUnused
	 */
	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:rules:modify' )
		     ->setAliases( [ 'fcias:rules:modify' ] )
		     ->setDescription( 'Change a hash generation rule; omitted options keep their value' )
		     ->addArgument( 'id', InputArgument::REQUIRED, 'The rule ID (see rules:list)' )
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

		$id       = (string) $input->getArgument( 'id' );
		$existing = $this->resolveRule( $id, $output );

		if ( $existing === null )
		{
			return self::FAILURE;
		}

		$payload = $this->payloadFrom( $input );

		if ( $payload === [] )
		{
			$output->writeln( '<comment>Nothing to change — no options given.</comment>' );

			return self::SUCCESS;
		}

		// An enable/disable with no other change goes through ruleToggle, so
		// the audit trail names the operation for what it is.
		if ( array_keys( $payload ) === [ 'enabled' ] )
		{
			$this->ruleService->ruleToggle( $id, (bool) $payload['enabled'], self::ACTOR );
			$output->writeln(
				sprintf(
					'Rule %s %s.',
					$id,
					$payload['enabled']
						? 'enabled'
						: 'disabled',
				),
			);

			return self::SUCCESS;
		}

		try
		{
			$definition = $this->definitionValidator->definitionFrom(
				$payload,
				self::ACTOR,
				true,
				$existing,
			);

			$this->ruleService->ruleUpdate( $id, $definition, self::ACTOR );
		}
		catch ( InvalidArgumentException $e )
		{
			$output->writeln( sprintf( '<error>%s</error>', $e->getMessage() ) );

			return self::FAILURE;
		}

		$output->writeln( sprintf( 'Updated rule %s.', $id ) );

		return self::SUCCESS;
	}

}
