<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\State\Format;

use OCA\FileChecksumSearch\State\Format\CsvFormat;
use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\HashRecord;
use PHPUnit\Framework\TestCase;

/**
 * The exchange format: every field, no header about the file itself.
 */
class CsvFormatTest
	extends
	TestCase
{

	private CsvFormat $format;


	protected function setUp(): void
	{

		parent::setUp();
		$this->format = new CsvFormat();
	}


	public function testItWritesAHeaderRowFirst(): void
	{

		$stream = fopen( 'php://memory', 'r+' );
		$this->format->write(
			[ new HashRecord( 'home::alice', 'files/a.txt', 'sha256', 'abc', 5 ) ],
			$stream,
			new FormatOptions(),
		);
		rewind( $stream );
		$lines = explode( "\n", trim( stream_get_contents( $stream ) ) );
		fclose( $stream );

		$this->assertSame( 'storage,path,algo,hash,updated_at', $lines[0] );
		$this->assertSame( 'home::alice,files/a.txt,sha256,abc,5', $lines[1] );
	}


	/**
	 * Columns are matched by name, so a file a spreadsheet reordered still
	 * reads — and one with a column we do not know about is not a reason to
	 * refuse the rest.
	 */
	public function testColumnsAreMatchedByNameNotPosition(): void
	{

		$records = $this->read(
			"hash,note,path,updated_at,algo,storage\n"
			. "abc,ignored,files/a.txt,5,sha256,home::alice\n",
		);

		$this->assertEquals(
			new HashRecord( 'home::alice', 'files/a.txt', 'sha256', 'abc', 5 ),
			$records[0],
		);
	}


	public function testTheHeaderIsMatchedCaseAndSpaceInsensitively(): void
	{

		$records = $this->read(
			" Storage , Path ,ALGO,Hash\n"
			. "home::alice,files/a.txt,SHA256,ABC\n",
		);

		$this->assertSame( 'home::alice', $records[0]->storageId );
		$this->assertSame( 'sha256', $records[0]->algo );
		$this->assertSame( 'abc', $records[0]->hash );
	}


	/**
	 * Two columns may be absent for good reasons: `storage` when the caller
	 * anchors instead, `updated_at` when the source does not know when it
	 * hashed.
	 */
	public function testStorageAndTimestampMayBeAbsent(): void
	{

		$records = $this->read( "path,algo,hash\nfiles/a.txt,sha256,abc\n" );

		$this->assertSame( '', $records[0]->storageId );
		$this->assertNull( $records[0]->updatedAt );
	}


	public function testAnEmptyTimestampIsNotZero(): void
	{

		// Zero would be a claim — the epoch — and would make every file look
		// hopelessly outdated rather than unstamped.
		$records = $this->read( "path,algo,hash,updated_at\nfiles/a.txt,sha256,abc,\n" );

		$this->assertNull( $records[0]->updatedAt );
	}


	public function testBlankLinesAreSkipped(): void
	{

		$records = $this->read(
			"path,algo,hash\n"
			. "files/a.txt,sha256,abc\n"
			. "\n"
			. "files/b.txt,sha256,def\n",
		);

		$this->assertCount( 2, $records );
	}


	public function testAnEmptyFileYieldsNothing(): void
	{

		$this->assertSame( [], $this->read( '' ) );
	}


	public function testItSaysWhatItCannotCarry(): void
	{

		$this->assertFalse( $this->format->carriesConfig() );
		$this->assertNotEmpty( $this->format->losses() );
	}


	/**
	 * @return list<HashRecord>
	 */
	private function read( string $text ): array
	{

		$stream = fopen( 'php://memory', 'r+' );
		fwrite( $stream, $text );
		rewind( $stream );
		$records = iterator_to_array( $this->format->read( $stream, new FormatOptions() ), false );
		fclose( $stream );

		return $records;
	}

}
