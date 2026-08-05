<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Manages database SP/trigger lifecycle via NC app events.
 *
 * SP and triggers are dynamic — they exist only while the app is enabled.
 * The shadow table persists across enable/disable cycles and is only
 * dropped on explicit uninstall (occ app:remove).
 */
class LifecycleHandler
{

	private string $prefix;

	private string $fcTable;

	private string $hashTable;

	private string $spName;


	public function __construct(
		private IDBConnection    $db,
		private TableNameService $tables,
		private LoggerInterface  $logger,
	) {

		$this->prefix    = $this->tables->getPrefix();
		$this->fcTable   = $this->tables->getFilecacheTableName();
		$this->hashTable = $this->tables->getHashTableName();
		$this->spName    = $this->tables->getSpName();
	}


	/**
	 * Deploy stored procedure + 3 triggers. Idempotent — safe to call
	 * multiple times (uses DROP IF EXISTS before each CREATE).
	 *
	 * Called by: migration postSchemaChange, AppEnableEvent listener.
	 */
	public function deployTriggers(): void
	{

		$this->logger->debug(
			'FCIAS: deployTriggers() called',
			[
				'app' => Application::APP_ID,
			],
		);

		// Idempotency: drop before create
		$this->db->executeStatement( "DROP PROCEDURE IF EXISTS `{$this->spName}`" );
		$this->db->executeStatement( "DROP TRIGGER IF EXISTS `{$this->prefix}t_fcias_after_insert`" );
		$this->db->executeStatement( "DROP TRIGGER IF EXISTS `{$this->prefix}t_fcias_after_update`" );
		$this->db->executeStatement( "DROP TRIGGER IF EXISTS `{$this->prefix}t_fcias_after_delete`" );

		$this->logger->debug(
			'FCIAS: deploying SP',
			[
				'app' => Application::APP_ID,
				'sp'  => $this->spName,
			],
		);

		// Stored procedure: parse space-delimited algo:hash pairs
		$createSp = <<<SQL
CREATE PROCEDURE `{$this->spName}`(IN p_fileid BIGINT, IN p_checksum TEXT)
BEGIN
    DECLARE v_pos INT;
    DECLARE v_pair VARCHAR(150);
    DECLARE v_colon INT;
    DECLARE v_algo VARCHAR(10);
    DECLARE v_hash VARCHAR(64);

    DELETE FROM `{$this->hashTable}` WHERE `fileid` = p_fileid;

    IF p_checksum IS NOT NULL AND p_checksum != '' THEN
        parse_loop: LOOP
            SET v_pos = LOCATE(' ', p_checksum);
            IF v_pos > 0 THEN
                SET v_pair = SUBSTRING(p_checksum, 1, v_pos - 1);
                SET p_checksum = SUBSTRING(p_checksum, v_pos + 1);
            ELSE
                SET v_pair = p_checksum;
            END IF;

            SET v_colon = LOCATE(':', v_pair);
            IF v_colon > 0 THEN
                SET v_algo = SUBSTRING(v_pair, 1, v_colon - 1);
                SET v_hash = SUBSTRING(v_pair, v_colon + 1);
                INSERT INTO `{$this->hashTable}` (`fileid`, `algo`, `hash_value`)
                VALUES (p_fileid, v_algo, v_hash)
                ON DUPLICATE KEY UPDATE `hash_value` = VALUES(`hash_value`);
            END IF;

            IF v_pos = 0 THEN
                LEAVE parse_loop;
            END IF;
        END LOOP parse_loop;
    END IF;
END
SQL;
		$this->db->executeStatement( $createSp );

		$this->logger->debug( 'FCIAS: SP created, deploying triggers', [
			'app' => Application::APP_ID,
		] );

		// Trigger: AFTER INSERT on filecache
		$this->db->executeStatement(
			<<<SQL
CREATE TRIGGER `{$this->prefix}t_fcias_after_insert`
AFTER INSERT ON `{$this->fcTable}`
FOR EACH ROW
BEGIN
    IF NEW.checksum IS NOT NULL AND NEW.checksum != '' THEN
        CALL `{$this->spName}`(NEW.fileid, NEW.checksum);
    END IF;
END
SQL,
		);

		// Trigger: AFTER UPDATE on filecache
		$this->db->executeStatement(
			<<<SQL
CREATE TRIGGER `{$this->prefix}t_fcias_after_update`
AFTER UPDATE ON `{$this->fcTable}`
FOR EACH ROW
BEGIN
    IF COALESCE(NEW.checksum, '') != COALESCE(OLD.checksum, '') THEN
        CALL `{$this->spName}`(NEW.fileid, NEW.checksum);
    END IF;
END
SQL,
		);

		// Trigger: AFTER DELETE on filecache
		$this->db->executeStatement(
			<<<SQL
CREATE TRIGGER `{$this->prefix}t_fcias_after_delete`
AFTER DELETE ON `{$this->fcTable}`
FOR EACH ROW
BEGIN
    DELETE FROM `{$this->hashTable}` WHERE `fileid` = OLD.fileid;
END
SQL,
		);

		$this->logger->debug( 'FCIAS: deployTriggers() completed successfully', [
			'app' => Application::APP_ID,
		] );
	}


