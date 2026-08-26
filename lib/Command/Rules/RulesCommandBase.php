<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command\Rules;

use OCA\FileChecksumSearch\Service\RuleDefinitionValidator;
use OCA\FileChecksumSearch\Service\RuleService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared mechanics for the rules:* command family.
 *
 * The base carries what every command needs the same way — id resolution
 * with one consistent error, the rule-field options, output rendering —
 * while each command keeps its own semantics. All of them are thin API
 * layers: parsing and formatting here, meaning in RuleService and
 * RuleDefinitionValidator, on the same code path REST and the web UI use.
 *
 * occ runs as the web server user, which is already the highest privilege
 * in play, so every command in this family acts as an administrator
 * ($isAdmin = true wherever validation asks).
 */
abstract class RulesCommandBase
	extends
	Command
{

	/** The audit-log actor for every mutation made through occ. */
	protected const ACTOR = 'cli';


	public function __construct(
		protected readonly RuleService             $ruleService,
		protected readonly RuleDefinitionValidator $definitionValidator,
	) {

		parent::__construct();
	}


	/**
	 * Register the options shared by rules:add and rules:modify.
	 */
	protected function addRuleFieldOptions(): static
	{

		return $this
			->addOption(
				'path',
				null,
				InputOption::VALUE_REQUIRED,
				'Glob pattern the file path must match, e.g. "**" or "Documents/**"',
			)
			->addOption( 'type', null, InputOption::VALUE_REQUIRED, 'Rule verdict: include, ignore, or exclude' )
			->addOption( 'scope', null, InputOption::VALUE_REQUIRED, 'Whose files: "all", "group:<gid>", or a user id' )
			->addOption(
				'algo',
				'a',
				InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				'Checksum algorithm (repeatable; commas work too). Include rules only.',
			)
			->addOption(
				'mode',
				'm',
				InputOption::VALUE_REQUIRED,
				'How existing hashes are treated: auto, missing, force, or lazy. Include rules only.',
			)
			->addOption( 'enforced', null, InputOption::VALUE_NEGATABLE, 'Users may not override or disable this rule' )
			->addOption( 'enable', null, InputOption::VALUE_NONE, 'Create or leave the rule enabled' )
			->addOption( 'disable', null, InputOption::VALUE_NONE, 'Create or leave the rule disabled' )
		;
	}


	/**
	 * Translate the rule-field options into a validator payload, mentioning
	 * only what the operator actually said — omitted fields fall back to the
	 * existing rule (modify) or the validator's defaults (add).
	 *
	 * @return array<string, mixed>
	 */
	protected function payloadFrom( InputInterface $input ): array
	{

		$payload = [];

		if ( $input->getOption( 'path' ) !== null )
		{
			$payload['path'] = $input->getOption( 'path' );
		}

		if ( $input->getOption( 'type' ) !== null )
		{
			$payload['type'] = strtolower( (string) $input->getOption( 'type' ) );
		}

		if ( $input->getOption( 'scope' ) !== null )
		{
			$payload['userScope'] = (string) $input->getOption( 'scope' );
		}

		$algos = [];

		foreach ( $input->getOption( 'algo' ) as $value )
		{
			foreach ( explode( ',', strtolower( (string) $value ) ) as $algo )
			{
				if ( trim( $algo ) !== '' )
				{
					$algos[] = trim( $algo );
				}
			}
		}

		if ( $algos !== [] )
		{
			$payload['algos'] = array_values( array_unique( $algos ) );
		}

		if ( $input->getOption( 'mode' ) !== null )
		{
			$payload['mode'] = strtolower( (string) $input->getOption( 'mode' ) );
		}

		if ( $input->getOption( 'enforced' ) !== null )
		{
			$payload['admin_enforced'] = (bool) $input->getOption( 'enforced' );
		}

		if ( $input->getOption( 'enable' ) )
		{
			$payload['enabled'] = true;
		}
		elseif ( $input->getOption( 'disable' ) )
		{
			$payload['enabled'] = false;
		}

		return $payload;
	}


	/**
	 * Resolve a rule id argument, failing with one consistent message.
	 */
	protected function resolveRule(
		string          $id,
		OutputInterface $output,
	): ?array {

		$rule = $this->ruleService->findRuleById( $id );

		if ( $rule === null )
		{
			$output->writeln(
				sprintf( '<error>No rule with ID "%s". See file-checksum-search:rules:list.</error>', $id ),
			);
		}

		return $rule;
	}


	/**
	 * Render one rule as the fixed-order row every command prints.
	 *
	 * @return array<string, string>
	 */
	protected function ruleRow( array $rule ): array
	{

		$computes = RuleService::verdictOf( $rule ) === RuleService::TYPE_INCLUDE;

		return [
			'id'       => (string) ( $rule['id'] ?? '' ),
			'priority' => sprintf( '%d.%s', RuleService::bandOf( $rule ), $rule['position'] ?? '?' ),
			'enabled'  => empty( $rule['enabled'] )
				? 'no'
				: 'yes',
			'type'     => RuleService::verdictOf( $rule ),
			'scope'    => (string) ( $rule['userScope'] ?? RuleService::SCOPE_ALL ),
			'path'     => (string) ( $rule['path'] ?? '**' ),
			'algos'    => $computes
				? implode( ',', $rule['algos'] ?? [] )
				: '—',
			'mode'     => $computes
				? (string) ( $rule['mode'] ?? 'auto' )
				: '—',
			'enforced' => empty( $rule['admin_enforced'] )
				? 'no'
				: 'yes',
			'pinned'   => empty( $rule['pinned'] )
				? 'no'
				: 'yes',
		];
	}

}
