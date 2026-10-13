<?php

declare( strict_types=1 );

/**
 * @copyright    Copyright (c) 2026 metaworx
 * @license      AGPL-3.0-or-later
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * What the status asks of the database itself, rather than of the app's
 * tables: which database it is, and its version.
 *
 * A failed query is not an error the status should fail on: safeString()
 * catches any Throwable, logs it as a warning, optionally echoes it to
 * $output, and answers 'unknown'.
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class DatabaseService
{

//  constants

	/** Each backend's name, by what IDBConnection::getDatabaseProvider( true ) answers. */
	private const NAMES = [
		IDBConnection::PLATFORM_MARIADB  => 'MariaDB',
		IDBConnection::PLATFORM_MYSQL    => 'MySQL',
		IDBConnection::PLATFORM_POSTGRES => 'PostgreSQL',
		IDBConnection::PLATFORM_SQLITE   => 'SQLite',
		IDBConnection::PLATFORM_ORACLE   => 'Oracle',
	];


//  constructor

	public function __construct(
		private readonly IDBConnection   $db,
		private readonly LoggerInterface $logger,
	) {
	}


//  getters / setters / is* / has*

	/**
	 * The database by name and version: "MariaDB 10.11.6", "MySQL 8.0.39",
	 * "PostgreSQL 16.4", "SQLite 3.45.1".
	 *
	 * Each backend is asked in its own terms, as Nextcloud's serverinfo app
	 * asks: SQLite has no VERSION(), only sqlite_version(). Each answers in
	 * its own form, too — MariaDB "10.11.6-MariaDB-ubu2204", PostgreSQL
	 * "PostgreSQL 16.4 on x86_64-pc-linux-gnu, compiled by …" — so the
	 * number is taken out of it. Nextcloud's own server-version call would
	 * spare the query, but it is on a private class, marked internal.
	 *
	 * @return string  The name and version, or the name and "unknown" where
	 *                 the query failed or answered no number.
	 */
	public function getDatabaseVersion( ?OutputInterface $output = null ): string
	{
		$provider = $this->db->getDatabaseProvider( true );
		$answer   = $this->safeString(
			fn(): string => (string) $this->db->executeQuery(
				$provider === IDBConnection::PLATFORM_SQLITE
					? 'SELECT sqlite_version()'
					: 'SELECT VERSION()',
			)
			                                  ->fetchOne(),
			$output,
		);

		return ( self::NAMES[ $provider ] ?? $provider ) . ' '
			. ( preg_match( '/\d+(?:\.\d+)*/', $answer, $version ) === 1 ? $version[0] : 'unknown' );
	}


//  other non-static methods

	private function safeString(
		callable         $fn,
		?OutputInterface $output,
	): string
	{
		try
		{
			return $fn();
		}
		catch ( Throwable $e )
		{
			// The error stream where the console has one; a buffered or null
			// output has only the one stream, and no getErrorOutput().
			$errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
			$errors?->writeln( sprintf( '<error>%s</error>', $e->getMessage() ) );

			$this->logger->warning(
				'FCIAS: database query failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return 'unknown';
		}
	}
}
