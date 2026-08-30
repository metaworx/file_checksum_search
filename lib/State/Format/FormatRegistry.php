<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\State\Format;

use InvalidArgumentException;

/**
 * Resolves a `--format` option to the one implementation that handles it.
 *
 * One list, used by both commands, so `--format` means the same thing when
 * writing a file as when reading one back.
 */
class FormatRegistry
{

	public const FORMAT_JSON = 'json';

	public const FORMAT_CSV = 'csv';

	public const FORMAT_SUM = 'sum';


	/**
	 * @return list<string>
	 */
	public static function names(): array
	{

		return [
			self::FORMAT_JSON,
			self::FORMAT_CSV,
			self::FORMAT_SUM,
		];
	}


	public function get( string $name ): HashRecordFormat
	{

		return match ( strtolower( trim( $name ) ) )
		{
			self::FORMAT_JSON => new JsonFormat(),
			self::FORMAT_CSV => new CsvFormat(),
			self::FORMAT_SUM => new SumFormat(),
			default => throw new InvalidArgumentException(
				sprintf(
					'Unknown format "%s"; expected one of %s.',
					$name,
					implode( ', ', self::names() ),
				),
			),
		};
	}


	/**
	 * The format a filename suggests, or null where it suggests nothing —
	 * so `-o backup.csv` does not have to be told twice.
	 */
	public function guessFromPath( string $path ): ?string
	{

		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) )
		{
			'json' => self::FORMAT_JSON,
			'csv' => self::FORMAT_CSV,
			'sum', 'sha1', 'sha256', 'sha512', 'md5', 'txt' => self::FORMAT_SUM,
			default => null,
		};
	}

}
