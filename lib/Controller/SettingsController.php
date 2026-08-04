<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TriggerInitializationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
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
	) {

		parent::__construct( $appName, $request );
	}


	#[NoCSRFRequired]
	public function getStatus(): DataResponse
	{

		return new DataResponse( [
			'version'    => $this->statusService->getAppVersion(),
			'dbVersion'  => $this->statusService->getDbVersion(),
			'rowCount'   => $this->statusService->getHashRowCount(),
			'triggersOk' => $this->statusService->getTriggerCount() >= 3,
			'spOk'       => $this->statusService->isSpInstalled(),
		] );
	}


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
			return new DataResponse(
				[
					'success' => false,
					'error'   => $e->getMessage(),
				], Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	public function teardownTriggers(): DataResponse
	{

		try
		{
			$this->hashIndexService->teardownTriggers();

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( Throwable $e )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => $e->getMessage(),
				], Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	public function removeTable(): DataResponse
	{

		try
		{
			$this->hashIndexService->removeTable();

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( Throwable $e )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => $e->getMessage(),
				], Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}

}
