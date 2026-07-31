<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the shadow table file_checksum_search_hashes.
 *
 * SP and triggers are managed at runtime by LifecycleHandler via
 * AppEnableEvent / AppDisableEvent listeners in Application.php.
 * They are NOT migration artifacts — the migration only creates the
 * table declaratively.
 */
class Version010000Date20260731000000
	extends
	SimpleMigrationStep
{

	public function changeSchema(
		IOutput  $output,
		\Closure $schemaClosure,
		array    $options,
	): ?ISchemaWrapper {

		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ( ! $schema->hasTable( 'file_checksum_search_hashes' ) )
		{
			$table = $schema->createTable( 'file_checksum_search_hashes' );

			$table->addColumn( 'fileid', 'bigint', [
				'notnull'  => true,
				'unsigned' => true,
			] );

			$table->addColumn( 'algo', 'string', [
				'notnull' => true,
				'length'  => 10,
			] );

			$table->addColumn( 'hash_value', 'string', [
				'notnull' => true,
				'length'  => 64,
			] );

			$table->setPrimaryKey(
				[
					'fileid',
					'algo',
				],
			);

			$table->addIndex(
				[
					'hash_value',
					'algo',
				],
				'idx_fcias_hash_lookup',
			);

			$table->addOption( 'collation', 'utf8mb4_bin' );

			return $schema;
		}

		return null;
	}


	public function postSchemaChange(
		IOutput  $output,
		\Closure $schemaClosure,
		array    $options,
	): void {

		// Deploy SP + triggers immediately after table creation.
		// This is idempotent and ensures the index is live without
		// waiting for the AppEnableEvent to fire.
		LifecycleHandler::deployTriggers();

		$output->info( 'FCIAS: shadow table created, SP and triggers deployed.' );
	}

}
