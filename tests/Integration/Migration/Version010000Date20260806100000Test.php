<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Migration;

use OCA\FileChecksumSearch\Migration\Version010000Date20260806100000;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Regression coverage for FCIAS Review §6, Finding 9:
 * postSchemaChange() used to seed the metadata index unconditionally,
 * even when changeSchema() had already no-opped because
 * files_metadata_index didn't exist — leaving the app permanently on
 * unindexed lookups with no automatic re-trigger.
 *
 * Runs against the real ddev Nextcloud database, where
 * files_metadata_index genuinely exists, so the "table missing" case
 * is exercised via a mocked ISchemaWrapper rather than a torn-down
 * table.
 */
class Version010000Date20260806100000Test
	extends
	DatabaseTestCase
{

	private Version010000Date20260806100000 $migration;


	protected function setUp(): void
	{

		parent::setUp();

		$this->migration = new Version010000Date20260806100000(
			Server::get( TableNameService::class ),
		);
	}


	public function testPostSchemaChangeSkipsSeedingWhenTableMissing(): void
	{

		$schema = $this->createMock( ISchemaWrapper::class );
		$schema->method( 'hasTable' )
		       ->with( 'files_metadata_index' )
		       ->willReturn( false )
		;

		$infoMessages = [];

		$output = $this->createMock( IOutput::class );
		$output->method( 'info' )
		       ->willReturnCallback(
			       function ( $message ) use ( &$infoMessages ): void
			       {

				       $infoMessages[] = $message;
			       },
		       )
		;
		$output->expects( $this->atLeastOnce() )
		       ->method( 'warning' )
		       ->with( $this->stringContains( 'occ file-checksum-search:rebuild' ) )
		;

		$this->migration->postSchemaChange(
			$output,
			fn () => $schema,
			[],
		);

		$this->assertEmpty(
			array_filter(
				$infoMessages,
				static fn ( $m ): bool => str_contains( (string) $m, 'seeding' ),
			),
			'Seeding must be skipped when files_metadata_index is missing.',
		);
	}


	public function testPostSchemaChangeSeedsWhenTableExists(): void
	{

		/** @var MockObject|IOutput $output */
		$output = $this->createMock( IOutput::class );
		$output->expects( $this->never() )
		       ->method( 'warning' )
		;

		$infoMessages = [];
		$output->method( 'info' )
		       ->willReturnCallback(
			       function ( $message ) use ( &$infoMessages ): void
			       {

				       $infoMessages[] = $message;
			       },
		       )
		;

		// files_metadata_index genuinely exists on this ddev instance;
		// the guard only cares about hasTable()'s answer, so mock it
		// rather than constructing a real ISchemaWrapper (only obtainable
		// from within an actual migration run).
		$schema = $this->createMock( ISchemaWrapper::class );
		$schema->method( 'hasTable' )
		       ->with( 'files_metadata_index' )
		       ->willReturn( true )
		;

		$this->migration->postSchemaChange(
			$output,
			fn () => $schema,
			[],
		);

		$this->assertNotEmpty(
			array_filter(
				$infoMessages,
				static fn ( $m ): bool => str_contains( (string) $m, 'seeding' ),
			),
			'Expected an "...seeding..." info message when the table exists.',
		);
	}

}
