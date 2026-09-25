<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\FileLocation;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\Server;

/**
 * Where a real file says it lives, and which rule therefore governs it.
 *
 * The unit tests for this classification feed it storage ids as strings,
 * which is a test of the parsing and not of the premise: that these are
 * the shapes Nextcloud actually stores. A group folder is the case that
 * cannot be checked any other way — it has *two* storage layouts, one
 * per-folder jail and one legacy root-jail, and which one an instance
 * uses is a fact about the instance rather than about this app.
 *
 * Group folders also have no owner. `getOwner()` on their storage
 * answers "whoever is looking", which is exactly why identity here is
 * storage-based, and exactly the thing a mocked row cannot demonstrate.
 *
 * Skipped where the groupfolders app is not installed: it is a soft
 * dependency, and a suite that fails without it would be asserting that
 * an optional app is mandatory.
 */
class FileLocationIntegrationTest
    extends
    DatabaseTestCase
{

//  private properties

	private FilecacheService $filecache;

	private RuleService      $ruleService;

	private int              $groupFolderId;

	private string           $groupFolderMount;

	/** @var list<callable(): void> */
	private array            $cleanup = [];


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		if ( ! Server::get( IAppManager::class )
		             ->isEnabledForAnyone( 'groupfolders' ) )
		{
			$this->markTestSkipped( 'FCIAS integration: the groupfolders app is not enabled here.' );
		}

		$this->filecache   = Server::get( FilecacheService::class );
		$this->ruleService = Server::get( RuleService::class );

		$this->preserveStoredRules();

		[
			$this->groupFolderId,
			$this->groupFolderMount,
		]
			 = $this->aGroupFolderAdminCanSee();
	}


