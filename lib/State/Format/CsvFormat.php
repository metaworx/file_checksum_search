<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\State\Format;

use Generator;
use OCA\FileChecksumSearch\State\HashRecord;

/**
 * `storage,path,algo,hash,updated_at`, with a header row.
 *
 * Carries every field a record has, so it round-trips — but no header about
 * the file as a whole, so a reader cannot tell which instance or which app
 * version produced it. That is what makes it a data exchange rather than a
 * backup: fine for a spreadsheet or another tool, not something a restore
 * can sanity-check itself against.
 *
 * Columns are matched by name from the header, so a file with them in a
 * different order, or with extra columns, still reads. `storage` and
 * `updated_at` may be absent: the first when the caller anchors the paths
 * instead, the second when the source does not know when it hashed.
 */
class CsvFormat
    implements
    HashRecordFormat
{

//  constants

	/**
	 * No backslash escaping, in either direction.
	 *
	 * PHP's default is a non-standard extension that no spreadsheet and no
	 * RFC 4180 reader implements, and it would mangle a path that legitimately
	 * ends in a backslash. Passing it explicitly also settles PHP 8.4's
	 * deprecation of the implicit default.
	 */
	private const ESCAPE = '';

	private const COLUMNS
		 = [
			'storage',
			'path',
			'algo',
			'hash',
			'updated_at',
		];


//  other non-static methods

	/**
	 * @param  resource  $stream
	 *
	 * @return Generator<HashRecord>
	 */
	public function read(
		$stream,
		FormatOptions $options,
	): Generator
	{
		$header = fgetcsv( $stream, escape: self::ESCAPE );

		if ( $header === false )
		{
			return;
		}

		$header = array_map(
			static fn(
				$column,
			): string => strtolower( trim( (string) $column ) ),
			$header,
		);

		while ( ( $row = fgetcsv( $stream, escape: self::ESCAPE ) ) !== false )
		{
			// A blank line reads as one null field; skip rather than import
			// a record made entirely of absences.
			if ( $row === [ null ] )
			{
				continue;
			}

			$fields = [];

			foreach ( $header as $index => $column )
			{
				$fields[ $column ] = $row[ $index ] ?? null;
			}

			yield $options->anchor( HashRecord::fromArray( $fields ) );
		}
	}

	public function write(
		iterable      $records,
		              $stream,
		FormatOptions $options,
	): int
	{
		fputcsv( $stream, self::COLUMNS, escape: self::ESCAPE );
		$written = 0;

		foreach ( $records as $record )
		{
			fputcsv(
				$stream,
				[
					$record->storageId,
					$record->path,
					$record->algo,
					$record->hash,
					$record->updatedAt,
				],
				escape: self::ESCAPE,
			);
			$written ++;
		}

		return $written;
	}

	public function carriesConfig(): bool
	{
		return false;
	}

	public function losses(): array
	{
		return [
			'the app\'s configuration',
			'the header a restore checks itself against — which instance, which version',
		];
	}
}