	/**
	 * Remove SP + triggers. Preserves shadow table + data.
	 *
	 * Called by: AppDisableEvent listener, Teardown CLI command,
	 * admin settings teardown button.
	 */
	public function stripTriggers(): void
	{

		$this->logger->debug(
			'FCIAS: stripTriggers() called',
			[
				'app' => Application::APP_ID,
			],
		);

		$this->db->executeStatement( "DROP TRIGGER IF EXISTS `{$this->prefix}t_fcias_after_insert`" );
		$this->db->executeStatement( "DROP TRIGGER IF EXISTS `{$this->prefix}t_fcias_after_update`" );
		$this->db->executeStatement( "DROP TRIGGER IF EXISTS `{$this->prefix}t_fcias_after_delete`" );
		$this->db->executeStatement( "DROP PROCEDURE IF EXISTS `{$this->spName}`" );

		$this->logger->debug( 'FCIAS: stripTriggers() completed successfully', [
			'app' => Application::APP_ID,
		] );
	}


	/**
	 * Full cleanup: strip triggers + drop both shadow tables.
	 *
	 * Called by: RemoveTable CLI command, admin settings remove-table button.
	 */
	public function purgeShadowTable(): void
	{

		$this->stripTriggers();

		$pendingTable = $this->tables->getPendingTableName();

		$this->logger->warning(
			'FCIAS: dropping tables',
			[
				'app'          => Application::APP_ID,
				'hashTable'    => $this->hashTable,
				'pendingTable' => $pendingTable,
			],
		);

		$this->db->executeStatement( "DROP TABLE IF EXISTS `{$this->hashTable}`" );
		$this->db->executeStatement( "DROP TABLE IF EXISTS `{$pendingTable}`" );
	}


	/**
	 * Create both shadow tables if they do not exist.
	 *
	 * Mirrors the schema from migrations.
	 */
	public function createTables(): void
	{

		$pendingTable = $this->tables->getPendingTableName();

		$this->db->executeStatement(
			<<<SQL
CREATE TABLE IF NOT EXISTS `{$this->hashTable}` (
	   `fileid`     BIGINT UNSIGNED NOT NULL,
	   `algo`       VARCHAR(10) NOT NULL,
	   `hash_value` VARCHAR(64) NOT NULL,
	   PRIMARY KEY (`fileid`, `algo`),
	   INDEX `idx_fcias_hash_lookup` (`hash_value`, `algo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
SQL,
		);

		$this->db->executeStatement(
			<<<SQL
CREATE TABLE IF NOT EXISTS `{$pendingTable}` (
	   `fileid`     BIGINT UNSIGNED NOT NULL,
	   `job_id`     BIGINT UNSIGNED DEFAULT NULL,
	   `created_at` INT UNSIGNED NOT NULL,
	   `event_type` VARCHAR(10) NOT NULL,
	   PRIMARY KEY (`fileid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
SQL,
		);

		$this->logger->debug(
			'FCIAS: createTables completed',
			[
				'app'          => Application::APP_ID,
				'hashTable'    => $this->hashTable,
				'pendingTable' => $pendingTable,
			],
		);
	}

}
