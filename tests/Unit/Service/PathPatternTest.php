<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\PathPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The path pattern on the cases the page's own (`pathPattern.spec.ts`) is
 * held to: one table, so that the server filters by what the page selects.
 */
class PathPatternTest
    extends
    TestCase
{

//  static methods

	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function cases(): array
	{
		/** @var list<array{pattern: string, path: string, match: bool}> $cases */
		$cases = json_decode( (string) file_get_contents( __DIR__ . '/../../fixtures/path-patterns.json' ), true, flags: JSON_THROW_ON_ERROR );
		$named = [];

		foreach ( $cases as $case )
		{
			$named[ "{$case['pattern']} on {$case['path']}" ] = [ $case['pattern'], $case['path'], $case['match'] ];
		}

		return $named;
	}


//  other non-static methods

	#[DataProvider( 'cases' )]
	public function testMatchesAsTheTableSays(
		string $pattern,
		string $path,
		bool   $match,
	): void
	{
		$this->assertSame( $match, PathPattern::of( $pattern )?->matches( $path ) );
	}

	public function testAnEmptyPatternIsNoPatternAtAll(): void
	{
		$this->assertNull( PathPattern::of( '' ) );
		$this->assertNull( PathPattern::of( '  / ' ) );
	}

	/** D8-A of AP DuplicateCli: how specific a pattern is, for --path against --no-path. */
	public function testAPatternNamesTheFoldersItReachesFromTheRoot(): void
	{
		$this->assertSame( 2, PathPattern::of( '/Backup/keep' )?->folders() );
		$this->assertSame( 2, PathPattern::of( 'Backup/keep/' )?->folders() );
		$this->assertSame( 1, PathPattern::of( '/Backup' )?->folders() );
		$this->assertSame( 2, PathPattern::of( '/Archive/**/*.pdf' )?->folders(), 'a ** names no folder' );
		$this->assertSame( 0, PathPattern::of( 'Backup' )?->folders(), 'a name anywhere names none' );
		$this->assertSame( 0, PathPattern::of( '*.bak' )?->folders() );
	}

	public function testAGroupMatchesWhereOneOfItsRowsDoes(): void
	{
		$pattern = PathPattern::of( '/Backup' );

		$this->assertTrue( $pattern?->matchesAny( [ '/Photos/a.jpg', '/Backup/a.jpg' ] ) );
		$this->assertFalse( $pattern?->matchesAny( [ '/Photos/a.jpg' ] ) );
	}
}
