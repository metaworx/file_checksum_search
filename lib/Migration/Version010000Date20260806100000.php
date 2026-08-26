<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use Closure;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;

/**
 * Adds custom indices on oc_files_metadata_index for FCIAS queries
 * and seeds file-checksum-updated_at index entries for existing files.
 *
 * @noinspection PhpUnused
 */
class Version010000Date20260806100000
	extends
	SimpleMigrationStep
{

	public function __construct(
		private readonly TableNameService $tableNameService,
	) {
	}


	public function changeSchema(
		IOutput $output,
		Closure $schemaClosure,
		array   $options,
	): ?ISchemaWrapper {

		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$prefix = $this->tableNameService->getPrefix();

		if ( $schema->hasTable( 'files_metadata_index' ) )
		{
			$table = $schema->getTable( 'files_metadata_index' );

			if ( ! $table->hasIndex( $prefix . 'fcias_f_metadata_str_idx' ) )
			{
				$table->addIndex(
					[
						'meta_key',
						'meta_value_string',
						'file_id',
					],
					$prefix . 'fcias_f_metadata_str_idx',
				);
			}

			if ( ! $table->hasIndex( $prefix . 'fcias_f_metadata_int_idx' ) )
			{
				$table->addIndex(
					[
						'meta_key',
						'meta_value_int',
						'file_id',
					],
					$prefix . 'fcias_f_metadata_int_idx',
				);
			}

			return $schema;
		}

		return null;
	}


	public function postSchemaChange(
		IOutput $output,
		Closure $schemaClosure,
		array   $options,
	): void {

		$output->info( 'FCIAS: registering metadata keys ...' );

		Server::get( MetadataService::class )
		      ->register()
		;

		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// changeSchema() already no-ops the composite indices when this
		// table is missing; the backfill writes into it, so check the same
		// precondition here instead of letting those INSERTs fail. Without
		// this guard, an unlucky core/app migration ordering would leave
		// the app permanently on unindexed lookups with no automatic
		// re-trigger.
		if ( ! $schema->hasTable( 'files_metadata_index' ) )
		{
			$output->warning(
				'FCIAS: files_metadata_index does not exist yet — skipping the checksum backfill. '
				. 'Run "occ file-checksum-search:rebuild" once it has been created.',
			);

			return;
		}

		// Quiet start: the only work installing does to existing data is
		// copying checksums the filecache already carries — searchable
		// immediately, no content read, nothing computed. Hashing begins
		// when an administrator enables a rule, not before.
		$output->info( 'FCIAS: backfilling checksums from the filecache ...' );

		$backfilled = Server::get( HashIndexService::class )
		                    ->backfillFromFilecache()
		;

		$output->info(
			sprintf(
				'FCIAS: backfilled %d hashes for %d files.',
				$backfilled['hashes'],
				$backfilled['files'],
			),
		);
	}

}
