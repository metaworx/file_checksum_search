<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\State\Format;

use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\FormatRegistry;
use OCA\FileChecksumSearch\State\HashRecord;
use PHPUnit\Framework\TestCase;

/**
 * What each format survives being written to and read back.
 *
 * The two directions live behind one interface precisely so this can be one
 * test rather than two that drift apart — and the interesting rows are the
 * awkward paths, because a path is the one field an import uses to decide
 * *which file* it is talking about.
 */
class FormatRoundTripTest
	extends
	TestCase
{

	/**
	 * json and csv carry every field, so they come back identical.
	 *
	 * @dataProvider losslessFormats
	 */
	public function testALosslessFormatReturnsWhatItWasGiven( string $format ): void
	{

		$records = $this->awkwardRecords();
		$back    = $this->roundTrip( $format, $records, new FormatOptions() );

		$this->assertEquals( $records, $back );
	}


	/**
	 * @dataProvider losslessFormats
	 */
	public function testPrettyPrintingChangesNothingButWhitespace( string $format ): void
	{

		$records = $this->awkwardRecords();

		$this->assertEquals(
			$this->roundTrip( $format, $records, new FormatOptions() ),
			$this->roundTrip( $format, $records, new FormatOptions( pretty: true ) ),
		);
	}


	/**
	 * A sumfile keeps the hash and the path and drops the rest — so what
	 * comes back is the algorithm the reader was told and the storage it was
	 * anchored to, with no timestamp at all.
	 */
	public function testASumfileKeepsOnlyTheHashAndThePath(): void
	{

		$options = new FormatOptions( algo: 'sha256', storageId: 'home::alice' );

		$back = $this->roundTrip(
			FormatRegistry::FORMAT_SUM,
			[
				new HashRecord( 'home::alice', 'files/Photos/a b.jpg', 'sha256', str_repeat( 'a', 64 ), 1756400000 ),
				new HashRecord(
					'home::alice', "files/back\\slash and\nnewline.txt", 'sha256', str_repeat( 'b', 64 ), 99,
				),
			],
			$options,
		);

		$this->assertSame( 'files/Photos/a b.jpg', $back[0]->path );
		$this->assertSame( "files/back\\slash and\nnewline.txt", $back[1]->path );
		$this->assertSame( str_repeat( 'b', 64 ), $back[1]->hash );

		foreach ( $back as $record )
		{
			$this->assertNull( $record->updatedAt, 'a sumfile cannot say when it was computed' );
			$this->assertSame( 'sha256', $record->algo, 'the algorithm can only come from the caller' );
		}
	}


	/**
	 * One listing, one algorithm: the tools that read a sumfile assume it,
	 * so writing one with `--algo` set drops everything else rather than
	 * producing a file whose lines mean different things.
	 */
	public function testWritingASumfileKeepsOneAlgorithmOnly(): void
	{

		$back = $this->roundTrip(
			FormatRegistry::FORMAT_SUM,
			[
				new HashRecord( 's', 'a.txt', 'sha256', str_repeat( 'a', 64 ) ),
				new HashRecord( 's', 'b.txt', 'md5', str_repeat( 'b', 32 ) ),
				new HashRecord( 's', 'c.txt', 'sha256', str_repeat( 'c', 64 ) ),
			],
			new FormatOptions( algo: 'sha256', storageId: 's' ),
		);

		$this->assertSame(
			[
				'a.txt',
				'c.txt',
			],
			array_map( static fn(
				HashRecord $record,
			): string => $record->path, $back ),
		);
	}


	/**
	 * @dataProvider allFormats
	 */
	public function testAnEmptySetWritesSomethingReadable( string $format ): void
	{

		$this->assertSame(
			[],
			$this->roundTrip( $format, [], new FormatOptions( algo: 'sha256', storageId: 's' ) ),
		);
	}


	/**
	 * @return array<string, array{string}>
	 */
	public static function losslessFormats(): array
	{

		return [
			'json' => [ FormatRegistry::FORMAT_JSON ],
			'csv'  => [ FormatRegistry::FORMAT_CSV ],
		];
	}


	/**
	 * @return array<string, array{string}>
	 */
	public static function allFormats(): array
	{

		return self::losslessFormats() + [ 'sum' => [ FormatRegistry::FORMAT_SUM ] ];
	}


	/**
	 * Paths that have broken importers before: spaces, a comma, quotes, a
	 * backslash, a newline, and non-ASCII.
	 *
	 * @return list<HashRecord>
	 */
	private function awkwardRecords(): array
	{

		return [
			new HashRecord( 'home::alice', 'files/Photos/a b.jpg', 'sha256', str_repeat( 'a', 64 ), 1756400000 ),
			new HashRecord( 'home::alice', 'files/comma, "quoted".txt', 'sha1', str_repeat( 'b', 40 ), null ),
			new HashRecord(
				'local::/data/__groupfolders/5/',
				"files/back\\slash and\nnewline.md",
				'md5',
				str_repeat( 'c', 32 ),
				1,
			),
			new HashRecord(
				'object::user:bob', 'files/Grüße/日本語.txt', 'sha512', str_repeat( 'd', 128 ), 1756400001,
			),
		];
	}


	/**
	 * @param  list<HashRecord>  $records
	 *
	 * @return list<HashRecord>
	 */
	private function roundTrip(
		string $format,
		array $records,
		FormatOptions $options,
	): array {

		$implementation = ( new FormatRegistry() )->get( $format );
		$stream         = fopen( 'php://memory', 'r+' );

		$implementation->write( $records, $stream, $options );
		rewind( $stream );
		$back = iterator_to_array( $implementation->read( $stream, $options ), false );
		fclose( $stream );

		return $back;
	}

}
