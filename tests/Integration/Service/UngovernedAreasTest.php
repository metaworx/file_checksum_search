<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Server;

/**
 * A hashed file in the trash, or among the versions, is nobody's copy of
 * anything: the contract says those areas are governed by nothing, and
 * the sweep, the sidebar and the API all refuse them. The listing and the
 * lookup used to offer one all the same — five hashed copies of one test
 * file, in the administrator's trash, headed every group on the Duplicates
 * page once — because the one place every caller resolves a row's path
 * asked the filecache for the path and nothing else.
 *
 * The rows are made here by hand, straight under the trash and versions
 * folders: whether a deletion lands in the trash depends on which apps
 * this process booted, and the app's own delete listener would clear the
 * hashes on the way. What is under test is the path, not how a file got
 * there.
 */
class UngovernedAreasTest
    extends
    DatabaseTestCase
{

//  private properties

	private static string $uid;

	private static string $hash;

	/** @var array<string, int> */
	private static array $fileIds = [];


//  static methods

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		[ self::$uid ] = self::makeAccount( 'fcias_ungoverned' );

		$content    = 'fcias ungoverned ' . bin2hex( random_bytes( 4 ) );
		self::$hash = sha1( $content );

		$root = Server::get( IRootFolder::class )->getUserFolder( self::$uid );
		$home = $root->getParent();

		// Two live copies, and one each where no rule governs. The trash
		// keeps a deleted file as `files_trashbin/files/<name>.d<time>`, the
		// versions app as `files_versions/<path>.v<time>`; the names follow.
		$docs  = $root->newFolder( 'Docs' );
		$files = [
			'live-a'  => $docs->newFile( 'a.txt', $content ),
			'live-b'  => $docs->newFile( 'b.txt', $content ),
			'trash'   => self::folderAt( $home, 'files_trashbin/files' )->newFile( 'c.txt.d1700000000', $content ),
			'version' => self::folderAt( $home, 'files_versions/Docs' )->newFile( 'a.txt.v1700000000', $content ),
		];

		$index = Server::get( HashIndexService::class );

		foreach ( $files as $key => $file )
		{
			self::$fileIds[ $key ] = $file->getId();
			$result                = $index->recalcFileHash( $file, 'sha1', false );

			self::assertTrue( $result['success'] ?? false, "hashing $key" );
		}
	}

	private static function folderAt( Folder $home, string $path ): Folder
	{
		try
		{
			$node = $home->get( $path );

			if ( $node instanceof Folder )
			{
				return $node;
			}
		}
		catch ( NotFoundException )
		{
			// Made below, one segment at a time.
		}

		$folder = $home;

		foreach ( explode( '/', $path ) as $segment )
		{
			try
			{
				$next = $folder->get( $segment );
			}
			catch ( NotFoundException )
			{
				$next = $folder->newFolder( $segment );
			}

			/** @var Folder $next */
			$folder = $next;
		}

		return $folder;
	}


//  other non-static methods

	public function testTheLookupAnswersTheLiveCopiesOnly(): void
	{
		$found = Server::get( ChecksumApi::class )->findByHash( self::$hash, 'sha1', 100, [ self::$uid ] );
		$ids   = array_map( static fn ( array $row ): int => (int) $row['fileid'], $found['results'] ?? [] );
		sort( $ids );

		$this->assertSame( [ self::$fileIds['live-a'], self::$fileIds['live-b'] ], $ids );
	}

	public function testADuplicateGroupCountsTheLiveCopiesOnly(): void
	{
		$listing = Server::get( ChecksumApi::class )->findDuplicatesFor( [ self::$uid ], 'sha1', 2, 50, 0, self::$hash );
		$groups  = array_values( array_filter(
			$listing['duplicates'] ?? [],
			static fn ( array $group ): bool => $group['hash_value'] === self::$hash,
		) );

		$this->assertCount( 1, $groups, 'one group for the hash' );

		$ids = array_map( static fn ( array $file ): int => (int) $file['fileid'], $groups[0]['files'] );
		sort( $ids );

		$this->assertSame( [ self::$fileIds['live-a'], self::$fileIds['live-b'] ], $ids );
		$this->assertSame( 2, (int) $groups[0]['file_count'], 'the count follows the rows kept' );
	}
}
