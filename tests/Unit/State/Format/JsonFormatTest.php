<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\State\Format;

use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\JsonFormat;
use OCA\FileChecksumSearch\State\HashRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The one format that is a backup, and the constraint that buys it.
 */
class JsonFormatTest
    extends
    TestCase
{

//  private properties

	private JsonFormat $format;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();
		$this->format = new JsonFormat();
	}


//  other non-static methods

	/**
	 * A backup document must be readable by anything that reads JSON, not
	 * only by the cursor that streams it — the streaming is an
	 * optimisation, not a dialect.
	 */
	public function testWhatItWritesIsOrdinaryJson(): void
	{
		$text = $this->writeDocument( pretty: true );

		$decoded = json_decode( $text, true );

		$this->assertIsArray( $decoded, 'the document must parse as plain JSON' );
		$this->assertSame( JsonFormat::SCHEMA_VERSION, $decoded['schema'] );
		$this->assertSame( 'file_checksum_search', $decoded['app'] );
		$this->assertSame( '1.2.0', $decoded['app_version'] );
		$this->assertSame( [ 'rule_definitions' => '[]' ], $decoded['config'] );
		$this->assertCount( 1, $decoded['status'] );
		$this->assertCount( 2, $decoded['hashes'] );
	}

	public function testTheHeaderIsReadableBeforeASingleRecordIs(): void
	{
		$stream = $this->streamOf( $this->writeDocument() );
		$read   = $this->format->readDocument( $stream, new FormatOptions() );

		// Asserted before touching the generator: a restore has to be able to
		// refuse a file it cannot honour without parsing all of it.
		$this->assertSame( JsonFormat::SCHEMA_VERSION, $read['header']['schema'] );
		$this->assertSame( 'ocabc123', $read['header']['instance_id'] );
		$this->assertSame( [ 'rule_definitions' => '[]' ], $read['config'] );

		$this->assertCount( 2, iterator_to_array( $read['records'], false ) );
		fclose( $stream );
	}

	public function testTheFormatAlwaysStampsItsOwnSchemaAndApp(): void
	{
		$stream = fopen( 'php://memory', 'r+' );
		// A caller that supplies no header at all still gets a backup document
		// that says what it is.
		$this->format->write( [], $stream, new FormatOptions() );
		rewind( $stream );
		$decoded = json_decode( stream_get_contents( $stream ), true );
		fclose( $stream );

		$this->assertSame( JsonFormat::SCHEMA_VERSION, $decoded['schema'] );
		$this->assertSame( 'file_checksum_search', $decoded['app'] );
		$this->assertSame( [], $decoded['hashes'] );
	}

	/**
	 * The records array is streamed in both directions, so nothing can be
	 * read past it — the writer puts it last and the reader says so when a
	 * hand-made backup document does not.
	 */
	public function testTheRecordsKeyMustBeLast(): void
	{
		$stream = $this->streamOf(
			'{"schema":1,"hashes":[{"storage":"s","path":"p","algo":"md5","hash":"x"}],"config":{"a":"b"}}',
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must be the last key/' );

		$read = $this->format->readDocument( $stream, new FormatOptions() );
		iterator_to_array( $read['records'], false );
	}

	public function testTheWriterPutsTheRecordsLast(): void
	{
		$text = $this->writeDocument();

		// The key, not the word: the header's own `slices` list names
		// "hashes" too, and it is written first.
		$this->assertGreaterThan(
			strpos( $text, '"config":' ),
			strpos( $text, '"hashes":' ),
		);
	}

	/**
	 * A backup of configuration alone has no records key at all, which is
	 * not the same thing as an empty one but reads the same way.
	 */
	public function testAConfigOnlyDocumentReadsAsNoRecords(): void
	{
		$stream = $this->streamOf( '{"schema":1,"config":{"a":"b"}}' );
		$read   = $this->format->readDocument( $stream, new FormatOptions() );

		$this->assertSame( [ 'a' => 'b' ], $read['config'] );
		$this->assertSame( [], iterator_to_array( $read['records'], false ) );
		fclose( $stream );
	}

	/**
	 * Accepted on the way in, never produced on the way out: a bare array is
	 * what someone writes by hand, and refusing it would help nobody.
	 */
	public function testABareArrayIsAHashesOnlyDocument(): void
	{
		$stream  = $this->streamOf( '[{"storage":"s","path":"p","algo":"md5","hash":"x"}]' );
		$records = iterator_to_array( $this->format->read( $stream, new FormatOptions() ), false );
		fclose( $stream );

		$this->assertCount( 1, $records );
		$this->assertSame( 'p', $records[0]->path );
	}

	public function testATruncatedDocumentIsAnError(): void
	{
		$stream = $this->streamOf( '{"schema":1,"hashes":[{"storage":"s","path":"p",' );

		$this->expectException( RuntimeException::class );

		iterator_to_array( $this->format->read( $stream, new FormatOptions() ), false );
	}

	/**
	 * Records are decoded one at a time, so a backup document larger than
	 * anything worth holding in memory still reads. The count is the
	 * observable part; that it never assembled them all is what the cursor
	 * exists for.
	 */
	public function testManyRecordsReadWithoutAssemblingThemAll(): void
	{
		$records = [];

		for ( $index = 0; $index < 5000; $index ++ )
		{
			$records[] = new HashRecord(
				'home::alice',
				'files/f' . $index . '.txt',
				'sha256',
				str_repeat( 'a', 64 ),
				$index,
			);
		}

		$stream = fopen( 'php://memory', 'r+' );
		$this->format->write( $records, $stream, new FormatOptions() );
		rewind( $stream );

		$seen = 0;
		$last = null;

		foreach ( $this->format->read( $stream, new FormatOptions() ) as $record )
		{
			$seen ++;
			$last = $record;
		}

		fclose( $stream );

		$this->assertSame( 5000, $seen );
		$this->assertSame( 'files/f4999.txt', $last->path );
	}

	/**
	 * The status slice is written for the operator's record and stepped over
	 * on the way back in — an import may not hand-set what a file is waiting
	 * for, and the slice is as large as the instance, so reading it would
	 * defeat the streaming it sits in front of.
	 */
	public function testTheStatusSliceIsWrittenButNeverReadBack(): void
	{
		$stream = $this->streamOf( $this->writeDocument() );
		$read   = $this->format->readDocument( $stream, new FormatOptions() );

		$this->assertArrayNotHasKey( 'status', $read['header'] );
		$this->assertSame( [ 'rule_definitions' => '[]' ], $read['config'] );
		$this->assertCount( 2, iterator_to_array( $read['records'], false ) );
		fclose( $stream );
	}

	public function testTheStatusSliceComesBeforeTheHashes(): void
	{
		$text = $this->writeDocument();

		$this->assertGreaterThan( strpos( $text, '"config":' ), strpos( $text, '"status":' ) );
		$this->assertGreaterThan( strpos( $text, '"status":' ), strpos( $text, '"hashes":' ) );
	}

	public function testADocumentWithNoStatusSliceHasNoStatusKey(): void
	{
		$stream = fopen( 'php://memory', 'r+' );
		$this->format->writeDocument( [], null, null, [], $stream, new FormatOptions() );
		rewind( $stream );
		$text = stream_get_contents( $stream );
		fclose( $stream );

		$this->assertStringNotContainsString( '"status"', $text );
	}

	/**
	 * A slice that was not asked for is absent, never present and empty: the
	 * two say different things to a restore.
	 */
	public function testAnUnrequestedSliceIsAbsentNotEmpty(): void
	{
		$stream = fopen( 'php://memory', 'r+' );
		$this->format->writeDocument(
			[],
			[ 'rule_definitions' => '[]' ],
			null,
			null,
			$stream,
			new FormatOptions(),
		);
		rewind( $stream );
		$text = stream_get_contents( $stream );
		fclose( $stream );

		$this->assertIsArray( json_decode( $text, true ) );
		$this->assertStringNotContainsString( '"hashes"', $text );
		$this->assertStringNotContainsString( '"status"', $text );
	}

	/**
	 * Empty is still a result: a hashes slice that was asked for and found
	 * nothing is written as an empty array.
	 */
	public function testARequestedSliceThatFoundNothingIsAnEmptyArray(): void
	{
		$stream = fopen( 'php://memory', 'r+' );
		$this->format->writeDocument( [], null, null, [], $stream, new FormatOptions() );
		rewind( $stream );
		$decoded = json_decode( stream_get_contents( $stream ), true );
		fclose( $stream );

		$this->assertSame( [], $decoded['hashes'] );
	}

	public function testItCarriesConfigAndLosesNothing(): void
	{
		$this->assertTrue( $this->format->carriesConfig() );
		$this->assertSame( [], $this->format->losses() );
	}

	private function writeDocument( bool $pretty = false ): string
	{
		$stream = fopen( 'php://memory', 'r+' );

		$this->format->writeDocument(
			[
				'app_version' => '1.2.0',
				'instance_id' => 'ocabc123',
				'created_at'  => 1756500000,
				'slices'      => [
					'config',
					'hashes',
				],
			],
			[ 'rule_definitions' => '[]' ],
			[
				[
					'storage' => 'home::alice',
					'path'    => 'files/queued.txt',
					'state'   => 'pending:auto',
				],
			],
			[
				new HashRecord( 'home::alice', 'files/a.txt', 'sha256', str_repeat( 'a', 64 ), 1 ),
				new HashRecord( 'home::alice', 'files/b.txt', 'sha256', str_repeat( 'b', 64 ), null ),
			],
			$stream,
			new FormatOptions( pretty: $pretty ),
		);

		rewind( $stream );
		$text = stream_get_contents( $stream );
		fclose( $stream );

		return $text;
	}

	/**
	 * @return resource
	 */
	private function streamOf( string $text )
	{
		$stream = fopen( 'php://memory', 'r+' );
		fwrite( $stream, $text );
		rewind( $stream );

		return $stream;
	}
}
