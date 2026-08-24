<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

class SettingsController
	extends
	Controller
{

	public function __construct(
		string                             $appName,
		IRequest                           $request,
		private readonly LoggerInterface   $logger,
		private readonly StatusService     $statusService,
		private readonly IUserManager      $userManager,
		private readonly RuleService       $ruleService,
		private readonly MetadataService   $metadataService,
		private readonly PermissionService $permissionService,
	) {

		parent::__construct( $appName, $request );
	}


	/**
	 * Display app status including version, row counts, and pending stats by mode.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/settings/status' )]
	public function getStatus(): DataResponse
	{

		return new DataResponse( [
			'version'      => $this->statusService->getAppVersion(),
			'dbVersion'    => $this->statusService->getDbVersion(),
			'rowCount'     => $this->statusService->getHashRowCount(),
			'pendingStats' => $this->metadataService->getPendingStats(),
		] );
	}


	/**
	 * List rule definitions, supported algorithms, and available users.
	 *
	 * Admin only — backs the admin settings page. Non-admin rule
	 * management goes through PersonalSettingsController's separate
	 * /personal/rules* routes instead.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/settings/cron/definitions' )]
	public function listRules(): DataResponse
	{

		$users = [];
		$this->userManager->callForAllUsers(
			function (
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

		return new DataResponse( [
			'definitions'    => $this->ruleService->listRulesFor( null ),
			'supportedAlgos' => HashCalculationService::SUPPORTED_ALGOS,
			'users'          => $users,
		] );
	}


	/**
	 * Create or update a rule definition.
	 *
	 * Admin only — see {@see listRules()}.
	 *
	 * @noinspection PhpUnused
	 */
	#[ApiRoute( verb: 'POST', url: '/settings/cron/save' )]
	public function saveRule(): DataResponse
	{

		$body = json_decode( $this->readRequestBody(), true );

		if ( ! is_array( $body ) )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'Invalid request body.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$type = $body['type'] ?? RuleService::TYPE_INCLUDE;

		if ( ! RuleService::isValidType( $type ) )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'Unknown rule type.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		// Only an include rule computes anything, so only an include rule
		// carries algorithms and a mode.
		if ( $type !== RuleService::TYPE_INCLUDE )
		{
			$definition = [
				'enabled'        => (bool) ( $body['enabled'] ?? true ),
				'type'           => $type,
				'path'           => $body['path'] ?? '/',
				'userScope'      => $body['userScope'] ?? 'all',
				'admin_enforced' => (bool) ( $body['admin_enforced'] ?? false ),
			];

			return $this->persistRule( $body['id'] ?? null, $definition );
		}

		$algos = $body['algos'] ?? [ HashCalculationService::getDefaultAlgo() ];

		if ( ! is_array( $algos ) )
		{
			$algos = [ $algos ];
		}

		$algos = array_values(
			array_filter(
				$algos,
				static fn(
					$a,
				): bool => HashCalculationService::isValidAlgo( $a ),
			),
		);

		if ( empty( $algos ) )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'At least one supported algorithm is required.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$definition = [
			'enabled'        => (bool) ( $body['enabled'] ?? true ),
			'type'           => RuleService::TYPE_INCLUDE,
			'mode'           => $body['mode'] ?? 'auto',
			'algos'          => $algos,
			'path'           => $body['path'] ?? '/',
			'userScope'      => $body['userScope'] ?? 'all',
			'admin_enforced' => (bool) ( $body['admin_enforced'] ?? false ),
		];

		// Marks the single catch-all default. RuleService keeps the
		// at-most-one invariant, so a stray flag cannot create a second.
		if ( ! empty( $body['pinned'] ) )
		{
			$definition['pinned'] = true;
		}

		return $this->persistRule( $body['id'] ?? null, $definition );
	}


	/**
	 * Create or update, shared by the include and non-include branches of
	 * {@see saveRule()}.
	 */
	private function persistRule(
		mixed $existingId,
		array $definition,
	): DataResponse {

		try
		{
			if ( $existingId !== null && $existingId !== '' )
			{
				$this->ruleService->ruleUpdate( (string) $existingId, $definition );
			}
			else
			{
				$this->ruleService->ruleAdd( $definition );
			}

			return new DataResponse( [
				'success'    => true,
				'definition' => $definition,
			] );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS SettingsController: saveRule failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[
					'success' => false,
					'error'   => $e->getMessage(),
				], Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	/**
	 * Delete a rule definition by ID.
	 *
	 * Admin only — see {@see listRules()}.
	 *
	 * @noinspection PhpUnused
	 */
	#[ApiRoute( verb: 'POST', url: '/settings/cron/delete' )]
	public function deleteRule(): DataResponse
	{

		$body = json_decode( $this->readRequestBody(), true );
		$id   = $body['id'] ?? null;

		if ( $id === null || $id === '' )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'Definition ID is required.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try
		{
			$this->ruleService->ruleDelete( $id );

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS SettingsController: deleteRule failed',
				[
					'app'       => Application::APP_ID,
					'id'        => $id,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[
					'success' => false,
					'error'   => $e->getMessage(),
				], Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	/**
	 * Enable or disable a rule definition.
	 *
	 * Admin only — see {@see listRules()}.
	 *
	 * @noinspection PhpUnused
	 */
	#[ApiRoute( verb: 'POST', url: '/settings/cron/toggle' )]
	public function toggleRule(): DataResponse
	{

		$body    = json_decode( $this->readRequestBody(), true );
		$id      = $body['id'] ?? null;
		$enabled = (bool) ( $body['enabled'] ?? false );

		if ( $id === null || $id === '' )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'Definition ID is required.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try
		{
			$this->ruleService->ruleToggle( $id, $enabled );

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS SettingsController: toggleRule failed',
				[
					'app'       => Application::APP_ID,
					'id'        => $id,
					'enabled'   => $enabled,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[
					'success' => false,
					'error'   => $e->getMessage(),
				], Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	/**
	 * Read the rule-editing permission options (admin only).
	 *
	 * @noinspection PhpUnused
	 */
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/settings/admin-options' )]
	public function getAdminOptions(): DataResponse
	{

		$users = [];
		$this->userManager->callForAllUsers(
			function (
				$user,
			) use
			(
				&
				$users,
			): void
			{

				$users[] = [
					'id'          => $user->getUID(),
					'displayName' => $user->getDisplayName(),
				];
			},
		);

		return new DataResponse( [
			'success'        => true,
			'allowAllUsers'  => $this->permissionService->isAllUsersEnabled(
				PermissionService::PERMISSION_RULE_EDITING,
			),
			'groups'         => $this->permissionService->getGroups( PermissionService::PERMISSION_RULE_EDITING ),
			'users'          => $this->permissionService->getUsers( PermissionService::PERMISSION_RULE_EDITING ),
			'availableUsers' => $users,
		] );
	}


	/**
	 * Persist the rule-editing permission options (admin only).
	 *
	 * @noinspection PhpUnused
	 */
	#[ApiRoute( verb: 'POST', url: '/settings/admin-options/save' )]
	public function saveAdminOptions(): DataResponse
	{

		$body = json_decode( $this->readRequestBody(), true );

		if ( ! is_array( $body ) )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'Invalid request body.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$allowAll = (bool) ( $body['allowAllUsers'] ?? false );
		$groups   = $body['groups'] ?? [];
		$users    = $body['users'] ?? [];

		if ( ! is_array( $groups ) )
		{
			$groups = [ $groups ];
		}

		if ( ! is_array( $users ) )
		{
			$users = [ $users ];
		}

		try
		{
			$this->permissionService->setAllUsersEnabled( PermissionService::PERMISSION_RULE_EDITING, $allowAll );
			$this->permissionService->setGroups( PermissionService::PERMISSION_RULE_EDITING, $groups );
			$this->permissionService->setUsers( PermissionService::PERMISSION_RULE_EDITING, $users );

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS SettingsController: saveAdminOptions failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[
					'success' => false,
					'error'   => $e->getMessage(),
				], Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	/**
	 * Read the raw HTTP request body.
	 *
	 * Protected so unit tests can mock it via
	 * getMockBuilder()->onlyMethods() — php://input is read-only
	 * in CLI mode where PHPUnit runs.
	 */
	protected function readRequestBody(): string
	{

		return file_get_contents( 'php://input' );
	}

}
