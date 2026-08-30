<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\State\Format;

use Generator;
use InvalidArgumentException;
use OCA\FileChecksumSearch\State\HashRecord;

/**
 * The native output of `sha1sum`, `md5sum`, `b2sum` and friends.
 *
 *     3b4e3162df23fa136219a915b5cccc654c7c75e2  Photos/Frog.jpg
 *
 * Two spaces separate hash from path for a text-mode read, one space and an
 * asterisk (` *`) for binary mode; both are accepted. The path may contain
 * spaces, so it is everything after the separator — split once, never
 * tokenised. A leading `\` marks a path GNU coreutils escaped because it
 * contained a newline or a backslash; those are unescaped on the way in.
 *
 * This is the format an instance's own filesystem can already produce, which
 * is the whole reason for accepting it — but it says the least of the three:
 * no algorithm, no timestamp, no storage. The caller supplies the first
 * through {@see FormatOptions::$algo} and the anchor through the others.
 */
class SumFormat
	implements
	HashRecordFormat
{

	/**
	 * @param  resource  $stream
	 *
	 * @return Generator<HashRecord>
	 */
	public function read(
		$stream,
		FormatOptions $options,
	): Generator {

		if ( $options->algo === null || $options->algo === '' )
		{
			throw new InvalidArgumentException(
				'A checksum listing does not name its algorithm; say which one it is.',
			);
		}

		while ( ( $line = fgets( $stream ) ) !== false )
		{
			$line = rtrim( $line, "\r\n" );

			if ( $line === '' )
			{
				continue;
			}

			$escaped = str_starts_with( $line, '\\' );

			if ( $escaped )
			{
				$line = substr( $line, 1 );
			}

			// "<hash>  <path>" or "<hash> *<path>"; the path keeps its spaces.
			$parts = preg_split( '/ [ *]/', $line, 2 );

			if ( $parts === false || count( $parts ) !== 2 )
			{
				continue;
			}

			[
				$hash,
				$path,
			]
				= $parts;

			if ( $escaped )
			{
				$path = $this->unescapePath( $path );
			}

			yield $options->anchor(
				new HashRecord(
					'',
					$path,
					strtolower( $options->algo ),
					strtolower( trim( $hash ) ),
				),
			);
		}
	}


	public function write(
		iterable      $records,
		              $stream,
		FormatOptions $options,
	): int {

		$written = 0;

		foreach ( $records as $record )
		{
			// One algorithm per listing, as the tools that read these expect.
			if ( $options->algo !== null && $record->algo !== strtolower( $options->algo ) )
			{
				continue;
			}

			$path = $record->path;
			$mark = '';

			if ( str_contains( $path, "\n" ) || str_contains( $path, '\\' ) )
			{
				$mark = '\\';
				$path = str_replace(
					[
						'\\',
						"\n",
					],
					[
						'\\\\',
						'\\n',
					],
					$path,
				);
			}

			fwrite( $stream, sprintf( "%s%s  %s\n", $mark, $record->hash, $path ) );
			$written ++;
		}

		return $written;
	}


	/**
	 * Undo the escaping coreutils applies to a path containing a newline or a
	 * backslash. One pass, left to right: a two-character replacement done
	 * with str_replace() cannot tell `\\n` — an escaped backslash followed by
	 * the letter n — from `\n`, an escaped newline.
	 */
	private function unescapePath( string $path ): string
	{

		$out    = '';
		$length = strlen( $path );

		for ( $index = 0; $index < $length; $index ++ )
		{
			$char = $path[ $index ];

			if ( $char !== '\\' || $index + 1 >= $length )
			{
				$out .= $char;

				continue;
			}

			$next = $path[ ++ $index ];
			$out  .= match ( $next )
			{
				'n' => "\n",
				'r' => "\r",
				'\\' => '\\',
				default => '\\' . $next,
			};
		}

		return $out;
	}


	public function carriesConfig(): bool
	{

		return false;
	}


	public function losses(): array
	{

		return [
			'the algorithm, which the reader must be told',
			'the time each hash was computed',
			'which storage each path belongs to',
			'the app\'s configuration',
		];
	}

}
