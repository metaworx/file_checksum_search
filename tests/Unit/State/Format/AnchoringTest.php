<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\State\Format;

use InvalidArgumentException;
use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\FormatRegistry;
use OCA\FileChecksumSearch\State\HashRecord;
use PHPUnit\Framework\TestCase;

/**
 * Where a path is measured from.
 *
 * This is the part of the import worth the most tests: a mis-anchored path
 * does not fail, it *succeeds against the wrong file* — writing one file's
 * checksum onto another, which is worse than importing nothing, because
 * nothing about the result looks wrong afterwards.
 */
class AnchoringTest
    extends
    TestCase
{

//  other non-static methods

	public function testAUserAnchorMeasuresFromTheirFilesDirectory(): void
	{
		$anchored = ( new FormatOptions( userId: 'alice' ) )
			->anchor( new HashRecord( '', 'Photos/a.jpg', 'sha256', 'abc' ) )
		;

		$this->assertSame( 'home::alice', $anchored->storageId );
		$this->assertSame( 'files/Photos/a.jpg', $anchored->path );
	}

	public function testAStorageAnchorMeasuresFromTheStorageRoot(): void
	{
		// An internal path already starts at the storage root, so nothing is
		// prepended — `files/` here is the storage's own files directory.
		$anchored = ( new FormatOptions( storageId: 'local::/data/__groupfolders/5/' ) )
			->anchor( new HashRecord( '', 'files/Team/notes.md', 'sha256', 'abc' ) )
		;

		$this->assertSame( 'local::/data/__groupfolders/5/', $anchored->storageId );
		$this->assertSame( 'files/Team/notes.md', $anchored->path );
	}

	/**
	 * The rule that makes a backup safe to re-import with an anchor set: a
	 * record that names its own storage is already anchored. Without this,
	 * `--user alice` over an export would bury every path a level deeper.
	 */
	public function testARecordThatNamesItsStorageIsNeverReAnchored(): void
	{
		$record = new HashRecord( 'home::alice', 'files/Photos/a.jpg', 'sha256', 'abc', 5 );

		$this->assertEquals(
			$record,
			( new FormatOptions( userId: 'alice' ) )->anchor( $record ),
		);
		$this->assertEquals(
			$record,
			( new FormatOptions( storageId: 'somewhere::else' ) )->anchor( $record ),
		);
	}

	/**
	 * `find . | xargs sha1sum` writes `./Photos/a.jpg`, and a listing made
	 * with an absolute-looking path is still relative to where it was run.
	 *
	 * @dataProvider messyPrefixes
	 */
	public function testTheCommonPrefixesFromShellToolsAreNormalised( string $written ): void
	{
		$this->assertSame(
			'files/Photos/a.jpg',
			( new FormatOptions( userId: 'alice' ) )
				->anchor( new HashRecord( '', $written, 'sha256', 'abc' ) )
				->path,
		);
	}


//  static methods

	/**
	 * @return array<string, array{string}>
	 */
	public static function messyPrefixes(): array
	{
		return [
			'plain relative' => [ 'Photos/a.jpg' ],
			'dot slash'      => [ './Photos/a.jpg' ],
			'repeated dot'   => [ './././Photos/a.jpg' ],
			'leading slash'  => [ '/Photos/a.jpg' ],
			'slash then dot' => [ '/./Photos/a.jpg' ],
		];
	}

	public function testWithoutAnAnchorAPathlessRecordKeepsItsOwnPath(): void
	{
		$record = new HashRecord( '', 'Photos/a.jpg', 'sha256', 'abc' );

		$this->assertEquals( $record, ( new FormatOptions() )->anchor( $record ) );
		$this->assertFalse( ( new FormatOptions() )->isAnchored() );
	}

	/**
	 * Two anchors would disagree about the same path, so the combination is
	 * refused where it is written rather than resolved by precedence.
	 */
	public function testTwoAnchorsAreRefused(): void
	{
		$this->expectException( InvalidArgumentException::class );

		new FormatOptions( userId: 'alice', storageId: 'home::alice' );
	}

	/**
	 * The readers apply it, not the callers — which is the point of putting
	 * it here: every format anchors the same way.
	 *
	 * @dataProvider unanchoredInputs
	 */
	public function testEveryReaderAnchorsWhatItReads(
		string $format,
		string $text,
	): void
	{
		$stream = fopen( 'php://memory', 'r+' );
		fwrite( $stream, $text );
		rewind( $stream );

		$records = iterator_to_array(
			( new FormatRegistry() )->get( $format )
			                        ->read( $stream, new FormatOptions( algo: 'sha256', userId: 'alice' ) ),
			false,
		);
		fclose( $stream );

		$this->assertCount( 1, $records );
		$this->assertSame( 'home::alice', $records[0]->storageId );
		$this->assertSame( 'files/Photos/a.jpg', $records[0]->path );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function unanchoredInputs(): array
	{
		return [
			'sum'  => [
				FormatRegistry::FORMAT_SUM,
				str_repeat( 'a', 64 ) . "  ./Photos/a.jpg\n",
			],
			'csv'  => [
				FormatRegistry::FORMAT_CSV,
				"path,algo,hash\nPhotos/a.jpg,sha256," . str_repeat( 'a', 64 ) . "\n",
			],
			'json' => [
				FormatRegistry::FORMAT_JSON,
				'{"schema":1,"hashes":[{"path":"Photos/a.jpg","algo":"sha256","hash":"'
				. str_repeat( 'a', 64 ) . '"}]}',
			],
		];
	}
}
