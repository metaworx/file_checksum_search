<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use InvalidArgumentException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hash-generation rules — one resource for both settings pages.
 *
 * What a caller may do is decided here, not by which URL they came through.
 * The admin and personal pages used to be separate controllers, so an
 * administrator on the personal page was silently limited by the route they
 * happened to hit. Capability now comes from who is asking.
 *
 * The one thing the caller still chooses is the *view*: `?scope=own` (the
 * default) lists the rules that concern their own files and marks only their
 * own as editable, while `?scope=all` is the administrator's whole-instance
 * view. That is what lets the personal page stay a personal page even for an
 * administrator — like `sudo`, the capability exists but is not exercised
 * unless asked for. The distinction is deliberately confined to listing:
 * mutations are judged on capability alone, so the API never depends on a
 * client honouring it.
 *
 * @noinspection PhpUnused
 */
class RulesController
	extends
	ApiController
{

// constants
	private const SCOPE_OWN = 'own';

	private const SCOPE_ALL = 'all';


	public function __construct(
		string                             $appName,
		IRequest                           $request,
		private readonly RuleService       $ruleService,
		private readonly PermissionService $permissionService,
		private readonly IUserSession      $userSession,
		private readonly IGroupManager     $groupManager,
		private readonly IUserManager      $userManager,
		private readonly LoggerInterface   $logger,
	) {

		parent::__construct( $appName, $request );
	}


	/**
	 * List rules, in evaluation order.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/api/v1/rules' )]
	public function index(): DataResponse
	{

		$userId = $this->currentUserId();

		if ( $userId === null )
		{
			return $this->unauthorized();
		}

		$isAdmin  = $this->groupManager->isAdmin( $userId );
		$scope    = (string) ( $this->request->getParam( 'scope', self::SCOPE_OWN ) );
		$wantsAll = $scope === self::SCOPE_ALL;

		if ( $wantsAll && ! $isAdmin )
		{
			return $this->forbidden( 'Listing every rule requires administrator rights.' );
		}

		if ( ! $wantsAll && $scope !== self::SCOPE_OWN )
		{
			return $this->badRequest( 'scope must be "own" or "all".' );
		}

		$payload = [
			'success'        => true,
			'rules'          => $this->ruleService->listRulesFor(
				$wantsAll
					? null
					: $userId,
			),
			'canCreate'      => $isAdmin || $this->permissionService->canUserEditRules( $userId ),
			'supportedAlgos' => HashCalculationService::SUPPORTED_ALGOS,
			'modes'          => RuleService::MODES,
			'types'          => RuleService::TYPES,
		];

		// Pickers for scopes only an administrator can assign — and only in
		// the administrator's view. In their own view an administrator can
		// still only create rules for themselves, so offering a user or group
		// picker there would advertise a capability that view does not have.
		if ( $wantsAll )
		{
			$payload['availableUsers']  = $this->allUserIds();
			$payload['availableGroups'] = $this->allGroupIds();
		}

		return new DataResponse( $payload );
	}


	/**
	 * Create a rule.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[ApiRoute( verb: 'POST', url: '/api/v1/rules' )]
	public function create(): DataResponse
	{

		$userId = $this->currentUserId();

		if ( $userId === null )
		{
			return $this->unauthorized();
		}

		$isAdmin = $this->groupManager->isAdmin( $userId );

		if ( ! $isAdmin && ! $this->permissionService->canUserEditRules( $userId ) )
		{
			return $this->forbidden();
		}

		$body = $this->decodeBody();

		if ( $body === null )
		{
			return $this->badRequest( 'Invalid request body.' );
		}

		try
		{
			$definition = $this->definitionFrom( $body, $userId, $isAdmin );
		}
		catch ( InvalidArgumentException $e )
		{
			return $this->badRequest( $e->getMessage() );
		}

		if ( ! $isAdmin
			&& ! $this->ruleService->isPathWritableByUser( $userId, $definition['path'] ) )
		{
			return $this->forbidden( 'The selected path is not write-accessible to you.' );
		}

		try
		{
			$this->ruleService->ruleAdd( $definition );

			return new DataResponse( [
				'success' => true,
				'rule'    => $definition,
			] );
		}
		catch ( Throwable $e )
		{
			return $this->serverError( 'create', $e );
		}
	}


	/**
	 * Update a rule. Enabling or disabling one is an update of `enabled`;
	 * there is no separate toggle endpoint.
	 *
	 * The `{id}` requirement pins the 32-hex form {@see RuleService::ruleAdd()}
	 * generates, so this route cannot swallow `/rules/order`.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[ApiRoute(
		verb: 'PUT',
		url: '/api/v1/rules/{id}',
		requirements: [ 'id' => '[0-9a-f]{32}' ],
	)]
	public function update( string $id ): DataResponse
	{

		$userId = $this->currentUserId();

		if ( $userId === null )
		{
			return $this->unauthorized();
		}

		$isAdmin  = $this->groupManager->isAdmin( $userId );
		$existing = $this->ruleService->findRuleById( $id );

		if ( $existing === null )
		{
			return $this->notFound();
		}

		if ( ! $this->mayMutate( $userId, $isAdmin, $existing ) )
		{
			return $this->forbidden();
		}

		$body = $this->decodeBody();

		if ( $body === null )
		{
			return $this->badRequest( 'Invalid request body.' );
		}

		try
		{
			$definition = $this->definitionFrom( $body, $userId, $isAdmin, $existing );
		}
		catch ( InvalidArgumentException $e )
		{
			return $this->badRequest( $e->getMessage() );
		}

		if ( ! $isAdmin
			&& ! $this->ruleService->isPathWritableByUser( $userId, $definition['path'] ) )
		{
			return $this->forbidden( 'The selected path is not write-accessible to you.' );
		}

		try
		{
			$this->ruleService->ruleUpdate( $id, $definition );

			return new DataResponse( [
				'success' => true,
				'rule'    => $definition,
			] );
		}
		catch ( Throwable $e )
		{
			return $this->serverError( 'update', $e );
		}
	}


	/**
	 * Delete a rule.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[ApiRoute(
		verb: 'DELETE',
		url: '/api/v1/rules/{id}',
		requirements: [ 'id' => '[0-9a-f]{32}' ],
	)]
	public function destroy( string $id ): DataResponse
	{

		$userId = $this->currentUserId();

		if ( $userId === null )
		{
			return $this->unauthorized();
		}

		$isAdmin  = $this->groupManager->isAdmin( $userId );
		$existing = $this->ruleService->findRuleById( $id );

		if ( $existing === null )
		{
			return $this->notFound();
		}

		if ( ! empty( $existing['pinned'] ) )
		{
			return $this->badRequest(
				'The catch-all default rule cannot be deleted — disable it instead.',
			);
		}

		if ( ! $this->mayMutate( $userId, $isAdmin, $existing ) )
		{
			return $this->forbidden();
		}

		try
		{
			$this->ruleService->ruleDelete( $id );

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( Throwable $e )
		{
			return $this->serverError( 'delete', $e );
		}
	}


	/**
	 * Reorder one band.
	 *
	 * Priority is only meaningful within a band, so a reorder names the band
	 * it applies to and submits that band's IDs in full.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[ApiRoute( verb: 'PUT', url: '/api/v1/rules/order' )]
	public function reorder(): DataResponse
	{

		$userId = $this->currentUserId();

		if ( $userId === null )
		{
			return $this->unauthorized();
		}

		$isAdmin = $this->groupManager->isAdmin( $userId );

		if ( ! $isAdmin && ! $this->permissionService->canUserEditRules( $userId ) )
		{
			return $this->forbidden();
		}

		$body = $this->decodeBody();

		if ( $body === null )
		{
			return $this->badRequest( 'Invalid request body.' );
		}

		$band       = $body['band'] ?? null;
		$orderedIds = $body['orderedIds'] ?? null;

		if ( ! is_int( $band ) )
		{
			return $this->badRequest( 'band is required and must be an integer.' );
		}

		if ( ! is_array( $orderedIds ) )
		{
			return $this->badRequest( 'orderedIds is required and must be an array.' );
		}

		try
		{
			$this->ruleService->reorderBand(
				$band,
				is_string( $body['ownerId'] ?? null )
					? $body['ownerId']
					: null,
				$orderedIds,
				$isAdmin
					? null
					: $userId,
			);

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( InvalidArgumentException $e )
		{
			return $this->badRequest( $e->getMessage() );
		}
		catch ( Throwable $e )
		{
			return $this->serverError( 'reorder', $e );
		}
	}


	/**
	 * Build a stored rule definition from a request body.
	 *
	 * Scope and the enforced flag are never taken from a non-administrator:
	 * their rules are always their own and never enforced, whatever the
	 * payload claims. `pinned` is administrator-only and additionally held to
	 * an at-most-one invariant inside {@see RuleService}.
	 *
	 * @throws InvalidArgumentException on anything the caller may not express
	 */
	private function definitionFrom(
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


	/**
	 * Whether this caller may change this rule.
	 */
	private function mayMutate(
		string $userId,
		bool   $isAdmin,
		array  $rule,
	): bool {

		if ( $isAdmin )
		{
			return true;
		}

		return $this->permissionService->canUserEditRules( $userId )
			&& $this->ruleService->canUserMutateRule( $userId, $rule );
	}


	/**
	 * @return string[]
	 */
	private function allUserIds(): array
	{

		$users = [];

		$this->userManager->callForAllUsers(
			static function (
				$user,
			) use
			(
				&
				$users,
			): void
			{

				$users[] = $user->getUID();
			},
		);

		return $users;
	}


	/**
	 * @return string[]
	 */
	private function allGroupIds(): array
	{

		return array_values(
			array_map(
				static fn(
					$group,
				): string => $group->getGID(),
				$this->groupManager->search( '' ),
			),
		);
	}


	private function decodeBody(): ?array
	{

		$body = json_decode( $this->readRequestBody(), true );

		return is_array( $body )
			? $body
			: null;
	}


	/**
	 * Read the raw HTTP request body.
	 *
	 * Protected so unit tests can mock it — php://input is read-only in the
	 * CLI mode where PHPUnit runs.
	 */
	protected function readRequestBody(): string
	{

		return file_get_contents( 'php://input' );
	}


	private function currentUserId(): ?string
	{

		return $this->userSession->getUser()
		                         ?->getUID()
		;
	}


	private function unauthorized(): DataResponse
	{

		return new DataResponse(
			[
				'success' => false,
				'error'   => 'Not logged in.',
			],
			Http::STATUS_UNAUTHORIZED,
		);
	}


	private function forbidden( string $message = 'You are not allowed to manage this rule.' ): DataResponse
	{

		return new DataResponse(
			[
				'success' => false,
				'error'   => $message,
			],
			Http::STATUS_FORBIDDEN,
		);
	}


	private function notFound(): DataResponse
	{

		return new DataResponse(
			[
				'success' => false,
				'error'   => 'Rule not found.',
			],
			Http::STATUS_NOT_FOUND,
		);
	}


	private function badRequest( string $message ): DataResponse
	{

		return new DataResponse(
			[
				'success' => false,
				'error'   => $message,
			],
			Http::STATUS_BAD_REQUEST,
		);
	}


	private function serverError(
		string    $operation,
		Throwable $e,
	): DataResponse {

		$this->logger->error(
			'FCIAS RulesController: ' . $operation . ' failed',
			[
				'app'       => Application::APP_ID,
				'exception' => $e,
			],
		);

		return new DataResponse(
			[
				'success' => false,
				'error'   => $e->getMessage(),
			],
			Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}

}
