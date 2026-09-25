<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\State\Format;

use InvalidArgumentException;
use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\SumFormat;
use OCA\FileChecksumSearch\State\HashRecord;
use PHPUnit\Framework\TestCase;

/**
 * The shapes `sha1sum` and its relatives actually produce.
 *
 * This format exists to accept files the instance's own shell already makes,
 * so what it must read is not a specification of our choosing — it is
 * whatever coreutils writes.
 */
class SumFormatTest
    extends
    TestCase
{

//  private properties

	private SumFormat $format;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();
		$this->format = new SumFormat();
	}


//  other non-static methods

	/**
	 * @dataProvider coreutilsLines
	 */
	public function testTheLineShapesCoreutilsWrites(
		string $line,
		string $expectedPath,
	): void
	{
		$records = $this->read( $line, new FormatOptions( algo: 'sha1' ) );

		$this->assertCount( 1, $records );
		$this->assertSame( $expectedPath, $records[0]->path );
		$this->assertSame( str_repeat( 'a', 40 ), $records[0]->hash );
	}


//  static methods

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function coreutilsLines(): array
	{
		$hash = str_repeat( 'a', 40 );

		return [
			'text mode, two spaces'       => [
				$hash . "  Photos/a.jpg\n",
				'Photos/a.jpg',
			],
			'binary mode, asterisk'       => [
				$hash . " *Photos/a.jpg\n",
				'Photos/a.jpg',
			],
			'path containing spaces'      => [
				$hash . "  Photos/a b  c.jpg\n",
				'Photos/a b  c.jpg',
			],
			'path containing an asterisk' => [
				$hash . "  Photos/*star*.jpg\n",
				'Photos/*star*.jpg',
			],
			'escaped newline'             => [
				'\\' . $hash . "  Photos/two\\nlines.jpg\n",
				"Photos/two\nlines.jpg",
			],
			'escaped backslash'           => [
				'\\' . $hash . "  Photos/back\\\\slash.jpg\n",
				'Photos/back\\slash.jpg',
			],
			'CRLF line ending'            => [
				$hash . "  Photos/a.jpg\r\n",
				'Photos/a.jpg',
			],
			'uppercase hash'              => [
				strtoupper( $hash ) . "  Photos/a.jpg\n",
				'Photos/a.jpg',
			],
		];
	}

	/**
	 * `\\n` — an escaped backslash followed by the letter n — is not an
	 * escaped newline, and a two-step string replacement cannot tell them
	 * apart. The scan is left to right for exactly this line.
	 */
	public function testAnEscapedBackslashBeforeAnNIsNotANewline(): void
	{
		$records = $this->read(
			'\\' . str_repeat( 'a', 40 ) . "  dir\\\\name.txt\n",
			new FormatOptions( algo: 'sha1' ),
		);

		$this->assertSame( 'dir\\name.txt', $records[0]->path );
	}

	public function testBlankAndUnparseableLinesAreSkipped(): void
	{
		$records = $this->read(
			"\n"
			. str_repeat( 'a', 40 ) . "  a.txt\n"
			. "not a checksum line at all\n"
			. "\n"
			. str_repeat( 'b', 40 ) . "  b.txt\n",
			new FormatOptions( algo: 'sha1' ),
		);

		$this->assertSame(
			[
				'a.txt',
				'b.txt',
			],
			array_map( static fn(
				HashRecord $record,
			): string => $record->path, $records ),
		);
	}

	/**
	 * The one thing a checksum listing cannot tell you about itself.
	 */
	public function testReadingWithoutAnAlgorithmIsRefused(): void
	{
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/algorithm/' );

		$this->read( str_repeat( 'a', 40 ) . "  a.txt\n", new FormatOptions() );
	}

	public function testWritingEscapesTheSameWayCoreutilsDoes(): void
	{
		$stream = fopen( 'php://memory', 'r+' );
		$this->format->write(
			[
				new HashRecord( 's', "two\nlines.txt", 'sha1', str_repeat( 'a', 40 ) ),
				new HashRecord( 's', 'plain.txt', 'sha1', str_repeat( 'b', 40 ) ),
			],
			$stream,
			new FormatOptions( algo: 'sha1' ),
		);
		rewind( $stream );
		$written = stream_get_contents( $stream );
		fclose( $stream );

		$this->assertSame(
			'\\' . str_repeat( 'a', 40 ) . "  two\\nlines.txt\n"
			. str_repeat( 'b', 40 ) . "  plain.txt\n",
			$written,
		);
	}

	public function testItSaysWhatItCannotCarry(): void
	{
		$this->assertFalse( $this->format->carriesConfig() );
		$this->assertNotEmpty( $this->format->losses() );
	}

	/**
	 * @return list<HashRecord>
	 */
	private function read(
		string        $text,
		FormatOptions $options,
	): array
	{
		$stream = fopen( 'php://memory', 'r+' );
		fwrite( $stream, $text );
		rewind( $stream );
		$records = iterator_to_array( $this->format->read( $stream, $options ), false );
		fclose( $stream );

		return $records;
	}
}
