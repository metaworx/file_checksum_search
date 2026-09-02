<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use InvalidArgumentException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\ApplyRuleJob;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\GroupFolderService;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\RuleDefinitionValidator;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJobList;
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
		string                                   $appName,
		IRequest                                 $request,
		private readonly RuleService             $ruleService,
		private readonly PermissionService       $permissionService,
		private readonly IUserSession            $userSession,
		private readonly IGroupManager           $groupManager,
		private readonly RuleDefinitionValidator $definitionValidator,
		private readonly IUserManager            $userManager,
		private readonly IJobList                $jobList,
		private readonly GroupFolderService      $groupFolderService,
		private readonly FilecacheService        $filecacheService,
		private readonly LoggerInterface         $logger,
		private readonly AlgorithmCatalogue      $catalogue,
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
			'supportedAlgos' => $this->catalogue->algorithms(),
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

			// Soft dependency: when the groupfolders app is missing the UI
			// gets an explicit "not available" rather than an empty list, so
			// it can drop the selector option instead of offering a picker
			// with nothing to pick.
			$payload['groupFoldersAvailable'] = $this->groupFolderService->isAvailable();
			$payload['groupFoldersLabel']     = $this->groupFolderService->appName();
			$payload['availableGroupFolders'] = $this->groupFolderService->listFolders();

			// The rest of the coverage view: storages only storage:<id> or
			// '*' can reach. A failure here costs the placeholder rows, not
			// the rule list — the page stays useful either way.
			try
			{
				$payload['availableStorages'] = $this->filecacheService->listAddressableStorages();
			}
			catch ( Throwable $e )
			{
				$this->logger->warning(
					'FCIAS: could not list addressable storages.',
					[
						'app'       => Application::APP_ID,
						'exception' => $e,
					],
				);

				$payload['availableStorages'] = [];
			}
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

		$context = $this->authorizeWrite();

		if ( $context instanceof DataResponse )
		{
			return $context;
		}

		[
			'userId'  => $userId,
			'isAdmin' => $isAdmin,
			'body'    => $body,
		]
			= $context;

		try
		{
			$definition = $this->definitionValidator->definitionFrom( $body, $userId, $isAdmin );
		}
		catch ( InvalidArgumentException $e )
		{
			return $this->badRequest( $e->getMessage() );
		}

		if ( ! $isAdmin
			&& ( $refusal = $this->ruleService->ruleTargetRefusal( $userId, $definition['path'] ) ) !== null )
		{
			return $this->forbidden( $refusal );
		}

		try
		{
			$id = $this->ruleService->ruleAdd( $definition, $userId );

			// The stored rule, not the payload: it carries the id the caller
			// needs in order to address the rule it just created, and the
			// selector in the canonical spelling the server settled on.
			return new DataResponse( [
				'success' => true,
				'rule'    => $this->ruleService->findRuleById( $id ) ?? $definition,
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
			$definition = $this->definitionValidator->definitionFrom( $body, $userId, $isAdmin, $existing );
		}
		catch ( InvalidArgumentException $e )
		{
			return $this->badRequest( $e->getMessage() );
		}

		if ( ! $isAdmin
			&& ( $refusal = $this->ruleService->ruleTargetRefusal( $userId, $definition['path'] ) ) !== null )
		{
			return $this->forbidden( $refusal );
		}

		try
		{
			$this->ruleService->ruleUpdate( $id, $definition, $userId );

			// As with create: echo what was stored, so a caller sending a
			// partial update sees the whole rule rather than its own fragment.
			return new DataResponse( [
				'success' => true,
				'rule'    => $this->ruleService->findRuleById( $id ) ?? $definition,
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

		if ( ! $this->mayMutate( $userId, $isAdmin, $existing ) )
		{
			return $this->forbidden();
		}

		try
		{
			$this->ruleService->ruleDelete( $id, $userId );

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( Throwable $e )
		{
			return $this->serverError( 'delete', $e );
		}
	}


	/**
	 * Queue a full apply pass for one rule: every file it currently governs
	 * is marked for background hashing, uncapped.
	 *
	 * Enqueues and returns immediately — the scan has no place inside an
	 * HTTP request. Applying is judged as writing (mayMutate), since it
	 * spends the same authority: deciding that work happens to the files
	 * this rule governs.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(
		verb: 'POST',
		url: '/api/v1/rules/{id}/apply',
		requirements: [ 'id' => '[0-9a-f]{32}' ],
	)]
	public function apply( string $id ): DataResponse
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

		try
		{
			// Refuse at submission time what the job could only fail on out
			// of sight: a disabled or non-include rule.
			RuleService::assertApplicable( $existing );
		}
		catch ( InvalidArgumentException $e )
		{
			return $this->badRequest( $e->getMessage() );
		}

		$this->jobList->add(
			ApplyRuleJob::class,
			[
				'ruleId' => $id,
				'actor'  => $userId,
			],
		);

		return new DataResponse(
			[
				'success' => true,
				'queued'  => true,
			],
		);
	}


	/**
	 * Reorder one segment partition.
	 *
	 * Priority is only meaningful within one selector's rules, so a reorder
	 * names the selector (and whether it targets the defaults partition) and
	 * submits that partition's IDs in full.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[ApiRoute( verb: 'PUT', url: '/api/v1/rules/order' )]
	public function reorder(): DataResponse
	{

		$context = $this->authorizeWrite();

		if ( $context instanceof DataResponse )
		{
			return $context;
		}

		[
			'userId'  => $userId,
			'isAdmin' => $isAdmin,
			'body'    => $body,
		]
			= $context;

		$selector   = $body['selector'] ?? null;
		$defaults   = (bool) ( $body['defaults'] ?? false );
		$orderedIds = $body['orderedIds'] ?? null;

		if ( ! is_string( $selector ) || $selector === '' )
		{
			return $this->badRequest( 'selector is required.' );
		}

		if ( ! is_array( $orderedIds ) )
		{
			return $this->badRequest( 'orderedIds is required and must be an array.' );
		}

		try
		{
			$this->ruleService->reorderSegment(
				$selector,
				$defaults,
				$orderedIds,
				$isAdmin
					? null
					: $userId,
				$userId,
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


	/**
	 * The checks create() and reorder() both open with: who is asking, may
	 * they write rules at all, and did they send a body we can read.
	 *
	 * update() and destroy() deliberately do not use this. They authorise
	 * against the rule being changed rather than against the caller alone —
	 * permission to write rules is not permission to write *that* rule — so
	 * sharing this prefix with them would invite the weaker check.
	 *
	 * @return array{userId: string, isAdmin: bool, body: array}|DataResponse
	 *         The caller's context, or the response to return as-is.
	 */
	private function authorizeWrite(): array|DataResponse
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

		return [
			'userId'  => $userId,
			'isAdmin' => $isAdmin,
			'body'    => $body,
		];
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
