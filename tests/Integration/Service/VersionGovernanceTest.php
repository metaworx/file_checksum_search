<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\IRootFolder;
use OCP\Server;

/**
 * A version is governed by its file's rule, against the real filecache.
 *
 * The versions are made by hand where the versions app keeps them,
 * `files_versions/<path>.v<mtime>` beside `files/<path>`: what is under test
 * is how a row is read, not how it got there. A version in the trash, and
 * one whose file is gone, are governed by nothing.
 */
class VersionGovernanceTest
    extends
    DatabaseTestCase
{

//  private properties

	private static string $uid;

	/** @var array<string, int> */
	private static array $fileIds = [];

	private RuleService $ruleService;


//  static methods

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		[ self::$uid ] = self::makeAccount( 'fcias_versions' );

		$root = Server::get( IRootFolder::class )->getUserFolder( self::$uid );
		$home = $root->getParent();

		$files = [
			'file'    => $root->newFolder( 'Docs' )->newFile( 'a.txt', 'now' ),
			'version' => self::folderAt( $home, 'files_versions/Docs' )->newFile( 'a.txt.v1700000000', 'before' ),
			'orphan'  => self::folderAt( $home, 'files_versions' )->newFile( 'gone.txt.v1700000000', 'before' ),
			'trashed' => self::folderAt( $home, 'files_trashbin/versions' )->newFile( 'b.txt.v1700000000.d1700000100', 'before' ),
		];

		foreach ( $files as $key => $file )
		{
			self::$fileIds[ $key ] = $file->getId();
		}
	}


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
	}


//  other non-static methods

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAVersionsFileIsFoundBesideIt(): void
	{
		$filecache = Server::get( FilecacheService::class );
		$version   = $filecache->locate( self::$fileIds['version'] );

		$this->assertNotNull( $version );
		$this->assertSame( 1700000000, $version->version?->timestamp );
		$this->assertSame( self::$fileIds['file'], $filecache->filesOfVersions( [ $version ] )[ $version->fileId ]->fileId ?? null );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAVersionIsGovernedByItsFilesRule(): void
	{
		$this->rule( 'Other/**' );

		$this->assertNull( $this->ruleService->findFirstMatchingRule( self::$fileIds['version'] ), 'a rule elsewhere does not reach it' );

		$id = $this->rule( 'Docs/**' );

		$this->assertSame( $id, $this->ruleService->findFirstMatchingRule( self::$fileIds['version'] )['id'] ?? null );
		$this->assertSame( $id, $this->ruleService->governingRulesForFileIds( [ self::$fileIds['version'] ] )[ self::$fileIds['version'] ]['id'] ?? null );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testATrashedVersionWithoutARecordAndAnOrphanAreGovernedByNothing(): void
	{
		$this->rule( '**' );

		$rules = $this->ruleService->governingRulesForFileIds( [
			self::$fileIds['orphan'],
			self::$fileIds['trashed'],
		] );

		$this->assertNull( $rules[ self::$fileIds['orphan'] ], 'its file is gone' );
		$this->assertNull( $rules[ self::$fileIds['trashed'] ], 'its trashed file has no record of where it was' );
		$this->assertSame(
			'files_trashbin/files/b.txt.d1700000100',
			Server::get( FilecacheService::class )->locate( self::$fileIds['trashed'] )?->version?->fileInternalPath,
			'a version in the trash is its trashed file\'s',
		);
		$this->assertSame( FileLocation::NS_HOME, Server::get( FilecacheService::class )->locate( self::$fileIds['orphan'] )?->namespace );
	}

	/**
	 * An enabled include rule over the account's home: the instance's own
	 * are set aside for the test.
	 *
	 * @return string  Its id.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function rule( string $path ): string
	{
		return $this->ruleService->ruleAdd(
			[
				'enabled'  => true,
				'type'     => RuleService::TYPE_INCLUDE,
				'path'     => $path,
				'mode'     => MetadataService::PENDING_MODE_MISSING,
				'selector' => 'home:' . self::$uid,
				'algos'    => [ 'sha1' ],
			],
		);
	}
}
