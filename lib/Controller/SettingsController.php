<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\CronGenerateHashes;
use OCA\FileChecksumSearch\Service\CronJobService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TriggerInitializationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
		string                                        $appName,
		IRequest                                      $request,
		private readonly LoggerInterface              $logger,
		private readonly HashIndexService             $hashIndexService,
		private readonly TriggerInitializationService $triggerInitService,
		private readonly StatusService                $statusService,
		private readonly CronJobService               $cronJobService,
		private readonly IUserManager                 $userManager,
		private readonly IAppConfig                   $appConfig,
	) {

		parent::__construct( $appName, $request );
	}


	/**
	 * Display app status including version, row counts, and infrastructure state.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoCSRFRequired]
	public function getStatus(): DataResponse
	{

		return new DataResponse( [
			'version'     => $this->statusService->getAppVersion(),
			'dbVersion'   => $this->statusService->getDbVersion(),
			'rowCount'    => $this->statusService->getHashRowCount(),
			'pendingRows' => $this->statusService->getPendingRowCount(),
			'tables'      => $this->statusService->getTableStatus(),
			'sp'          => $this->statusService->getProcedureStatus(),
			'triggers'    => $this->statusService->getTriggerStatus(),
		] );
	}


	/**
	 * Run MariaDB version, TRIGGER privilege, and checksum column checks.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoCSRFRequired]
	public function runCompatibilityTest(): DataResponse
	{

		$issues = [];
		$checks = [];

		$dbVersion                = $this->statusService->getDbVersion();
		$checks['mariadbVersion'] = [
			'label' => 'MariaDB >= 10.2',
			'value' => $dbVersion,
			'pass'  => version_compare( $dbVersion, '10.2', '>=' ),
		];

		$hasTrigger            = $this->triggerInitService->checkTriggerPrivilege();
		$checks['triggerPriv'] = [
			'label' => 'TRIGGER privilege',
			'value' => $hasTrigger
				? 'Granted'
				: 'Missing',
			'pass'  => $hasTrigger,
		];

		$hasChecksum              = $this->statusService->hasChecksumColumn();
		$checks['checksumColumn'] = [
			'label' => 'filecache.checksum column',
			'value' => $hasChecksum
				? 'Exists'
				: 'Missing',
			'pass'  => $hasChecksum,
		];

		$allPass = ! in_array( false, array_column( $checks, 'pass' ), true );

		return new DataResponse( [
			'allPass' => $allPass,
			'checks'  => $checks,
			'issues'  => $issues,
		] );
	}


	/**
	 * Truncate the hash index table. Destructive — requires confirmation.
	 *
	 * @noinspection PhpUnused
	 */
	public function purgeIndex(): DataResponse
	{

		try
		{
			$result = $this->hashIndexService->purgeIndex();

			return new DataResponse(
				[
					'success' => true,
					'before'  => $result['before'],
					'after'   => $result['after'],
				],
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS SettingsController: purgeIndex failed',
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
	 * Repopulate the hash table from existing filecache checksums.
	 *
	 * @noinspection PhpUnused
	 */
	public function rebuildIndex(): DataResponse
	{

		try
		{
			$result = $this->hashIndexService->rebuildIndex();

			return new DataResponse(
				[
					'success'   => true,
					'total'     => $result['total'],
					'processed' => $result['processed'],
				],
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS SettingsController: rebuildIndex failed',
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
	 * Drop FCIAS triggers and stored procedure, preserving the hash table.
	 *
	 * @noinspection PhpUnused
	 */
	public function teardownTriggers(): DataResponse
	{

		try
		{
			$this->hashIndexService->teardownTriggers();

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS SettingsController: teardownTriggers failed',
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
	 * Drop the FCIAS hash table entirely. Requires teardown first.
	 *
	 * @noinspection PhpUnused
	 */
	public function removeTable(): DataResponse
	{

		try
		{
			$this->hashIndexService->removeTable();

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS SettingsController: removeTable failed',
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
	 * Create FCIAS triggers and stored procedure. Idempotent.
	 *
	 * @noinspection PhpUnused
	 */
	public function deployTriggers(): DataResponse
	{

		try
		{
			$this->hashIndexService->deployTriggers();

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS SettingsController: deployTriggers failed',
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
	 * Create the FCIAS hash table if it does not exist.
	 *
	 * @noinspection PhpUnused
	 */
	public function createTable(): DataResponse
	{

		try
		{
			$this->hashIndexService->createTable();

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS SettingsController: createTable failed',
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
	 * List cron job definitions, supported algorithms, and available users.
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
			'definitions'    => $this->cronJobService->listDefinitions(),
			'supportedAlgos' => HashIndexService::SUPPORTED_ALGOS,
			'users'          => $users,
		] );
	}


	/**
	 * Create or update a cron job definition.
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

		$algo = $body['algo'] ?? HashIndexService::getDefaultAlgo();

		if ( ! in_array( $algo, HashIndexService::SUPPORTED_ALGOS, true ) )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'Unsupported algorithm: ' . $algo,
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$allowedIntervals = [
			300,
			900,
			1800,
			3600,
		];
		$interval         = (int) ( $body['interval'] ?? 900 );

		if ( ! in_array( $interval, $allowedIntervals, true ) )
		{
			$interval = 900;
		}

		$batchSize = (int) ( $body['batchSize'] ?? 100 );

		if ( $batchSize < 1 )
		{
			$batchSize = 100;
		}

		$definition = [
			'_v'        => CronGenerateHashes::ARG_VERSION,
			'enabled'   => (bool) ( $body['enabled'] ?? true ),
			'userScope' => $body['userScope'] ?? 'all',
			'path'      => $body['path'] ?? '/',
			'algo'      => $algo,
			'batchSize' => $batchSize,
			'interval'  => $interval,
		];

		$existingId = $body['id'] ?? null;

		try
		{
			if ( $existingId !== null && $existingId !== '' )
			{
				$this->cronJobService->updateDefinition( $existingId, $definition );
			}
			else
			{
				$this->cronJobService->saveDefinition( $definition );
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
	 * Delete a cron job definition by ID.
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
			$this->cronJobService->deleteDefinition( $id );

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
	 * Enable or disable a cron job definition.
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
			$this->cronJobService->toggleDefinition( $id, $enabled );

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

		return sprintf( '*/%d * * * *', max( 1, $minutes ) );
	}


	/**
	 * Read current rehash behavior settings (write/create/delete).
	 *
	 * @noinspection PhpUnused
	 */
	#[NoCSRFRequired]
	public function getRehashBehavior(): DataResponse
	{

		return new DataResponse( [
			'write'  => $this->appConfig->getValueString(
				Application::APP_ID,
				'update_hash_on_file_write',
				'lazy',
			),
			'create' => $this->appConfig->getValueString(
				Application::APP_ID,
				'update_hash_on_file_create',
				'off',
			),
			'delete' => $this->appConfig->getValueString(
				Application::APP_ID,
				'update_hash_on_file_delete',
				'off',
			),
		] );
	}


	/**
	 * Save rehash behavior settings for write, create, and delete events.
	 *
	 * @noinspection PhpUnused
	 */
	public function saveRehashBehavior(): DataResponse
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

		$allowedWrite  = [
			'off',
			'force',
			'lazy',
			'auto',
		];
		$allowedCreate = [
			'off',
			'lazy',
			'force',
		];
		$allowedDelete = [
			'off',
			'on',
		];

		$write  = $body['write'] ?? null;
		$create = $body['create'] ?? null;
		$delete = $body['delete'] ?? null;

		$errors = [];

		if ( $write !== null )
		{
			if ( in_array( $write, $allowedWrite, true ) )
			{
				$this->appConfig->setValueString(
					Application::APP_ID,
					'update_hash_on_file_write',
					$write,
				);
			}
			else
			{
				$errors[] = 'Invalid value for write: ' . $write;
			}
		}

		if ( $create !== null )
		{
			if ( in_array( $create, $allowedCreate, true ) )
			{
				$this->appConfig->setValueString(
					Application::APP_ID,
					'update_hash_on_file_create',
					$create,
				);
			}
			else
			{
				$errors[] = 'Invalid value for create: ' . $create;
			}
		}

		if ( $delete !== null )
		{
			if ( in_array( $delete, $allowedDelete, true ) )
			{
				$this->appConfig->setValueString(
					Application::APP_ID,
					'update_hash_on_file_delete',
					$delete,
				);
			}
			else
			{
				$errors[] = 'Invalid value for delete: ' . $delete;
			}
		}

		if ( ! empty( $errors ) )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => implode( '; ', $errors ),
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		return new DataResponse( [ 'success' => true ] );
	}

}
