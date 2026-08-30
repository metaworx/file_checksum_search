<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\State\Format;

use Generator;
use OCA\FileChecksumSearch\State\HashRecord;
use RuntimeException;

/**
 * The backup format — the only one of the three that is one.
 *
 * A document is an object whose leading keys describe the file and whose last
 * key carries the records:
 *
 *     {
 *       "schema": 1,
 *       "app": "file_checksum_search",
 *       "app_version": "1.2.0",
 *       "instance_id": "ocabc123",
 *       "created_at": 1756500000,
 *       "slices": ["config", "hashes"],
 *       "config": { "rule_definitions": "[]" },
 *       "hashes": [
 *         {"storage": "home::alice", "path": "files/a.txt", "algo": "sha256", "hash": "…", "updated_at": 1756400000}
 *       ]
 *     }
 *
 * That header is what makes it a backup rather than a hash table: a restore
 * can see which schema, which app version and which instance it is holding,
 * and refuse a file it cannot honour. `csv` and `sum` carry the same records
 * with none of that, which is why `--config` is meaningless in them.
 *
 * **`hashes` must be the document's last key.** Neither writing nor reading
 * holds an instance's worth of records in memory — they are streamed out one
 * at a time and pulled back in one at a time by {@see JsonCursor} — and a
 * reader that must reach a key *after* the record array would have to buffer
 * the array to get there. The writer always emits it last; the reader says so
 * plainly when a hand-made document does not.
 */
