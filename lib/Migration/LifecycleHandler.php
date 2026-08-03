<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\IConfig;
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
		private IDBConnection   $db,
		private IConfig         $config,
		private LoggerInterface $logger,
	) {

		$this->prefix    = $this->config->getSystemValue( 'dbprefix', 'oc_' );
		$this->fcTable   = $this->prefix . 'filecache';
		$this->hashTable = $this->prefix . 'file_checksum_search_hashes';
		$this->spName    = $this->prefix . 'fcias_parse_file_hashes';
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
	 * Full cleanup: strip triggers + drop shadow table.
	 *
	 * Called by: RemoveTable CLI command, admin settings remove-table button.
	 */
	public function purgeShadowTable(): void
	{

		$this->stripTriggers();

		$this->logger->warning( "FCIAS: dropping table $this->hashTable", [
			'app' => Application::APP_ID,
		] );

		$this->db->executeStatement( "DROP TABLE IF EXISTS `{$this->hashTable}`" );
	}

}
