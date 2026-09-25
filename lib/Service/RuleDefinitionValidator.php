<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use InvalidArgumentException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * The one place a rule payload becomes a stored rule definition.
 *
 * REST, occ and the public PHP API all validate through here, so what a rule
 * may say — and what a non-administrator may say about one — cannot drift
 * between surfaces. The interface layers parse their transport's input and
 * format the result; the meaning of that input is decided here.
 *
 * Trust is expressed the same way throughout: $isAdmin true means the caller
 * may name any selector and may enforce (occ always may; REST decides per
 * session; DI callers decide via their requesting-user parameter).
 * A non-administrator's rule is always their own and never enforced,
 * whatever the payload claims.
 */
readonly class RuleDefinitionValidator
{

//  constructor

	public function __construct(
		private IGroupManager      $groupManager,
		private IUserManager       $userManager,
		private AlgorithmCatalogue $catalogue,
	) {
	}


//  other non-static methods

	/**
	 * Build a stored rule definition from a request payload.
	 *
	 * @param  array       $body      The payload, transport-decoded
	 * @param  string      $userId    Who is asking ('cli' etc. for trusted
	 *                                non-session callers — only read when
	 *                                $isAdmin is false)
	 * @param  bool        $isAdmin   Whether the caller may name any
	 *                                selector and may enforce
	 * @param  array|null  $existing  The rule being updated, as fallback for
	 *                                omitted fields; null when creating
	 *
	 * @throws InvalidArgumentException on anything the caller may not express
	 */
	public function definitionFrom(
		array  $body,
		string $userId,
		bool   $isAdmin,
		?array $existing = null,
	): array
	{
		$type = $body['type'] ?? RuleService::TYPE_INCLUDE;

		if ( ! RuleService::isValidType( $type ) )
		{
			throw new InvalidArgumentException( 'Unknown rule type.' );
		}

		$path = $body['path'] ?? ( $existing['path'] ?? '/' );

		if ( ! is_string( $path ) || trim( $path ) === '' )
		{
			throw new InvalidArgumentException( 'A path is required.' );
		}

		$definition = [
			'enabled'        => (bool) ( $body['enabled'] ?? $existing['enabled'] ?? true ),
			'type'           => $type,
			'path'           => $path,
			'selector'       => $isAdmin
				? $this->validatedSelector( $body, $existing )
				: 'home:' . $userId,
			'admin_enforced' => $isAdmin
				&& ( $body['admin_enforced'] ?? $existing['admin_enforced'] ?? false ),
		];

		if ( $type !== RuleService::TYPE_INCLUDE )
		{
			// Nothing is computed, so nothing about how to compute is stored.
			return $definition;
		}

		$mode = $body['mode'] ?? ( $existing['mode'] ?? 'auto' );

		if ( ! RuleService::isValidMode( $mode ) )
		{
			throw new InvalidArgumentException( 'Unknown rule mode.' );
		}

		$algos = $body['algos'] ?? ( $existing['algos'] ?? [ $this->catalogue->default() ] );

		if ( ! is_array( $algos ) )
		{
			$algos = [ $algos ];
		}

		$algos = array_values(
			array_filter(
				$algos,
				fn(
					$algo,
				): bool => $this->catalogue->isValid( $algo ),
			),
		);

		if ( $algos === [] )
		{
			throw new InvalidArgumentException( 'At least one supported algorithm is required.' );
		}

		$definition['mode']  = $mode;
		$definition['algos'] = $algos;

		return $definition;
	}

	/**
	 * Validate an administrator-supplied selector, rejecting one whose
	 * target does not exist — otherwise the rule would sit in the list
	 * matching nothing, with no indication why.
	 *
	 * @throws InvalidArgumentException
	 */
	private function validatedSelector(
		array  $body,
		?array $existing,
	): string
	{
		$value = $body['selector']
			?? ( $existing !== null
				? RuleService::ruleSelector( $existing )
				             ->canonical()
				: 'home:*' );

		if ( ! is_string( $value ) || $value === '' )
		{
			throw new InvalidArgumentException( 'selector must be a non-empty string.' );
		}

		$selector = Selector::parse( $value );

		switch ( $selector->kind )
		{
		case Selector::KIND_GROUP:
			if ( ! $this->groupManager->groupExists( (string) $selector->target ) )
			{
				throw new InvalidArgumentException( 'Unknown group.' );
			}

			break;

		case Selector::KIND_USER:
			if ( ! $this->userManager->userExists( (string) $selector->target ) )
			{
				throw new InvalidArgumentException( 'Unknown user.' );
			}

			break;

		case Selector::KIND_GROUPFOLDER:
			if ( ! ctype_digit( (string) $selector->target ) )
			{
				throw new InvalidArgumentException( 'groupfolder: takes the numeric folder id.' );
			}

			break;
		}

		return $selector->canonical();
	}
}
