<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
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
		private readonly AlgorithmCatalogue $catalogue,
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
	#[ApiRoute( verb: 'GET', url: '/settings/global' )]
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
			// The catalogue, both halves: what is in force, and what this PHP
			// build could offer if allowed. The picker shows the second and
			// marks the first; the default picker offers the first.
			'allowedAlgorithms'   => $this->catalogue->algorithms(),
			'availableAlgorithms' => $this->catalogue->available(),
			'defaultAlgorithm'    => $this->catalogue->default(),
		] );
	}


	/**
	 * Persist the rule-editing permission options (admin only).
	 *
	 * @noinspection PhpUnused
	 */
	#[ApiRoute( verb: 'PUT', url: '/settings/global' )]
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

		// Every field is optional and an absent one is left as it is: the
		// page has more than one section saving to this endpoint, and a
		// section must be able to save its own part without resetting the
		// others to defaults it never showed.
		$allowAll   = array_key_exists( 'allowAllUsers', $body ) ? (bool) $body['allowAllUsers'] : null;
		$groups     = $this->listOrNull( $body, 'groups' );
		$users      = $this->listOrNull( $body, 'users' );
		// A list that would leave nothing in force is refused rather than
		// silently replaced by the default, because the administrator asked
		// for something and should hear that it could not be done.
		$algorithms = $this->listOrNull( $body, 'allowedAlgorithms' );
		// Applied after the allowlist when both are sent, so a default from a
		// list that is being widened in the same request is accepted.
		$default    = array_key_exists( 'defaultAlgorithm', $body ) ? $body['defaultAlgorithm'] : null;

		try
		{
			if ( $allowAll !== null )
			{
				$this->permissionService->setAllUsersEnabled( PermissionService::PERMISSION_RULE_EDITING, $allowAll );
			}

			if ( $groups !== null )
			{
				$this->permissionService->setGroups( PermissionService::PERMISSION_RULE_EDITING, $groups );
			}

			if ( $users !== null )
			{
				$this->permissionService->setUsers( PermissionService::PERMISSION_RULE_EDITING, $users );
			}

			if ( $algorithms !== null )
			{
				$kept = $this->catalogue->setAllowlist( $algorithms );

				if ( $kept === [] )
				{
					return new DataResponse(
						[
							'success' => false,
							'error'   => 'None of the requested algorithms is available on this server; the previous list is kept.',
						],
						Http::STATUS_BAD_REQUEST,
					);
				}
			}

			if ( $default !== null && ( ! is_string( $default ) || ! $this->catalogue->setDefault( $default ) ) )
			{
				return new DataResponse(
					[
						'success' => false,
						'error'   => 'The default must be one of the allowed algorithms.',
					],
					Http::STATUS_BAD_REQUEST,
				);
			}

			return new DataResponse( [
				'success'           => true,
				'allowedAlgorithms' => $this->catalogue->algorithms(),
				'defaultAlgorithm'  => $this->catalogue->default(),
			] );
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


	/**
	 * A body field as a list, or null when the field is absent. A scalar is
	 * one entry, so a client sending a single value is not sending nothing.
	 *
	 * @return list<mixed>|null
	 */
	private function listOrNull(
		array  $body,
		string $key,
	): ?array {

		if ( ! array_key_exists( $key, $body ) )
		{
			return null;
		}

		return is_array( $body[ $key ] )
			? array_values( $body[ $key ] )
			: [ $body[ $key ] ];
	}

}
