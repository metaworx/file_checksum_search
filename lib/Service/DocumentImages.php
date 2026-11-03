<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\IURLGenerator;

/**
 * A bundled markdown document as the app's pages show it: each image it
 * names by a relative path is pointed at the app's own copy.
 *
 * The server's image policy admits the server alone, so a picture from the
 * project's site never shows on a page; one the app serves does, offline
 * too, and is the installed version's. The documents keep relative links,
 * which GitLab and GitHub resolve the same way.
 *
 * A target is resolved from the document's own directory, and rewritten
 * only where it names an image inside the app's directory: a link to a
 * document, an anchor, an absolute URL or a file that is not there stays as
 * it is. Both forms are read, a reference definition (`[name]: path`) and
 * an inline image (`![alt](path)`).
 */
readonly class DocumentImages
{

//  constants

	/** The extensions of the images a document shows. */
	private const EXTENSIONS = [ 'png', 'gif', 'jpg', 'jpeg', 'svg', 'webp' ];


//  constructor

	public function __construct(
		private IURLGenerator $urlGenerator,
	)
	{
	}


//  other non-static methods

	/**
	 * The document with its images' relative targets made the app's web
	 * paths.
	 *
	 * @param  string  $markdown  The document's text.
	 * @param  string  $docPath   Its path inside the app, as `docs/FAQ.md`.
	 * @param  string  $appRoot   The app's directory.
	 */
	public function servedByTheApp(
		string $markdown,
		string $docPath,
		string $appRoot,
	): string
	{
		$root = realpath( $appRoot );

		if ( $root === false )
		{
			return $markdown;
		}

		$dir     = dirname( $docPath );
		$base    = $root . ( $dir === '.' ? '' : '/' . $dir );
		$replace = fn( array $match ): string => $match[1] . ( $this->appPath( $match[2], $base, $root ) ?? $match[2] ) . $match[3];

		// `[name]: target`, up to three spaces in, the target maybe in <>.
		$markdown = preg_replace_callback( '/^( {0,3}\[[^\]\n]+\]:[ \t]*<?)([^\s<>]+)(>?)/m', $replace, $markdown ) ?? $markdown;

		// `![alt](target`, the alt text maybe over several lines.
		return preg_replace_callback( '/(!\[[^\]]*\]\(<?)([^\s()<>]+)(>?)/', $replace, $markdown ) ?? $markdown;
	}

	/**
	 * The app's web path of the image a relative target names, or null where
	 * it names none inside the app's directory.
	 */
	private function appPath(
		string $target,
		string $base,
		string $root,
	): ?string
	{
		// A scheme, an absolute path or an anchor.
		if ( preg_match( '~^([a-z][a-z0-9+.-]*:|/|#)~i', $target ) === 1 )
		{
			return null;
		}

		if ( ! in_array( strtolower( pathinfo( $target, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true ) )
		{
			return null;
		}

		$file = realpath( $base . '/' . $target );

		if ( $file === false || ! is_file( $file ) || ! str_starts_with( $file, $root . '/' ) )
		{
			return null;
		}

		return $this->urlGenerator->linkTo( Application::APP_ID, substr( $file, strlen( $root ) + 1 ) );
	}
}
