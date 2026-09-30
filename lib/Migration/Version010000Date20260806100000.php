<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use Closure;
use OCA\FileChecksumSearch\BackgroundJob\FilecacheBackfill;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;

/**
 * Adds this app's indices to oc_files_metadata_index, then adopts the
 * checksums the filecache already holds.
 *
 * The backfill is why the migration has a postSchemaChange half at all: a
 * file whose hash Nextcloud computed on upload is already hashed, and
 * copying those values in is cheaper than recomputing them and kinder than
 * pretending the instance starts empty.
 *
 * @noinspection PhpUnused
 */
class Version010000Date20260806100000
    extends
    SimpleMigrationStep
{

//  constructor

	public function __construct(
		private readonly TableNameService $tableNameService,
	) {
	}


//  other non-static methods

	#[\Override]
	public function changeSchema(
		IOutput $output,
		Closure $schemaClosure,
		array   $options,
	): ?ISchemaWrapper
	{
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

	#[\Override]
	public function postSchemaChange(
		IOutput $output,
		Closure $schemaClosure,
		array   $options,
	): void
	{
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
				. 'Run "occ fcias:repair --step rebuild-from-filecache" once it has been created.',
			);

			return;
		}

		// Quiet start: the only work installing does to existing data is
		// copying checksums the filecache already carries — no content read,
		// nothing computed. Hashing begins when an administrator enables a
		// rule, not before. Queued rather than run here: this runs inside
		// the request that installed or enabled the app, and the copy reads
		// every filecache row with a checksum ({@see FilecacheBackfill}).
		FilecacheBackfill::queue( Server::get( IJobList::class ), Server::get( IAppConfig::class ) );

		$output->info( 'FCIAS: queued the copy of the filecache\'s checksums for the background jobs.' );
	}
}
