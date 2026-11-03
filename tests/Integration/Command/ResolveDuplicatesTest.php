<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Command;

use OCA\FileChecksumSearch\Command\ResolveDuplicates;
use OCA\FileChecksumSearch\Service\DuplicateActionService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\Files_Versions\Versions\IVersionManager;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command against a real instance, as occ runs it, with nobody signed
 * in and no terminal: on copies planted in an account's home, a dry run
 * names what would go and nothing goes; with -y they go, to the trash or
 * for good, their versions merged into the copy that stays.
 */
class ResolveDuplicatesTest
    extends
    DatabaseTestCase
{

//  private properties

	private static string $uid;

	/** Who was signed in before the test, given back after it. */
	private ?string $before = null;


//  static methods

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		// Loaded, as occ loads them: the trash takes what goes, and the
		// versions app answers what a file kept.
		$apps = Server::get( IAppManager::class );

		foreach ( [ 'files_trashbin', 'files_versions' ] as $app )
		{
			if ( $apps->isEnabledForUser( $app ) )
			{
				$apps->loadApp( $app );
			}
		}

		[ self::$uid ] = self::makeAccount( 'fcias_rd' );
	}


//  getters / setters / is* / has*

	/**
	 * Nobody signed in and no storage set up, as occ starts: the trash and
	 * the versions app take whose files they are from what is set up, and an
	 * earlier test's account would be taken for this one's.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->before = Server::get( IUserSession::class )->getUser()?->getUID();

		self::actAs( null );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		self::actAs( $this->before );

		parent::tearDown();
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testEveryCopyButTheOldestWouldGoAndNothingGoes(): void
	{
		[ $oldest, $middle, $newest, $hash ] = $this->copies();

		$data  = $this->resolve( [ '--hash' => $hash, '--dry-run' => true ] );
		$group = $data['accounts'][0]['groups'][0] ?? null;

		$this->assertNotNull( $group, (string) json_encode( $data ) );
		$this->assertSame( $oldest->getId(), $group['keeper'] );
		$this->assertEqualsCanonicalizing( [ $middle->getId(), $newest->getId() ], array_column( $group['results'], 'fileid' ) );
		$this->assertSame( [ DuplicateActionService::STATUS_READY ], array_values( array_unique( array_column( $group['results'], 'status' ) ) ) );
		$this->assertSame( [ DuplicateActionService::ACTION_TRASH ], array_values( array_unique( array_column( $group['results'], 'action' ) ) ) );

		foreach ( [ $oldest, $middle, $newest ] as $file )
		{
			$this->assertInstanceOf( File::class, $this->home()->getFirstNodeById( $file->getId() ), 'nothing was deleted' );
		}
	}

	/**
	 * --path lets only the Backup and Old copies go, --no-path keeps Old;
	 * the copy outside the paths stays anyway, so none is kept for them.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testThePathsChooseWhatMayGo(): void
	{
		[ , $middle, , $hash, $folder ] = $this->copies();

		$data  = $this->resolve( [ '--hash' => $hash, '--path' => [ "/$folder/Backup", "/$folder/Old" ], '--no-path' => [ "/$folder/Old" ], '--dry-run' => true ] );
		$group = $data['accounts'][0]['groups'][0] ?? null;

		$this->assertNotNull( $group, (string) json_encode( $data ) );
		$this->assertNull( $group['keeper'] );
		$this->assertSame( [ $middle->getId() ], array_column( $group['results'], 'fileid' ) );
	}

	/**
	 * D2-A, D3-A: with -y the copies go at once, to the trash.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testWithYesTheCopiesGoToTheTrash(): void
	{
		$this->needs( 'files_trashbin' );

		[ $oldest, $middle, $newest, $hash ] = $this->copies();

		$data = $this->resolve( [ '--hash' => $hash, '--yes' => true ] );

		$this->assertInstanceOf( File::class, $this->home()->getFirstNodeById( $oldest->getId() ) );

		foreach ( [ $middle, $newest ] as $file )
		{
			$this->assertNull( $this->home()->getFirstNodeById( $file->getId() ) );
			$this->assertNotNull( Server::get( ITrashManager::class )->getTrashNodeById( $this->user(), $file->getId() ), 'in the trash: ' . json_encode( $data ) );
		}
	}

	/**
	 * D3-A: --permanent goes past the trash.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testPermanentLeavesNothingInTheTrash(): void
	{
		$this->needs( 'files_trashbin' );

		[ , $middle, , $hash ] = $this->copies();

		$this->resolve( [ '--hash' => $hash, '--yes' => true, '--permanent' => true ] );

		$this->assertNull( $this->home()->getFirstNodeById( $middle->getId() ) );
		$this->assertNull( Server::get( ITrashManager::class )->getTrashNodeById( $this->user(), $middle->getId() ) );
	}

	/**
	 * --remove-last takes every copy.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRemoveLastTakesEveryCopy(): void
	{
		$this->needs( 'files_trashbin' );

		$copies = $this->copies();
		$data   = $this->resolve( [ '--hash' => $copies[3], '--yes' => true, '--remove-last' => true ] );

		$this->assertSame( 1, $data['summary']['keepsNone'] );

		foreach ( array_slice( $copies, 0, 3 ) as $file )
		{
			$this->assertNull( $this->home()->getFirstNodeById( $file->getId() ) );
		}
	}

	/**
	 * D4-A: the versions of a copy that goes are merged into the one that
	 * stays; with --no-merge they go with it.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheVersionsOfACopyThatGoesAreMergedIntoTheOneThatStays(): void
	{
		$this->needs( 'files_trashbin' );
		$this->needs( 'files_versions' );

		foreach ( [ true, false ] as $merge )
		{
			// Its earlier versions: the versions app lists the file as it is
			// now among them too.
			[ $stays, $gone, $hash ] = $this->versionedCopies();
			$revisions               = array_values( array_diff( $this->revisionsOf( $gone ), [ (string) $gone->getMTime() ] ) );

			$this->assertNotSame( [], $revisions, 'the versions app kept a version of the copy that goes' );

			$this->resolve( [ '--hash' => $hash, '--yes' => true ] + ( $merge ? [] : [ '--no-merge' => true ] ) );

			$merged = array_values( array_intersect( $revisions, $this->revisionsOf( $stays ) ) );

			if ( $merge )
			{
				$this->assertSame( $revisions, $merged, 'merged into the copy that stays' );
			}
			else
			{
				$this->assertSame( [], $merged, 'let go with the copy' );
			}
		}
	}

	/**
	 * D2-A: without a terminal the question answers no, and nothing goes.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testWithoutATerminalNothingGoes(): void
	{
		$copies = $this->copies();
		$data   = $this->resolve( [ '--hash' => $copies[3] ], Command::FAILURE );

		$this->assertTrue( $data['accounts'][0]['declined'] );

		foreach ( array_slice( $copies, 0, 3 ) as $file )
		{
			$this->assertInstanceOf( File::class, $this->home()->getFirstNodeById( $file->getId() ) );
		}
	}

	/**
	 * The command's answer, as JSON, for the account and $options, run as
	 * occ runs it without a terminal.
	 *
	 * @param  array<string, mixed>  $options
	 *
	 * @return array<string, mixed>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function resolve(
		array $options,
		int   $expected = Command::SUCCESS,
	): array
	{
		$tester = new CommandTester( Server::get( ResolveDuplicates::class ) );
		$code   = $tester->execute( $options + [ '--user' => [ self::$uid ], '--algo' => 'sha1', '--output' => 'json' ], [ 'interactive' => false ] );

		$this->assertSame( $expected, $code, $tester->getDisplay() );

		return json_decode( $tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function needs( string $app ): void
	{
		if ( ! Server::get( IAppManager::class )->isEnabledForUser( $app ) )
		{
			$this->markTestSkipped( "FCIAS integration: $app is not enabled on this instance." );
		}
	}

	/**
	 * Two copies of one content, the older to stay, the newer to go with an
	 * earlier version of other content, which the versions app kept when
	 * the content was written over it.
	 *
	 * @return array{0: File, 1: File, 2: string}  the copy that stays, the
	 *         one that goes, and their hash
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function versionedCopies(): array
	{
		$name    = 'fcias_rd_' . bin2hex( random_bytes( 4 ) );
		$content = "fcias resolve $name";
		$folder  = $this->home()->newFolder( $name );

		$stays = $folder->newFile( 'a.txt', $content );
		$stays->touch( time() - 3000 );

		$gone = $folder->newFolder( 'Backup' )->newFile( 'a.txt', "old $name" );
		$gone->touch( time() - 7200 );
		$this->refetched( $gone )->putContent( $content );

		$stays = $this->refetched( $stays );
		$gone  = $this->refetched( $gone );

		foreach ( [ $stays, $gone ] as $file )
		{
			$this->assertTrue( Server::get( HashCalculationService::class )->recalcFileHash( $file, 'sha1' )['success'] );
		}

		return [ $stays, $gone, sha1( $content ) ];
	}

	/**
	 * $file read afresh, its time and ETag as they are now.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function refetched( File $file ): File
	{
		$node = $this->home()->getFirstNodeById( $file->getId() );
		$this->assertInstanceOf( File::class, $node );

		return $node;
	}

	/**
	 * The revisions the versions app lists for $file.
	 *
	 * @return list<string>
	 */
	private function revisionsOf( File $file ): array
	{
		return array_map(
			static fn ( object $version ): string => (string) $version->getRevisionId(),
			Server::get( IVersionManager::class )->getVersionsForFile( $this->user(), $file ),
		);
	}

	private function user(): IUser
	{
		$user = Server::get( IUserManager::class )->get( self::$uid );
		$this->assertInstanceOf( IUser::class, $user );

		return $user;
	}

	/**
	 * Three copies of one content in a folder of its own: the oldest in it,
	 * then one in `Backup`, the newest in `Old`, each hashed.
	 *
	 * Each has a name of its own: the trash names what it takes by its name
	 * and the second it went, and keeps one of two that share both.
	 *
	 * @return array{0: File, 1: File, 2: File, 3: string, 4: string}  the
	 *         copies, oldest first, their hash, and the folder's name
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function copies(): array
	{
		$name    = 'fcias_rd_' . bin2hex( random_bytes( 4 ) );
		$content = "fcias resolve $name";
		$folder  = $this->home()->newFolder( $name );
		$files   = [];

		foreach ( [ '' => 3000, 'Backup' => 2000, 'Old' => 1000 ] as $sub => $age )
		{
			$in   = $sub === ''
				? $folder
				: $folder->newFolder( $sub );
			$file = $in->newFile( ( $sub === '' ? 'a' : strtolower( $sub ) ) . '.txt', $content );
			$file->touch( time() - $age );

			$file = $this->home()->getFirstNodeById( $file->getId() );
			$this->assertInstanceOf( File::class, $file );
			$this->assertTrue( Server::get( HashCalculationService::class )->recalcFileHash( $file, 'sha1' )['success'] );

			$files[] = $file;
		}

		return [ ...$files, sha1( $content ), $name ];
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function home(): Folder
	{
		return Server::get( IRootFolder::class )->getUserFolder( self::$uid );
	}
}