class JsonFormat
	implements
	HashRecordFormat
{

	/**
	 * Bumped when the document's shape changes in a way an older reader
	 * cannot honour. A restore compares this before it writes anything.
	 */
	public const SCHEMA_VERSION = 1;

	/** The key whose array is streamed, and therefore must come last. */
	public const RECORDS_KEY = 'hashes';


	/**
	 * @param  resource  $stream
	 *
	 * @return Generator<HashRecord>
	 */
	public function read(
		$stream,
		FormatOptions $options,
	): Generator {

		yield from $this->readDocument( $stream, $options )['records'];
	}


	/**
	 * Read a document whole: its header and config eagerly — they are small —
	 * and its records as a generator, which is the part that is not.
	 *
	 * The records generator must be consumed before the stream is closed; the
	 * header is available before a single record has been read, which is what
	 * lets a restore refuse the file without parsing it.
	 *
	 * @param  resource  $stream
	 *
	 * @return array{header: array<string, mixed>, config: array<string, string>|null, records: Generator<HashRecord>}
	 */
	public function readDocument(
		$stream,
		FormatOptions $options,
	): array {

		$cursor = new JsonCursor( $stream );
		$cursor->skipWhitespace();
		$opener = $cursor->peek();

		// A bare array is a hashes-only document with no header — accepted on
		// the way in, never produced on the way out.
		if ( $opener === '[' )
		{
			$cursor->expect( '[' );

			return [
				'header'  => [],
				'config'  => null,
				'records' => $this->readRecords( $cursor, $options ),
			];
		}

		$cursor->expect( '{' );

		$header = [];
		$config = null;

		while ( true )
		{
			$cursor->skipWhitespace();

			if ( $cursor->peek() === '}' )
			{
				$cursor->expect( '}' );

				// No record key at all: a config-only backup.
				return [
					'header'  => $header,
					'config'  => $config,
					'records' => $this->emptyRecords(),
				];
			}

			$key = $cursor->readString();
			$cursor->expect( ':' );

			if ( $key === self::RECORDS_KEY )
			{
				$cursor->expect( '[' );

				return [
					'header'  => $header,
					'config'  => $config,
					'records' => $this->readRecords( $cursor, $options ),
				];
			}

			$value = $cursor->readValue();

			if ( $key === 'config' )
			{
				$config = is_array( $value )
					? array_map( static fn(
						$entry,
					): string => (string) $entry, $value )
					: null;
			}
			else
			{
				$header[ $key ] = $value;
			}

			$cursor->skipWhitespace();

			if ( $cursor->peek() === ',' )
			{
				$cursor->expect( ',' );
			}
		}
	}


	public function write(
		iterable      $records,
		              $stream,
		FormatOptions $options,
	): int {

		return $this->writeDocument( [], null, $records, $stream, $options );
	}


	/**
	 * Write a full document: header, optional config, then the records.
	 *
	 * The header the caller supplies is merged over the two fields the format
	 * knows about itself, so a caller cannot accidentally write a document
	 * that does not say which schema it is.
	 *
	 * @param  array<string, mixed>        $header
	 * @param  array<string, string>|null  $config   Null when the backup carries no config slice.
	 * @param  ?iterable                   $records  {@see HashRecord}s; null for a document
	 *                                               with no hashes slice at all.
	 * @param  resource                    $stream
	 * @param  FormatOptions               $options
	 *
	 * @return int  Records written.
	 */
	public function writeDocument(
		array         $header,
		?array        $config,
		?iterable     $records,
		              $stream,
		FormatOptions $options,
	): int {

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			| ( $options->pretty
				? JSON_PRETTY_PRINT
				: 0 );

		$newline = $options->pretty
			? "\n"
			: '';
		$indent  = $options->pretty
			? '    '
			: '';

		$header = [
				'schema' => self::SCHEMA_VERSION,
				'app'    => 'file_checksum_search',
			]
			+ $header;

		fwrite( $stream, '{' . $newline );

		foreach ( $header as $key => $value )
		{
			fwrite(
				$stream,
				$indent . json_encode( $key ) . ':' . ( $options->pretty
					? ' '
					: '' )
				. $this->encode( $value, $flags, $indent ) . ',' . $newline,
			);
		}

		if ( $config !== null )
		{
			fwrite(
				$stream,
				$indent . '"config":' . ( $options->pretty
					? ' '
					: '' )
				. $this->encode( $config, $flags | JSON_FORCE_OBJECT, $indent ) . ',' . $newline,
			);
		}

		// Last, always: everything after it would have to be read past the
		// whole array. See the class docblock.
		fwrite(
			$stream,
			$indent . '"' . self::RECORDS_KEY . '":' . ( $options->pretty
				? ' '
				: '' ) . '[',
		);

		$written = 0;

		foreach ( $records ?? [] as $record )
		{
			fwrite(
				$stream,
				( $written > 0
					? ','
					: '' ) . $newline . $indent . $indent
				. json_encode( $record->toArray(), $flags & ~JSON_PRETTY_PRINT ),
			);
			$written ++;
		}

		if ( $written > 0 )
		{
			fwrite( $stream, $newline . $indent );
		}

		fwrite( $stream, ']' . $newline . '}' . $newline );

		return $written;
	}


	/**
	 * Encode one header value at the depth it sits at.
	 *
	 * `json_encode()` pretty-prints from column zero, which puts the second
	 * line of a nested array flush against the left margin of a document that
	 * is already one level in. Re-indenting the continuation lines is all it
	 * takes to make the result read as the object it is.
	 */
	private function encode(
		mixed  $value,
		int    $flags,
		string $indent,
	): string {

		$encoded = json_encode( $value, $flags );

		if ( $indent === '' )
		{
			return $encoded;
		}

		return str_replace( "\n", "\n" . $indent, $encoded );
	}


	public function carriesConfig(): bool
	{

		return true;
	}


	public function losses(): array
	{

		return [];
	}


	/**
	 * Pull records out of the array the cursor is standing inside, one at a
	 * time, and stop at its closing bracket.
	 *
	 * @return Generator<HashRecord>
	 */
	private function readRecords(
		JsonCursor    $cursor,
		FormatOptions $options,
	): Generator {

		$cursor->skipWhitespace();

		if ( $cursor->peek() === ']' )
		{
			$cursor->expect( ']' );

			return;
		}

		while ( true )
		{
			$row = $cursor->readValue();

			if ( ! is_array( $row ) )
			{
				throw new RuntimeException( 'A record in the hashes array is not an object.' );
			}

			yield $options->anchor( HashRecord::fromArray( $row ) );

			$cursor->skipWhitespace();
			$next = $cursor->peek();

			if ( $next === ',' )
			{
				$cursor->expect( ',' );

				continue;
			}

			$cursor->expect( ']' );

			$cursor->skipWhitespace();

			if ( $cursor->peek() === ',' )
			{
				throw new RuntimeException(
					sprintf(
						'"%s" must be the last key of a backup document; it is streamed, so nothing can be read past it.',
						self::RECORDS_KEY,
					),
				);
			}

			return;
		}
	}


	/**
	 * @return Generator<HashRecord>
	 */
	private function emptyRecords(): Generator
	{

		yield from [];
	}

}
