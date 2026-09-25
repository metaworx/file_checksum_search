<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

/**
 * Shared path-matching utilities.
 *
 * Centralizes fnmatch-based glob matching so the behavior is consistent
 * across the codebase and the underlying implementation can be swapped
 * without touching every call site.
 */
class PathUtil
{

//  static methods

	/**
	 * Match a file path against a glob pattern.
	 *
	 * @param  string  $pattern  Glob pattern (supports *, ?, [...])
	 * @param  string  $path     Absolute or relative file path
	 *
	 * @return bool True if path matches the pattern
	 */
	public static function matchesGlob(
		string $pattern,
		string $path,
	): bool
	{
		return fnmatch( $pattern, $path );
	}

	/**
	 * Match a namespace-relative path against a rule's glob, leading slash
	 * optional on both sides.
	 *
	 * Rule globs and canonical relative paths are the same coordinate
	 * system — the path below a home's files area, a group folder's root, or
	 * a storage's files area — but people write them both ways ('Photos/**'
	 * and '/Photos/**'). Stripping the leading slash from pattern and
	 * subject makes the two spellings one. An empty or '/' pattern means
	 * everything, same as '**'.
	 */
	public static function matchesRelativeGlob(
		string $pattern,
		string $relativePath,
	): bool
	{
		$pattern = ltrim( trim( $pattern ), '/' );

		if ( $pattern === '' )
		{
			$pattern = '**';
		}

		$subject = ltrim( $relativePath, '/' );

		if ( fnmatch( $pattern, $subject ) )
		{
			return true;
		}

		// A leading '**/' means "in any folder, however deep" — which
		// includes the root itself. fnmatch would insist on the literal
		// slash; the second try makes '**/*.pdf' match 'report.pdf' the
		// way every glob dialect users know does.
		return str_starts_with( $pattern, '**/' )
			&& fnmatch( substr( $pattern, 3 ), $subject );
	}
}
