<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Server;
use Throwable;

class SettingsController
	extends
	Controller
{

	private IDBConnection $db;


	public function __construct(
		string        $appName,
		IRequest      $request,
		IDBConnection $db,
	) {

		parent::__construct( $appName, $request );
		$this->db = $db;
	}


	#[NoCSRFRequired]
	public function getStatus(): DataResponse
	{

		$prefix    = $this->db->getPrefix();
		$hashTable = $prefix . 'file_checksum_search_hashes';

		$version   = Server::get( IAppManager::class )
		                   ->getAppVersion( 'file_checksum_search' )
		;
		$dbVersion = $this->db->executeQuery( 'SELECT VERSION() AS version' )
		                      ->fetch()['version'] ?? 'unknown';

		$rowCount = 0;
		try
		{
			$rowCount = (int) $this->db->executeQuery( "SELECT COUNT(*) FROM `{$hashTable}`" )
			                           ->fetchOne()
			;
		}
		catch ( Throwable )
		{
		}

		$triggersOk = false;
		try
		{
			$cnt        = (int) $this->db->executeQuery(
				"SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE ?",
				[ $prefix . 't_fcias_after_%' ],
			)
			                             ->fetchOne()
			;
			$triggersOk = $cnt >= 3;
		}
		catch ( Throwable )
		{
		}

		$spOk = false;
		try
		{
			$cnt  = (int) $this->db->executeQuery(
				"SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ?",
				[ $prefix . 'fcias_parse_file_hashes' ],
			)
			                       ->fetchOne()
			;
			$spOk = $cnt > 0;
		}
		catch ( Throwable )
		{
		}

		return new DataResponse( [
			'version'    => $version,
			'dbVersion'  => $dbVersion,
			'rowCount'   => $rowCount,
			'triggersOk' => $triggersOk,
			'spOk'       => $spOk,
		] );
	}


	#[NoCSRFRequired]
	public function runCompatibilityTest(): DataResponse
	{

		$prefix = $this->db->getPrefix();
		$issues = [];
		$checks = [];

		// MariaDB >= 10.2
		$dbVersion                = $this->db->executeQuery( 'SELECT VERSION() AS version' )
		                                     ->fetch()['version'] ?? '0';
		$checks['mariadbVersion'] = [
			'label' => 'MariaDB >= 10.2',
			'value' => $dbVersion,
			'pass'  => version_compare( $dbVersion, '10.2', '>=' ),
		];

		// TRIGGER privilege
		$hasTrigger = false;
		try
		{
			$tempTable = $prefix . 'fcias_comp_check';
			$this->db->executeStatement( "CREATE TEMPORARY TABLE IF NOT EXISTS `{$tempTable}` (x INT)" );
			$this->db->executeStatement(
				"CREATE TRIGGER `{$tempTable}_t` BEFORE INSERT ON `{$tempTable}` FOR EACH ROW BEGIN END",
			);
			$this->db->executeStatement( "DROP TRIGGER `{$tempTable}_t`" );
			$this->db->executeStatement( "DROP TEMPORARY TABLE `{$tempTable}`" );
			$hasTrigger = true;
		}
		catch ( Throwable )
		{
		}
		$checks['triggerPriv'] = [
			'label' => 'TRIGGER privilege',
			'value' => $hasTrigger
				? 'Granted'
				: 'Missing',
			'pass'  => $hasTrigger,
		];

		// filecache.checksum column
		$hasChecksum = false;
		try
		{
			$colCount    = $this->db->executeQuery(
				"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'checksum'",
				[ $prefix . 'filecache' ],
			)
			                        ->fetchOne()
			;
			$hasChecksum = $colCount > 0;
		}
		catch ( Throwable )
		{
		}
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

		$prefix    = $this->db->getPrefix();
		$hashTable = $prefix . 'file_checksum_search_hashes';

		try
		{
			$before = (int) $this->db->executeQuery( "SELECT COUNT(*) FROM `{$hashTable}`" )
			                         ->fetchOne()
			;
			$this->db->executeStatement( "TRUNCATE TABLE `{$hashTable}`" );

			return new DataResponse(
				[
					'success' => true,
					'before'  => $before,
					'after'   => 0,
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


	public function rebuildIndex(): DataResponse
	{

		$prefix = $this->db->getPrefix();
		$spName = $prefix . 'fcias_parse_file_hashes';

		try
		{
			$countQb = $this->db->getQueryBuilder();
			$countQb->select(
				$countQb->func()
				        ->count( '*', 'total' ),
			)
			        ->from( 'filecache' )
			        ->where(
				        $countQb->expr()
				                ->isNotNull( 'checksum' ),
				        $countQb->expr()
				                ->neq( 'checksum', $countQb->createNamedParameter( '' ) ),
			        )
			;
			$total = (int) $countQb->executeQuery()
			                       ->fetchOne()
			;

			$selectQb = $this->db->getQueryBuilder();
			$selectQb->select( 'fileid', 'checksum' )
			         ->from( 'filecache' )
			         ->where(
				         $selectQb->expr()
				                  ->isNotNull( 'checksum' ),
				         $selectQb->expr()
				                  ->neq( 'checksum', $selectQb->createNamedParameter( '' ) ),
			         )
			;

			$rows      = $selectQb->executeQuery();
			$processed = 0;
			$statement = $this->db->prepare( "CALL `{$spName}`(?, ?)" );

			while ( ( $row = $rows->fetch() ) !== false )
			{
				$statement->execute(
					[
						(int) $row['fileid'],
						$row['checksum'],
					],
				);
				$processed ++;
			}
			$rows->closeCursor();

			return new DataResponse(
				[
					'success'   => true,
					'total'     => $total,
					'processed' => $processed,
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
			LifecycleHandler::stripTriggers();

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
			LifecycleHandler::purgeShadowTable();

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
