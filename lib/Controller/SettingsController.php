<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
		string                                 $appName,
		IRequest                               $request,
		private readonly LoggerInterface       $logger,
		private readonly StatusService         $statusService,
		private readonly IUserManager          $userManager,
		private readonly RuleService           $ruleService,
		private readonly MetadataService       $metadataService,
	) {

		parent::__construct( $appName, $request );
	}


	/**
	 * Display app status including version, row counts, and pending stats by mode.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoCSRFRequired]
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
	 * @noinspection PhpUnused
	 */
	#[NoCSRFRequired]
	public function listJobDefinitions(): DataResponse
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
			'definitions'    => $this->ruleService->loadRules(),
			'supportedAlgos' => HashIndexService::SUPPORTED_ALGOS,
			'users'          => $users,
		] );
	}


	/**
	 * Create or update a rule definition.
	 *
	 * @noinspection PhpUnused
	 */
	public function saveJobDefinition(): DataResponse
	{

		$body = json_decode( file_get_contents( 'php://input' ), true );

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

		$algos = $body['algos'] ?? [ HashIndexService::getDefaultAlgo() ];

		if ( ! is_array( $algos ) )
		{
			$algos = [ $algos ];
		}

		$algos = array_values( array_filter(
			$algos,
			static fn( $a ): bool => is_string( $a ) && in_array( $a, HashIndexService::SUPPORTED_ALGOS, true ),
		) );

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
			'enabled'   => (bool) ( $body['enabled'] ?? true ),
			'mode'      => $body['mode'] ?? 'auto',
			'algos'     => $algos,
			'path'      => $body['path'] ?? '/',
			'userScope' => $body['userScope'] ?? 'all',
		];

		$existingId = $body['id'] ?? null;

		try
		{
			if ( $existingId !== null && $existingId !== '' )
			{
				$this->ruleService->ruleUpdate( $existingId, $definition );
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
				'FCIAS SettingsController: saveJobDefinition failed',
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
	 * @noinspection PhpUnused
	 */
	public function deleteJobDefinition(): DataResponse
	{

		$body = json_decode( file_get_contents( 'php://input' ), true );
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
				'FCIAS SettingsController: deleteJobDefinition failed',
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
	 * @noinspection PhpUnused
	 */
	public function toggleJobDefinition(): DataResponse
	{

		$body    = json_decode( file_get_contents( 'php://input' ), true );
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
				'FCIAS SettingsController: toggleJobDefinition failed',
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
	 * Generate a crontab entry snippet for CLI-based hash generation.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoCSRFRequired]
	public function getCrontabSnippet(): DataResponse
	{

		$userScope = $this->request->getParam( 'userScope', 'all' );
		$path      = $this->request->getParam( 'path', '/' );
		$algo      = $this->request->getParam( 'algo', HashIndexService::getDefaultAlgo() );
		$batchSize = (int) $this->request->getParam( 'batchSize', 100 );
		$interval  = (int) $this->request->getParam( 'interval', 900 );

		$occPath = isset( \OC::$SERVERROOT )
			? \OC::$SERVERROOT . '/occ'
			: '/var/www/nextcloud/occ';

		$intervalStr = $this->intervalToCron( $interval );

		$userArg = $userScope === 'all'
			? '--user=all'
			: '--user=' . escapeshellarg( $userScope );

		$pathArg  = $path !== '' && $path !== '/'
			? ' --path=' . escapeshellarg( $path )
			: '';
		$batchArg = $batchSize > 0
			? ' --batch-size=' . $batchSize
			: '';
		$algoArg  = ' --algo=' . escapeshellarg( $algo );

		$snippet = sprintf(
			'%s php %s file-checksum-search:generate %s%s%s%s',
			$intervalStr,
			$occPath,
			$userArg,
			$pathArg,
			$algoArg,
			$batchArg,
		);

		return new DataResponse( [ 'snippet' => $snippet ] );
	}


	private function intervalToCron( int $seconds ): string
	{

		$minutes = (int) round( $seconds / 60 );

		if ( $minutes >= 60 && $minutes % 60 === 0 )
		{
			$hours = $minutes / 60;

			if ( $hours >= 24 && $hours % 24 === 0 )
			{
				return sprintf( '0 */%d * * *', $hours / 24 );
			}

			return sprintf( '0 */%d * * *', $hours );
		}

		return sprintf( '*/%d * * *', max( 1, $minutes ) );
	}

}