//  other non-static methods

	protected function tearDown(): void
	{
		foreach ( array_reverse( $this->cleanup ) as $undo )
		{
			try
			{
				$undo();
			}
			catch ( NotFoundException )
			{
				// A fixture that is already gone is the outcome we wanted.
				// Anything else propagates: a cleanup that fails quietly
				// left three files per run in the group folder.
			}
		}

		$this->cleanup = [];

		parent::tearDown();
	}

	public function testAFileInAGroupFolderIsClassifiedByItsJailRatherThanItsViewer(): void
	{
		$location = $this->locateAFileInTheGroupFolder();

		$this->assertSame( FileLocation::NS_GROUPFOLDER, $location->namespace );
		$this->assertSame( $this->groupFolderId, $location->groupFolderId );

		// The property the whole namespace exists for: a group folder is
		// nobody's home, so asking who owns it has no answer worth acting
		// on. A rule addressing it names the folder, not a person.
		$this->assertNull( $location->owner );

		// And the path is relative to the jail, not to whoever is looking at
		// it — the same file has as many view paths as it has members.
		$this->assertNotNull( $location->relativePath );
		$this->assertStringNotContainsString( '__groupfolders', $location->relativePath );
	}

	public function testAGroupFolderRuleGovernsItAndAHomeRuleDoesNot(): void
	{
		$location = $this->locateAFileInTheGroupFolder();

		$this->givenRules( [
			[
				'id'       => 'gf_rule',
				'enabled'  => true,
				'type'     => 'include',
				'path'     => '**',
				'mode'     => 'auto',
				'algos'    => [ 'sha1' ],
				'selector' => 'groupfolder:' . $this->groupFolderId,
			],
		] );

		$this->assertSame(
			'gf_rule',
			$this->ruleService->governingRuleForLocation( $location )['id'] ?? null,
		);

		// `home:*` reaches every home folder and nothing else. A group
		// folder is not in anybody's home, so the rule that looks like it
		// covers everything covers this not at all — which is the whole
		// reason the shipped defaults are two rules and not one.
		$this->givenRules( [
			[
				'id'       => 'home_rule',
				'enabled'  => true,
				'type'     => 'include',
				'path'     => '**',
				'mode'     => 'auto',
				'algos'    => [ 'sha1' ],
				'selector' => 'home:*',
			],
		] );

		$this->assertNull( $this->ruleService->governingRuleForLocation( $location ) );
	}

	public function testTheUniversalSelectorReachesWhatHomeCannot(): void
	{
		$location = $this->locateAFileInTheGroupFolder();

		$this->givenRules( [
			[
				'id'       => 'everything',
				'enabled'  => true,
				'type'     => 'include',
				'path'     => '**',
				'mode'     => 'auto',
				'algos'    => [ 'sha1' ],
				'selector' => '*',
			],
		] );

		// This is what makes the band-8 default the deliberate switch it is:
		// enabling it reaches group folders and external storage, which the
		// band-7 one never does.
		$this->assertSame(
			'everything',
			$this->ruleService->governingRuleForLocation( $location )['id'] ?? null,
		);
	}

	// ─── helpers ─────────────────────────────────────────────────────
	/**
	 * A group folder the admin account has mounted, with the name it is
	 * mounted under.
	 *
	 * Both halves are needed: the id is what a `groupfolder:<id>` rule
	 * names, and the mount point is the only way to reach the folder's
	 * files — they are written through a member's view, never through the
	 * jail path, which is not in anybody's tree.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function aGroupFolderAdminCanSee(): array
	{
		$result = $this->getRawConnection()
		               ->executeQuery(
			               'SELECT `folder_id`, `mount_point` FROM `*PREFIX*group_folders` ORDER BY `folder_id`',
		               )
		;

		$folders = $result->fetchAllAssociative();
		$result->free();

		if ( $folders === [] )
		{
			$this->markTestSkipped( 'FCIAS integration: the instance has no group folder to test against.' );
		}

		$userFolder = Server::get( IRootFolder::class )
		                    ->getUserFolder( 'admin' )
		;

		foreach ( $folders as $folder )
		{
			if ( $userFolder->nodeExists( (string) $folder['mount_point'] ) )
			{
				return [
					(int) $folder['folder_id'],
					(string) $folder['mount_point'],
				];
			}
		}

		$this->markTestSkipped(
			'FCIAS integration: the admin account has no group folder mounted, so there is no '
			. 'members-eye view to write a test file through.',
		);
	}

	/**
	 * A real file inside the group folder's jail, located the way the app
	 * locates one.
	 */
	private function locateAFileInTheGroupFolder(): FileLocation
	{
		$name = 'fcias_gf_' . bin2hex( random_bytes( 4 ) ) . '.txt';

		// Written through a member's view, which is the only way in — and
		// the point: the view path is one of as many as the folder has
		// members, while the row underneath names the jail. That gap is what
		// this whole namespace exists to close.
		$folder = Server::get( IRootFolder::class )
		                ->getUserFolder( 'admin' )
		                ->get( $this->groupFolderMount )
		;
		$file   = $folder->newFile( $name, 'FCIAS group folder integration content' );

		$this->cleanup[] = static fn (): mixed => self::removeThroughTheStorage( $file );

		$location = $this->filecache->locate( $file->getId() );

		$this->assertNotNull( $location, 'the file this test just wrote can be located' );

		return $location;
	}


//  static methods

	/**
	 * Remove a file the test wrote, past the mount's permission mask.
	 *
	 * `$file->delete()` goes through the member's view, and the harness
	 * grants the administrator's group no delete on the folder (7: read,
	 * update, create) — which is a fine thing to test against and a bad
	 * way to clean up. The storage has no mask: the file goes from disk
	 * and its row from the cache, as a scan would do.
	 */
	private static function removeThroughTheStorage( File $file ): void
	{
		$storage  = $file->getStorage();
		$internal = $file->getInternalPath();
		$onDisk   = $storage->getLocalFile( $internal );

		if ( is_string( $onDisk ) && file_exists( $onDisk ) )
		{
			unlink( $onDisk );
		}

		$storage->getCache()->remove( $internal );
	}

	/**
	 * @param  list<array>  $rules
	 */
	private function givenRules( array $rules ): void
	{
		Server::get( IAppConfig::class )
		      ->setValueString(
			      Application::APP_ID,
			      'rule_definitions',
			      json_encode( $rules, JSON_THROW_ON_ERROR ),
		      )
		;

		// Memoised per process, invalidated only through the write path this
		// went around.
		$this->ruleService->loadRules( refresh: true );
	}
}
