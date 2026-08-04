<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Centralized status/health-check queries used by both the CLI status
 * command and the admin settings HTTP API.
 */
class StatusService
{

	public function __construct(
		private readonly IDBConnection    $db,
		private readonly TableNameService $tables,
		private readonly IAppManager      $appManager,
		private readonly LoggerInterface  $logger,
	) {
	}


	public function getAppVersion(): string
	{

		return $this->appManager->getAppVersion( Application::APP_ID );
	}


	public function getDbVersion(): string
	{

		$row = $this->db->executeQuery( 'SELECT VERSION() AS version' )
		                ->fetch()
		;

		return $row['version'] ?? 'unknown';
	}


	public function getHashRowCount(): int
	{

		$hashTable = $this->tables->getHashTableName();

		try
		{
			return (int) $this->db->executeQuery( "SELECT COUNT(*) FROM `{$hashTable}`" )
			                      ->fetchOne()
			;
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS: hash row count query failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return 0;
		}
	}


	public function getTriggerCount(): int
	{

		$prefix = $this->tables->getPrefix();

		try
		{
			return (int) $this->db->executeQuery(
				"SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE ?",
				[ $prefix . 't_fcias_after_%' ],
			)
			                      ->fetchOne()
			;
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS: trigger count query failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return 0;
		}
	}


	public function isSpInstalled(): bool
	{

		$prefix = $this->tables->getPrefix();

		try
		{
			$count = (int) $this->db->executeQuery(
				"SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ?",
				[ $prefix . 'fcias_parse_file_hashes' ],
			)
			                        ->fetchOne()
			;

			return $count > 0;
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS: SP check query failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return false;
		}
	}


	public function hasChecksumColumn(): bool
	{

		$prefix = $this->tables->getPrefix();

		try
		{
			$count = (int) $this->db->executeQuery(
				"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'checksum'",
				[ $prefix . 'filecache' ],
			)
			                        ->fetchOne()
			;

			return $count > 0;
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS: checksum column check failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return false;
		}
	}

}
