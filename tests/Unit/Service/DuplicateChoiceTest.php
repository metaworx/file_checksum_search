<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\DuplicateChoice;
use OCA\FileChecksumSearch\Service\PathPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The command's choice on the cases the page's own (`pageSelection.spec.ts`)
 * is held to — one table, so that the command keeps the copy *Select
 * duplicates* keeps — and on what only the command offers: which copy stays,
 * none, and the paths.
 */
class DuplicateChoiceTest
    extends
    TestCase
{

//  static methods

	/**
	 * @return array<string, array{list<array<string, mixed>>, list<int>, ?int}>
	 */
	public static function cases(): array
	{
		/** @var list<array{case: string, files: list<array<string, mixed>>, best: list<int>, keeper: ?int}> $cases */
		$cases = json_decode( (string) file_get_contents( __DIR__ . '/../../fixtures/duplicate-choice.json' ), true, flags: JSON_THROW_ON_ERROR );
		$named = [];

		foreach ( $cases as $case )
		{
			$named[ $case['case'] ] = [ $case['files'], $case['best'], $case['keeper'] ];
		}

		return $named;
	}

	/**
	 * The rows as the listing gives them, every one the account's to
	 * delete where the case names no actions.
	 *
	 * @param  list<array<string, mixed>>  $files
	 *
	 * @return array{algo: string, hash_value: string, files: list<array<string, mixed>>}
	 */
	private static function group( array $files ): array
	{
		return [
			'algo'       => 'sha1',
			'hash_value' => 'abc',
			'files'      => array_map( static fn ( array $file ): array => $file + [ 'actions' => [ 'trash', 'delete' ] ], $files ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function file(
		int    $fileId,
		string $path,
		int    $mtime = 100,
	): array
	{
		return [
			'fileid' => $fileId,
			'path'   => $path,
			'mtime'  => $mtime,
		];
	}

	/**
	 * @param  list<string>  $paths
	 * @param  list<string>  $noPaths
	 */
	private static function choice(
		?string $keep = DuplicateChoice::KEEP_OLDEST,
		array   $paths = [],
		array   $noPaths = [],
	): DuplicateChoice
	{
		return new DuplicateChoice(
			$keep,
			array_map( static fn ( string $pattern ): PathPattern => PathPattern::of( $pattern ), $paths ),
			array_map( static fn ( string $pattern ): PathPattern => PathPattern::of( $pattern ), $noPaths ),
		);
	}


//  other non-static methods

	/**
	 * @param  list<array<string, mixed>>  $files
	 * @param  list<int>                   $best
	 */
	#[DataProvider( 'cases' )]
	public function testTheBestCopiesAndTheOneThatStayAreThePages(
		array $files,
		array $best,
		?int  $keeper,
	): void
	{
		$group = self::group( $files );

		$this->assertSame( $best, array_column( DuplicateChoice::bestCopies( $group ), 'fileid' ) );
		$this->assertSame( $keeper, DuplicateChoice::keeperOf( $group ) );
	}

	public function testEveryCopyButTheOneThatStaysGoes(): void
	{
		$group = self::group( [ self::file( 1, '/a.txt', 300 ), self::file( 2, '/b.txt' ), self::file( 3, '/c.txt', 200 ) ] );

		$this->assertSame( [ 'going' => [ 1, 3 ], 'keeper' => 2, 'keepsNone' => false ], self::choice()->of( $group ) );
	}

	public function testTheNewestOrTheFirstMayStayInstead(): void
	{
		$group = self::group( [ self::file( 1, '/b.txt', 300 ), self::file( 2, '/c.txt' ), self::file( 3, '/a.txt', 200 ) ] );

		$this->assertSame( 1, self::choice( DuplicateChoice::KEEP_NEWEST )->of( $group )['keeper'] );
		$this->assertSame( 3, self::choice( DuplicateChoice::KEEP_FIRST )->of( $group )['keeper'] );
	}

	public function testWithNoCopyToStayEveryCopyGoesAndTheGroupIsNamed(): void
	{
		$group = self::group( [ self::file( 1, '/a.txt' ), self::file( 2, '/b.txt' ) ] );

		$this->assertSame( [ 'going' => [ 1, 2 ], 'keeper' => null, 'keepsNone' => true ], self::choice( null )->of( $group ) );
	}

	/** A copy the account may not delete stays: it is not chosen, and none needs keeping. */
	public function testACopyTheAccountMayNotDeleteStaysAndNoneIsKept(): void
	{
		$group                        = self::group( [ self::file( 1, '/a.txt' ), self::file( 2, '/b.txt', 300 ) ] );
		$group['files'][0]['actions'] = [];

		$this->assertSame( [ 'going' => [ 2 ], 'keeper' => null, 'keepsNone' => false ], self::choice()->of( $group ) );
	}

	/** D7-A: outside every path, a copy stays, and none needs keeping. */
	public function testOnlyCopiesOnThePathsGo(): void
	{
		$group = self::group( [ self::file( 1, '/Photos/a.jpg', 300 ), self::file( 2, '/Backup/a.jpg' ), self::file( 3, '/Old/a.jpg' ) ] );

		$this->assertSame( [ 'going' => [ 2, 3 ], 'keeper' => null, 'keepsNone' => false ], self::choice( paths: [ '/Backup', '/Old' ] )->of( $group ) );
	}

	/** D9-A: the paths taking every copy, the one --keep picks stays. */
	public function testWherePathsTakeEveryCopyOneOfThemStays(): void
	{
		$group = self::group( [ self::file( 1, '/Backup/a.jpg', 300 ), self::file( 2, '/Backup/old/a.jpg' ) ] );

		$this->assertSame( [ 'going' => [ 1 ], 'keeper' => 2, 'keepsNone' => false ], self::choice( paths: [ '/Backup' ] )->of( $group ) );
	}

	/** D8-A: the pattern naming more folders wins, and a tie goes to --no-path. */
	public function testTheMoreSpecificPatternDecides(): void
	{
		$choice = self::choice( paths: [ '/Backup', '*.bak' ], noPaths: [ '/Backup/keep', '/Old' ] );

		$this->assertTrue( $choice->mayGo( '/Backup/a.jpg' ) );
		$this->assertFalse( $choice->mayGo( '/Backup/keep/a.jpg' ), '/Backup/keep names more than /Backup' );
		$this->assertTrue( $choice->mayGo( '/Docs/a.bak' ) );
		$this->assertFalse( $choice->mayGo( '/Old/a.bak' ), '/Old names more than *.bak' );
		$this->assertFalse( self::choice( paths: [ '/Backup' ], noPaths: [ '/Backup' ] )->mayGo( '/Backup/a.jpg' ), 'a tie keeps the copy' );
	}

	public function testWithoutPathsANoPathStillKeepsWhatItMatches(): void
	{
		$choice = self::choice( noPaths: [ '*.bak' ] );

		$this->assertTrue( $choice->mayGo( '/a.jpg' ) );
		$this->assertFalse( $choice->mayGo( '/a.bak' ) );
	}
}
