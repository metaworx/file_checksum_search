<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use OCP\IDBConnection;
use OCP\Server;

/**
 * Manages database SP/trigger lifecycle via NC app events.
 *
 * SP and triggers are dynamic — they exist only while the app is enabled.
 * The shadow table persists across enable/disable cycles and is only
 * dropped on explicit uninstall (occ app:remove).
 */
class LifecycleHandler
{

	/**
	 * Deploy stored procedure + 3 triggers. Idempotent — safe to call
	 * multiple times (uses DROP IF EXISTS before each CREATE).
	 *
	 * Called by: migration postSchemaChange, AppEnableEvent listener.
	 */
	public static function deployTriggers(): void
	{

		$db        = Server::get( IDBConnection::class );
		$prefix    = $db->getPrefix();
		$fcTable   = $prefix . 'filecache';
		$hashTable = $prefix . 'file_checksum_search_hashes';
		$spName    = $prefix . 'fcias_parse_file_hashes';

		// Idempotency: drop before create
		$db->executeStatement( "DROP PROCEDURE IF EXISTS `{$spName}`" );
		$db->executeStatement( "DROP TRIGGER IF EXISTS `{$prefix}t_fcias_after_insert`" );
		$db->executeStatement( "DROP TRIGGER IF EXISTS `{$prefix}t_fcias_after_update`" );
		$db->executeStatement( "DROP TRIGGER IF EXISTS `{$prefix}t_fcias_after_delete`" );

		// Stored procedure: parse space-delimited algo:hash pairs
		$createSp = <<<SQL
CREATE PROCEDURE `{$spName}`(IN p_fileid BIGINT, IN p_checksum TEXT)
BEGIN
    DECLARE v_pos INT;
    DECLARE v_pair VARCHAR(150);
    DECLARE v_colon INT;
    DECLARE v_algo VARCHAR(10);
    DECLARE v_hash VARCHAR(64);

    DELETE FROM `{$hashTable}` WHERE `fileid` = p_fileid;

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
                INSERT INTO `{$hashTable}` (`fileid`, `algo`, `hash_value`)
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
		$db->executeStatement( $createSp );

		// Trigger: AFTER INSERT on filecache
		$db->executeStatement(
			<<<SQL
CREATE TRIGGER `{$prefix}t_fcias_after_insert`
AFTER INSERT ON `{$fcTable}`
FOR EACH ROW
BEGIN
    IF NEW.checksum IS NOT NULL AND NEW.checksum != '' THEN
        CALL `{$spName}`(NEW.fileid, NEW.checksum);
    END IF;
END
SQL,
		);

		// Trigger: AFTER UPDATE on filecache
		$db->executeStatement(
			<<<SQL
CREATE TRIGGER `{$prefix}t_fcias_after_update`
AFTER UPDATE ON `{$fcTable}`
FOR EACH ROW
BEGIN
    IF COALESCE(NEW.checksum, '') != COALESCE(OLD.checksum, '') THEN
        CALL `{$spName}`(NEW.fileid, NEW.checksum);
    END IF;
END
SQL,
		);

		// Trigger: AFTER DELETE on filecache
		$db->executeStatement(
			<<<SQL
CREATE TRIGGER `{$prefix}t_fcias_after_delete`
AFTER DELETE ON `{$fcTable}`
FOR EACH ROW
BEGIN
    DELETE FROM `{$hashTable}` WHERE `fileid` = OLD.fileid;
END
SQL,
		);
	}


	/**
	 * Remove SP + triggers. Preserves shadow table + data.
	 *
	 * Called by: AppDisableEvent listener, Teardown CLI command,
	 * admin settings teardown button.
	 */
	public static function stripTriggers(): void
	{

		$db     = Server::get( IDBConnection::class );
		$prefix = $db->getPrefix();

		$db->executeStatement( "DROP TRIGGER IF EXISTS `{$prefix}t_fcias_after_insert`" );
		$db->executeStatement( "DROP TRIGGER IF EXISTS `{$prefix}t_fcias_after_update`" );
		$db->executeStatement( "DROP TRIGGER IF EXISTS `{$prefix}t_fcias_after_delete`" );
		$db->executeStatement( "DROP PROCEDURE IF EXISTS `{$prefix}fcias_parse_file_hashes`" );
	}


	/**
	 * Full cleanup: strip triggers + drop shadow table.
	 *
	 * Called by: registerUninstallHandler, RemoveTable CLI command,
	 * admin settings remove-table button.
	 */
	public static function purgeShadowTable(): void
	{

		self::stripTriggers();

		$db     = Server::get( IDBConnection::class );
		$prefix = $db->getPrefix();

		$db->executeStatement( "DROP TABLE IF EXISTS `{$prefix}file_checksum_search_hashes`" );
	}

}
