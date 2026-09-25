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
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The settings pages' own endpoints: instance status, permissions, and the
 * algorithm catalogue.
 *
 * Deliberately not part of the public API. These serve two Vue pages and are
 * free to change with them, which is why they are absent from the OpenAPI
 * spec while everything under /api/v1 is in it. Every route here is
 * administrator-only unless its method says otherwise.
 */
class SettingsController
    extends
    Controller
{

//  constructor

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
	)
	{
		parent::__construct( $appName, $request );
	}


//  getters / setters / is* / has*

	/**
	 * Display app status including version, row counts, and pending stats by mode.
	 *
	 * @noinspection PhpUnused
	 */
	// Admin-only: the status describes the instance — its database version,
	// how much it holds, its hashing backlog, its jobs — and only the admin
	// page reads it. No #[NoAdminRequired].
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


//  other non-static methods

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
			function(
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

		// Every permission, keyed, in one shape: the page has one section
		// per key and one component behind all of them, and the endpoint
		// loops rather than naming any.
		$permissions = [];

		foreach ( PermissionService::keys() as $key )
		{
			$permissions[ $key ] = [
				'allowAll' => $this->permissionService->isAllUsersEnabled( $key ),
				'groups'   => $this->permissionService->getGroups( $key ),
				'users'    => $this->permissionService->getUsers( $key ),
			];
		}

		return new DataResponse( [
			'success'        => true,
			'permissions'    => $permissions,
			'availableUsers' => $users,
			// The catalogue, both halves: what is in force, and what this PHP
			// build could offer if allowed. The picker shows the second and
			// marks the first; the default picker offers the first.
			'allowedAlgorithms'   => $this->catalogue->algorithms(),
			'availableAlgorithms' => $this->catalogue->available(),
			'defaultAlgorithm'    => $this->catalogue->default(),
			// Tunables, shown under Advanced beside the diagnostics.
			'crossAccountPrefillLimit' => $this->appConfig->getValueInt(
				Application::APP_ID,
				ConfigLexicon::CROSS_ACCOUNT_PREFILL_LIMIT,
			),
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
		// `permissions` is a map of key => {allowAll?, groups?, users?}; each
		// key and each field inside it is optional, for the same reason.
		$permissions = is_array( $body['permissions'] ?? null ) ? $body['permissions'] : [];
		// A list that would leave nothing in force is refused rather than
		// silently replaced by the default, because the administrator asked
		// for something and should hear that it could not be done.
		$algorithms = $this->listOrNull( $body, 'allowedAlgorithms' );
		// Applied after the allowlist when both are sent, so a default from a
		// list that is being widened in the same request is accepted.
		$default    = array_key_exists( 'defaultAlgorithm', $body ) ? $body['defaultAlgorithm'] : null;

		try
		{
			foreach ( PermissionService::keys() as $key )
			{
				$sent = $permissions[ $key ] ?? null;

				if ( ! is_array( $sent ) )
				{
					continue;
				}

				if ( array_key_exists( 'allowAll', $sent ) )
				{
					$this->permissionService->setAllUsersEnabled( $key, (bool) $sent['allowAll'] );
				}

				$groups = $this->listOrNull( $sent, 'groups' );

				if ( $groups !== null )
				{
					$this->permissionService->setGroups( $key, $groups );
				}

				$users = $this->listOrNull( $sent, 'users' );

				if ( $users !== null )
				{
					$this->permissionService->setUsers( $key, $users );
				}
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

			if ( array_key_exists( 'crossAccountPrefillLimit', $body ) )
			{
				// One is meaningless and a picker of thousands is not a
				// picker; the bounds are the control's, not the caller's.
				$this->appConfig->setValueInt(
					Application::APP_ID,
					ConfigLexicon::CROSS_ACCOUNT_PREFILL_LIMIT,
					max( 5, min( 500, (int) $body['crossAccountPrefillLimit'] ) ),
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
	): ?array
	{
		if ( ! array_key_exists( $key, $body ) )
		{
			return null;
		}

		return is_array( $body[ $key ] )
			? array_values( $body[ $key ] )
			: [ $body[ $key ] ];
	}
}
