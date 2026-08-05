<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use Closure;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adds updated_at DATETIME column to file_checksum_search_hashes.
 *
 * The column records when a hash was last computed and enables
 * smart skip logic in HashCalculationService (compare updated_at
 * against file mtime to avoid unnecessary recomputation).
 */
class Version010001Date20260805125000
	extends
	SimpleMigrationStep
{

	public function changeSchema(
		IOutput $output,
		Closure $schemaClosure,
		array   $options,
	): ?ISchemaWrapper {

		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable( 'file_checksum_search_hashes' );

		if ( ! $table->hasColumn( 'updated_at' ) )
		{
			$table->addColumn( 'updated_at', 'datetime', [
				'notnull' => false,
			] );

			return $schema;
		}

		return null;
	}


	public function postSchemaChange(
		IOutput $output,
		Closure $schemaClosure,
		array   $options,
	): void {

		$logger = Server::get( LoggerInterface::class );
		$db     = Server::get( IDBConnection::class );
		$tables = Server::get( TableNameService::class );
		$appId  = Application::APP_ID;

		$logger->info(
			'FCIAS Version010001Date20260805125000 migration: postSchemaChange running',
			[ 'app' => $appId ],
		);

		// Backfill existing rows with current timestamp.
		// Uses the query builder for cross-DB portability.
		$hashTable = $tables->getHashTableName();
		$now       = date( 'Y-m-d H:i:s' );

		$output->debug( 'FCIAS migration: backfilling updated_at column …' );

		try
		{
			$qb = $db->getQueryBuilder();
			$qb->automaticTablePrefix( false );
			$qb->update( $hashTable )
			   ->set( 'updated_at', $qb->createNamedParameter( $now, IQueryBuilder::PARAM_STR ) )
			   ->where(
				   $qb->expr()
				      ->isNull( 'updated_at' ),
			   )
			;

			$updated = $qb->executeStatement();

			$output->info(
				sprintf( 'FCIAS: backfilled updated_at for %d existing hash rows.', $updated ),
			);

			$logger->debug(
				'FCIAS migration: updated_at backfill completed',
				[
					'app'  => $appId,
					'rows' => $updated,
				],
			);
		}
		catch ( Throwable $e )
		{
			$output->warning( 'FCIAS: updated_at backfill failed: ' . $e->getMessage() );
			$logger->error(
				'FCIAS migration postSchemaChange backfill ERROR: ' . $e->getMessage(),
				[
					'app'       => $appId,
					'exception' => $e,
				],
			);
		}

		// Deploy SP + triggers with updated_at support.
		// This is idempotent and ensures the updated SP is live immediately.
		$output->debug( 'FCIAS migration: deploying triggers via LifecycleHandler …' );

		try
		{
			Server::get( LifecycleHandler::class )
			      ->deployTriggers()
			;

			$output->info( 'FCIAS: updated_at column added, SP and triggers deployed.' );
			$logger->debug(
				'FCIAS migration postSchemaChange: deployTriggers() succeeded',
				[ 'app' => $appId ],
			);
		}
		catch ( Throwable $e )
		{
			$output->warning( 'FCIAS: deployTriggers() failed: ' . $e->getMessage() );
			$logger->error(
				'FCIAS migration postSchemaChange ERROR: ' . $e->getMessage(),
				[
					'app'       => $appId,
					'exception' => $e,
				],
			);
		}
	}

}
