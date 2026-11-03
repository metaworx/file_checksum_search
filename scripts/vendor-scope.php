<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

/*
 * Puts what Strauss scoped into lib/Vendor where the server loads it from;
 * `composer vendor:scope` runs it after Strauss.
 *
 * Strauss copies each package under its own path, lib/Vendor/<vendor>/
 * <package>/src/..., and writes an autoloader of its own beside it. The
 * server loads `OCA\FileChecksumSearch\` from lib/ by PSR-4 and nothing
 * else, so a scoped class has to sit at its namespace's path:
 * `OCA\FileChecksumSearch\Vendor\mwx\Nextcloud\App\Update\Release` at
 * lib/Vendor/mwx/Nextcloud/App/Update/Release.php.
 *
 * For each package in composer.json's `extra.strauss.packages`, every
 * directory of the package's own `autoload.psr-4` map is moved to its
 * namespace's path under lib/Vendor, replacing what an earlier run put
 * there, and the package's licence, which Strauss copies whatever the
 * exclusions say, goes to the top of that path, since the copy is still
 * licensed as the package is; then the package's copy, Strauss's
 * autoload.php and its composer/ directory are deleted. A file it cannot
 * place fails the run, and lib/Vendor is left for Strauss to write again.
 *
 * Strauss writes the package from vendor/ afresh on every run, so running
 * both again changes nothing.
 */

$root   = dirname( __DIR__ );
$vendor = $root . '/vendor';

/**
 * Ends the run with a message on stderr.
 */
$fail = static function( string $message ): never
{
	fwrite( STDERR, 'vendor-scope: ' . $message . PHP_EOL );

	exit( 1 );
};

/**
 * A JSON file's content as an array, or the run fails.
 *
 * @return array<array-key, mixed>
 */
$readJson = static function( string $path ) use ( $fail ): array
{
	$json = is_file( $path ) ? file_get_contents( $path ) : false;
	$data = is_string( $json ) ? json_decode( $json, true ) : null;

	return is_array( $data ) ? $data : $fail( 'cannot read ' . $path );
};

/**
 * Every file under a directory, by its path relative to it, sorted.
 *
 * @return list<string>
 */
$filesUnder = static function( string $directory ): array
{
	$files = [];
	$found = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) );

	/** @var SplFileInfo $file */
	foreach ( $found as $file )
	{
		$files[] = substr( $file->getPathname(), strlen( $directory ) + 1 );
	}

	sort( $files );

	return $files;
};

/**
 * Deletes a file, or a directory with everything in it.
 */
$remove = static function( string $path ) use ( &$remove, $fail ): void
{
	if ( is_dir( $path ) && ! is_link( $path ) )
	{
		foreach ( scandir( $path ) ?: [] as $entry )
		{
			if ( $entry !== '.' && $entry !== '..' )
			{
				$remove( $path . '/' . $entry );
			}
		}

		rmdir( $path ) || $fail( 'cannot delete ' . $path );
	}
	elseif ( file_exists( $path ) || is_link( $path ) )
	{
		unlink( $path ) || $fail( 'cannot delete ' . $path );
	}
};

$strauss  = $readJson( $root . '/composer.json' )['extra']['strauss'] ?? null;
$target   = is_array( $strauss ) && is_string( $strauss['target_directory'] ?? null ) ? $strauss['target_directory'] : $fail( 'no extra.strauss.target_directory in composer.json' );
$packages = is_array( $strauss['packages'] ?? null ) ? $strauss['packages'] : $fail( 'no extra.strauss.packages in composer.json' );
$target   = $root . '/' . trim( $target, '/' );

foreach ( $packages as $package )
{
	$copy = $target . '/' . $package;
	$psr4 = $readJson( $vendor . '/' . $package . '/composer.json' )['autoload']['psr-4'] ?? [];

	if ( ! is_dir( $copy ) )
	{
		$fail( 'Strauss wrote no ' . $copy );
	}

	if ( ! is_array( $psr4 ) || $psr4 === [] )
	{
		$fail( $package . ' has no autoload.psr-4 to place its files by' );
	}

	// Where the licence goes: the top of the package's shortest namespace path.
	$top = null;

	foreach ( $psr4 as $namespace => $directories )
	{
		if ( trim( (string) $namespace, '\\' ) === '' )
		{
			$fail( $package . ' maps the global namespace, which has no path of its own under ' . $target );
		}

		$place = $target . '/' . str_replace( '\\', '/', trim( (string) $namespace, '\\' ) );
		$top   = $top === null || strlen( $place ) < strlen( $top ) ? $place : $top;

		// What an earlier run placed, so that a class the package dropped
		// goes too.
		$remove( $place );

		foreach ( (array) $directories as $directory )
		{
			$source = $copy . '/' . trim( (string) $directory, '/' );

			if ( ! is_dir( $source ) )
			{
				$fail( 'Strauss wrote no ' . $source . ' for ' . $namespace );
			}

			foreach ( $filesUnder( $source ) as $file )
			{
				$to = $place . '/' . $file;

				if ( file_exists( $to ) )
				{
					$fail( 'two files for ' . $to . ': ' . $package . ' maps it twice' );
				}

				if ( ! is_dir( dirname( $to ) ) && ! mkdir( dirname( $to ), 0o755, true ) )
				{
					$fail( 'cannot create ' . dirname( $to ) );
				}

				rename( $source . '/' . $file, $to ) || $fail( 'cannot move ' . $source . '/' . $file . ' to ' . $to );
			}
		}
	}

	foreach ( $filesUnder( $copy ) as $file )
	{
		if ( preg_match( '#^(LICEN[CS]E|COPYING)(\.[A-Za-z]+)?$#', $file ) === 1 && $top !== null )
		{
			rename( $copy . '/' . $file, $top . '/' . $file ) || $fail( 'cannot move ' . $copy . '/' . $file . ' to ' . $top . '/' . $file );
		}
	}

	$left = $filesUnder( $copy );

	if ( $left !== [] )
	{
		$fail( 'no namespace of ' . $package . ' places ' . implode( ', ', $left ) . ' (in ' . $copy . ')' );
	}

	$remove( $copy );

	// The vendor's directory, once its last package is placed.
	$owner = dirname( $copy );

	if ( $owner !== $target && is_dir( $owner ) && ( scandir( $owner ) ?: [] ) === [ '.', '..' ] )
	{
		$remove( $owner );
	}
}

// Strauss's own autoloader: the server's PSR-4 loads the files where they now are.
$remove( $target . '/autoload.php' );
$remove( $target . '/composer' );

echo 'vendor-scope: placed ' . implode( ', ', $packages ) . ' under ' . substr( $target, strlen( $root ) + 1 ) . PHP_EOL;
