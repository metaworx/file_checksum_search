<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use Closure;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Creates the pending hash update queue table.
 *
 * file_checksum_search_pending is a transient queue — rows are inserted
 * by the FileListener on file write/create events and consumed by the
 * DrainPendingUpdates background job. The PRIMARY KEY on fileid provides
 * natural deduplication (INSERT IGNORE is a no-op for already-queued files).
 */
class Version010000Date20260805000000
	extends
	SimpleMigrationStep
{

	public function changeSchema(
		IOutput $output,
		Closure $schemaClosure,
		array   $options,
	): ?ISchemaWrapper {

		$logger = Server::get( LoggerInterface::class );

		$logger->info(
			'FCIAS Version010000Date20260805000000 migration: changeSchema running',
			[ 'app' => Application::APP_ID ],
		);

		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ( ! $schema->hasTable( 'file_checksum_search_pending' ) )
		{
			$table = $schema->createTable( 'file_checksum_search_pending' );

			$table->addColumn( 'fileid', 'bigint', [
				'notnull'  => true,
				'unsigned' => true,
			] );

			$table->addColumn( 'job_id', 'bigint', [
				'notnull'  => false,
				'unsigned' => true,
			] );

			$table->addColumn( 'created_at', 'integer', [
				'notnull'  => true,
				'unsigned' => true,
			] );

			$table->addColumn( 'event_type', 'string', [
				'notnull' => true,
				'length'  => 10,
			] );

			$table->setPrimaryKey( [ 'fileid' ] );

			return $schema;
		}

		return null;
	}

}
