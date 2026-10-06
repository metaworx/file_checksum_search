<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OC\Files\Storage\Local;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Server;
use Throwable;

/**
 * Rules govern the files of an external storage.
 *
 * An external storage holds its files from its root, where a home holds
 * them below `files/`; read as a home's, none of its rows had a path a
 * rule could match, and neither `storage:<raw id>` nor `*` governed a file
 * on an SMB share or a local mount. A real local storage, scanned into the
 * filecache as Nextcloud scans one, and removed again afterwards.
 */
class ExternalStorageRulesTest
    extends
    DatabaseTestCase
{

//  private properties

	private RuleService $ruleService;

	private string      $dir;

	private Local       $storage;

	private int         $fileId;


//  getters / setters / is* / has*

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->ruleService = Server::get( RuleService::class );

		$this->preserveStoredRules();

		foreach ( $this->ruleService->loadRules() as $rule )
		{
			$this->ruleService->ruleDelete( (string) $rule['id'] );
		}

		$this->dir = sys_get_temp_dir() . '/fcias-ext-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir . '/2024', 0775, true );
		file_put_contents( $this->dir . '/2024/scan.txt', 'external ' . bin2hex( random_bytes( 8 ) ) );

		$this->storage = new Local( [ 'datadir' => $this->dir ] );
		$this->storage->getScanner()
		              ->scan( '' )
		;

		$this->fileId = (int) $this->storage->getCache()
		                                    ->getId( '2024/scan.txt' )
		;

		$this->assertGreaterThan( 0, $this->fileId, 'The scan put the file in the filecache.' );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		try
		{
			$numericId = $this->storage->getCache()
			                           ->getNumericStorageId()
			;

			foreach ( [ 'files_metadata_index', 'files_metadata' ] as $table )
			{
				$this->getRawConnection()
				     ->executeStatement( "DELETE FROM `*PREFIX*$table` WHERE `file_id` = ?", [ $this->fileId ] )
				;
			}

			$this->getRawConnection()
			     ->executeStatement( 'DELETE FROM `*PREFIX*filecache` WHERE `storage` = ?', [ $numericId ] )
			;
			$this->getRawConnection()
			     ->executeStatement( 'DELETE FROM `*PREFIX*storages` WHERE `numeric_id` = ?', [ $numericId ] )
			;
		}
		catch ( Throwable )
		{
		}

		@unlink( $this->dir . '/2024/scan.txt' );
		@rmdir( $this->dir . '/2024' );
		@rmdir( $this->dir );

		parent::tearDown();
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAStorageRuleGovernsAFileOnIt(): void
	{
		$location = $this->location();

		$this->assertSame( FileLocation::NS_OTHER, $location->namespace );
		$this->assertSame( '/2024/scan.txt', $location->relativePath );

		$id = $this->rule( 'storage:' . $this->storage->getId(), '2024/**' );

		$this->assertSame( $id, $this->ruleService->governingRuleForLocation( $location )['id'] ?? null );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheUniversalRuleGovernsItAndAGlobElsewhereDoesNot(): void
	{
		$this->rule( '*', 'other/**' );

		$this->assertNull(
			$this->ruleService->governingRuleForLocation( $this->location() ),
			'A glob for another folder does not reach it.',
		);

		$id = $this->rule( '*', '**' );

		$this->assertSame( $id, $this->ruleService->governingRuleForLocation( $this->location() )['id'] ?? null );
	}

	/**
	 * The sweep reaches it too, and queues it: what a rule governs but no
	 * sweep visits is never hashed.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheRulesSweepQueuesIt(): void
	{
		$id = $this->rule( 'storage:' . $this->storage->getId(), '**' );

		$rule = array_values( array_filter(
			$this->ruleService->loadRules(),
			static fn ( array $rule ): bool => $rule['id'] === $id,
		) )[0];

		$this->assertGreaterThanOrEqual( 1, $this->ruleService->processRule( $rule )['marked'] );
		$this->assertSame(
			MetadataService::PENDING_PREFIX . MetadataService::PENDING_MODE_MISSING,
			Server::get( MetadataService::class )->getMarker( $this->fileId ),
		);
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function location(): FileLocation
	{
		$location = Server::get( FilecacheService::class )->locate( $this->fileId );

		$this->assertNotNull( $location );

		return $location;
	}

	/**
	 * An enabled include rule, ahead of none: the instance's own are set
	 * aside for the test.
	 *
	 * @return string  Its id.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function rule(
		string $selector,
		string $path,
	): string
	{
		return $this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_INCLUDE,
				'path'     => $path,
				'mode'     => MetadataService::PENDING_MODE_MISSING,
				'selector' => $selector,
				'algos'    => [ 'sha1' ],
			],
		);
	}
}
