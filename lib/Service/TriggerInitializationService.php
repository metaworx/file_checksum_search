<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

readonly class TriggerInitializationService
{

	public function __construct(
		private IAppConfig       $appConfig,
		private LifecycleHandler $lifecycleHandler,
		private IDBConnection    $db,
		private TableNameService $tables,
		private LoggerInterface  $logger,
	) {
	}


	public function deployIfNeeded( string $appId ): void
	{

		if ( $this->appConfig->getValueBool( $appId, 'triggers_deployed' ) )
		{
			return;
		}

		// Set flag first to minimize race window during concurrent requests.
		// deployTriggers() is idempotent (DROP IF EXISTS before CREATE),
		// so redundant calls during the race window are harmless.
		$this->appConfig->setValueBool( $appId, 'triggers_deployed', true );
		$this->lifecycleHandler->deployTriggers();
	}


	public function markUndeployed( string $appId ): void
	{

		$this->appConfig->setValueBool( $appId, 'triggers_deployed', false );
	}


	/**
	 * Test whether the database user has the TRIGGER privilege
	 * by creating a real table, a trigger on it, then cleaning up.
	 */
	public function checkTriggerPrivilege(): bool
	{

		$prefix    = $this->tables->getPrefix();
		$tempTable = $prefix . 'fcias_priv_check';

		try
		{
			$this->db->executeStatement( "CREATE TABLE IF NOT EXISTS `{$tempTable}` (x INT)" );
			$this->db->executeStatement(
				"CREATE TRIGGER `{$tempTable}_t` BEFORE INSERT ON `{$tempTable}` FOR EACH ROW BEGIN END",
			);
			$this->db->executeStatement( "DROP TRIGGER `{$tempTable}_t`" );

			return true;
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS: trigger privilege check failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return false;
		}
		finally
		{
			$this->db->executeStatement( "DROP TABLE IF EXISTS `{$tempTable}`" );
		}
	}

}
