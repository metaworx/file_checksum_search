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

	/**
	 * Match a file path against a glob pattern.
	 *
	 * @param string $pattern Glob pattern (supports *, ?, [...])
	 * @param string $path    Absolute or relative file path
	 *
	 * @return bool True if path matches the pattern
	 */
	public static function matchesGlob( string $pattern, string $path ): bool
	{

		return fnmatch( $pattern, $path );
	}

}
