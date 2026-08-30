<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
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
		private readonly MetadataService   $metadataService,
		private readonly PermissionService $permissionService,
		private readonly IAppConfig        $appConfig,
		private readonly JobStatsService   $jobStats,
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
			'version'                => $this->statusService->getAppVersion(),
			'dbVersion'              => $this->statusService->getDbVersion(),
			'rowCount'               => $this->statusService->getHashRowCount(),
			'pendingStats'           => $this->metadataService->getPendingStats(),
			'staleStats'             => $this->metadataService->getStaleStats(),
			'jobs'                   => $this->jobStats->lastRuns(),
			'idleBannerAcknowledged' => $this->appConfig->getValueBool(
				Application::APP_ID,
				RuleService::CONFIG_KEY_IDLE_BANNER_ACK,
			),
		] );
	}


	/**
	 * Record that the administrator saw the idle banner and chose to leave
	 * automatic hashing off. The flag expires by itself: enabling an include
	 * rule clears it through the rules' single write gate
	 * ({@see RuleService::CONFIG_KEY_IDLE_BANNER_ACK}).
	 *
	 * @noinspection PhpUnused
	 */
	#[ApiRoute( verb: 'POST', url: '/settings/idle-banner/ack' )]
	public function acknowledgeIdleBanner(): DataResponse
	{

		$this->appConfig->setValueBool(
			Application::APP_ID,
			RuleService::CONFIG_KEY_IDLE_BANNER_ACK,
			true,
		);

		return new DataResponse( [ 'success' => true ] );
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
