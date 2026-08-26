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
 * is trusted with scope, enforcement and pinning (occ always is; REST decides
 * per session; DI callers decide via their requesting-user parameter).
 * A non-administrator's rule is always their own and never enforced,
 * whatever the payload claims.
 */
readonly class RuleDefinitionValidator
{

	public function __construct(
		private IGroupManager $groupManager,
		private IUserManager  $userManager,
	) {
	}


	/**
	 * Build a stored rule definition from a request payload.
	 *
	 * @param  array       $body      The payload, transport-decoded
	 * @param  string      $userId    Who is asking ('cli' etc. for trusted
	 *                                non-session callers — only read when
	 *                                $isAdmin is false)
	 * @param  bool        $isAdmin   Whether the caller is trusted with
	 *                                scope, enforcement and pinning
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
	): array {

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
			'userScope'      => $isAdmin
				? $this->validatedScope( $body, $existing )
				: $userId,
			'admin_enforced' => $isAdmin
				&& ( $body['admin_enforced'] ?? $existing['admin_enforced'] ?? false ),
		];

		if ( $isAdmin && ! empty( $body['pinned'] ) )
		{
			$definition['pinned'] = true;
		}

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

		$algos = $body['algos'] ?? ( $existing['algos'] ?? [ HashCalculationService::getDefaultAlgo() ] );

		if ( ! is_array( $algos ) )
		{
			$algos = [ $algos ];
		}

		$algos = array_values(
			array_filter(
				$algos,
				static fn(
					$algo,
				): bool => HashCalculationService::isValidAlgo( $algo ),
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
	 * Validate an administrator-supplied scope, rejecting one that names a
	 * group or user that does not exist — otherwise the rule would sit in the
	 * list matching nothing, with no indication why.
	 *
	 * @throws InvalidArgumentException
	 */
	private function validatedScope(
		array  $body,
		?array $existing,
	): string {

		$scope = $body['userScope'] ?? ( $existing['userScope'] ?? RuleService::SCOPE_ALL );

		if ( ! is_string( $scope ) || $scope === '' )
		{
			throw new InvalidArgumentException( 'userScope must be a non-empty string.' );
		}

		switch ( RuleService::scopeKind( $scope ) )
		{
		case 'group':
			if ( ! $this->groupManager->groupExists( (string) RuleService::scopeGroupId( $scope ) ) )
			{
				throw new InvalidArgumentException( 'Unknown group.' );
			}

			break;

		case 'user':
			if ( ! $this->userManager->userExists( $scope ) )
			{
				throw new InvalidArgumentException( 'Unknown user.' );
			}

			break;
		}

		return $scope;
	}

}
