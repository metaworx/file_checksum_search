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
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command against a real instance, as occ runs it, with nobody signed
 * in: on copies planted in an account's home, the report names what would
 * go, and nothing goes.
 */
class ResolveDuplicatesTest
    extends
    DatabaseTestCase
{

//  private properties

	private static string $uid;


//  static methods

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		[ self::$uid ] = self::makeAccount( 'fcias_rd' );
	}


//  other non-static methods

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testEveryCopyButTheOldestWouldGoAndNothingGoes(): void
	{
		[ $oldest, $middle, $newest, $hash ] = $this->copies();

		$data  = $this->resolve( [ '--hash' => $hash ] );
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

		$data  = $this->resolve( [ '--hash' => $hash, '--path' => [ "/$folder/Backup", "/$folder/Old" ], '--no-path' => [ "/$folder/Old" ] ] );
		$group = $data['accounts'][0]['groups'][0] ?? null;

		$this->assertNotNull( $group, (string) json_encode( $data ) );
		$this->assertNull( $group['keeper'] );
		$this->assertSame( [ $middle->getId() ], array_column( $group['results'], 'fileid' ) );
	}

	/**
	 * The command's answer, as JSON, for the account and $options.
	 *
	 * @param  array<string, mixed>  $options
	 *
	 * @return array<string, mixed>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function resolve( array $options ): array
	{
		$tester = new CommandTester( Server::get( ResolveDuplicates::class ) );
		$code   = $tester->execute( $options + [ '--user' => [ self::$uid ], '--algo' => 'sha1', '--dry-run' => true, '--output' => 'json' ] );

		$this->assertSame( Command::SUCCESS, $code, $tester->getDisplay() );

		return json_decode( $tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR );
	}

	/**
	 * Three copies of one content in a folder of its own: the oldest in it,
	 * then one in `Backup`, the newest in `Old`, each hashed.
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
			$file = $in->newFile( 'a.txt', $content );
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
