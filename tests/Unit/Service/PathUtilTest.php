<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\PathUtil;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PathUtil.
 *
 * Verifies glob-based path matching via matchesGlob().
 */
class PathUtilTest
	extends
	TestCase
{

	public function testMatchesGlobWithStarPattern(): void
	{

		self::assertTrue( PathUtil::matchesGlob( '*.txt', 'readme.txt' ) );
		self::assertTrue( PathUtil::matchesGlob( 'dir/*.txt', 'dir/readme.txt' ) );
		self::assertTrue( PathUtil::matchesGlob( '**/*.txt', 'a/b/c/readme.txt' ) );
		self::assertFalse( PathUtil::matchesGlob( '*.txt', 'readme.md' ) );
		self::assertFalse( PathUtil::matchesGlob( 'dir/*.txt', 'other/readme.txt' ) );
	}


	public function testMatchesGlobWithQuestionMark(): void
	{

		self::assertTrue( PathUtil::matchesGlob( 'file?.txt', 'file1.txt' ) );
		self::assertTrue( PathUtil::matchesGlob( 'file?.txt', 'fileA.txt' ) );
		self::assertTrue( PathUtil::matchesGlob( '??.txt', 'ab.txt' ) );
		self::assertFalse( PathUtil::matchesGlob( 'file?.txt', 'file10.txt' ) );
		self::assertFalse( PathUtil::matchesGlob( 'file?.txt', 'file.txt' ) );
	}


	public function testMatchesGlobWithCharacterClass(): void
	{

		self::assertTrue( PathUtil::matchesGlob( 'file[abc].txt', 'filea.txt' ) );
		self::assertTrue( PathUtil::matchesGlob( 'file[abc].txt', 'fileb.txt' ) );
		self::assertTrue( PathUtil::matchesGlob( 'file[abc].txt', 'filec.txt' ) );
		self::assertFalse( PathUtil::matchesGlob( 'file[abc].txt', 'filed.txt' ) );
		self::assertFalse( PathUtil::matchesGlob( 'file[abc].txt', 'fileab.txt' ) );
	}


	public function testMatchesGlobWithExactMatch(): void
	{

		self::assertTrue( PathUtil::matchesGlob( 'readme.txt', 'readme.txt' ) );
		self::assertTrue( PathUtil::matchesGlob( 'path/to/file.txt', 'path/to/file.txt' ) );
		self::assertFalse( PathUtil::matchesGlob( 'readme.txt', 'readme.md' ) );
		self::assertFalse( PathUtil::matchesGlob( 'Readme.txt', 'readme.txt' ) );
	}

}
