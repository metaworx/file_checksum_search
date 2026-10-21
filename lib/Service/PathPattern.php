<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

/**
 * A path pattern, as the duplicate listing's `path` filter reads it — the
 * same as the Duplicates page's selection (`src/duplicates-vue/pathPattern.ts`),
 * held to it by the cases in `tests/fixtures/path-patterns.json`:
 *
 * - `*` matches within a name, `**` across folders, `?` one character;
 * - a pattern without `/` matches a name anywhere: a file of that name, or a
 *   folder of that name and everything in it;
 * - one with `/` matches from the root: the path, or a folder on it and
 *   everything in it; a leading `/` is optional;
 * - a trailing `/` matches folders only;
 * - case is ignored.
 *
 * Not a rule's glob ({@see PathUtil}), whose `*` crosses folders: a rule
 * names an area, this a place a person sees.
 *
 * A version or a trashed row is matched by the path it shows, less its
 * `versions://` or `trash://`.
 */
readonly class PathPattern
{

//  constants

	/** The longest pattern taken: a path is seldom longer, and a pattern is typed. */
	public const MAX_LENGTH = 255;


//  constructor

	private function __construct(
		private string $regex,
	) {
	}


//  static methods

	/**
	 * The pattern compiled, or null where it is empty: no filter.
	 */
	public static function of( string $pattern ): ?self
	{
		$text        = trim( $pattern );
		$foldersOnly = str_ends_with( $text, '/' );
		$text        = rtrim( $text, '/' );
		$anchored    = str_contains( $text, '/' );
		$text        = ltrim( $text, '/' );

		if ( $text === '' )
		{
			return null;
		}

		$body   = '';
		$length = strlen( $text );

		for ( $i = 0; $i < $length; $i ++ )
		{
			$char = $text[ $i ];

			if ( $char === '*' && ( $text[ $i + 1 ] ?? '' ) === '*' )
			{
				// `**/` is any folders, none included; a lone `**` anything.
				while ( ( $text[ $i + 1 ] ?? '' ) === '*' )
				{
					$i ++;
				}

				if ( ( $text[ $i + 1 ] ?? '' ) === '/' )
				{
					$body .= '(?:.*/)?';
					$i ++;
				}
				else
				{
					$body .= '.*';
				}
			}
			elseif ( $char === '*' )
			{
				$body .= '[^/]*';
			}
			elseif ( $char === '?' )
			{
				$body .= '[^/]';
			}
			else
			{
				// Byte by byte: a multibyte character's bytes are no syntax,
				// and preg_quote leaves them as they are.
				$body .= preg_quote( $char, '#' );
			}
		}

		$start = $anchored
			? '^'
			: '(?:^|/)';
		$end   = $foldersOnly
			? '/.+$'
			: '(?:/.*)?$';

		return new self( '#' . $start . $body . $end . '#iu' );
	}

	/** The path a row is matched by: no scheme, no leading slash. */
	public static function subjectOf( string $path ): string
	{
		return ltrim( (string) preg_replace( '#^[a-z]+://#', '', $path ), '/' );
	}


//  other non-static methods

	public function matches( string $path ): bool
	{
		return preg_match( $this->regex, self::subjectOf( $path ) ) === 1;
	}

	/**
	 * Whether any of the paths matches: a group is kept where one of its
	 * rows is.
	 *
	 * @param  iterable<string>  $paths
	 */
	public function matchesAny( iterable $paths ): bool
	{
		foreach ( $paths as $path )
		{
			if ( $this->matches( $path ) )
			{
				return true;
			}
		}

		return false;
	}
}
