<?php

declare( strict_types=1 );

/**
 * @copyright    Copyright (c) 2026 metaworx
 * @license      AGPL-3.0-or-later
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */

namespace OCA\FileChecksumSearch\Service;

use Doctrine\DBAL\Connection;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * What the status asks of the database itself, rather than of the app's
 * tables: its version.
 *
 * A failed query is not an error the status should fail on: safeString()
 * catches any Throwable, logs it as a warning, optionally echoes it to
 * $output, and answers 'unknown' — indistinguishable from a database that
 * really reports that, so a caller that must tell them apart reads the log.
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class DatabaseService
{

//  constructor

	public function __construct(
		private readonly IDBConnection   $db,
		private readonly LoggerInterface $logger,
	) {
	}


//  getters / setters / is* / has*

	/**
	 * The database server's version string (e.g. "10.11.6-MariaDB").
	 *
	 * @return string  The version string, or 'unknown' if the query failed.
	 */
	public function getDatabaseVersion( ?OutputInterface $output = null ): string
	{
		return $this->safeString(
			fn() => $this->getRawConnection()
			             ->executeQuery( 'SELECT VERSION() AS version' )
			             ->fetchOne(),
			$output,
		);
	}

	/** The underlying Doctrine DBAL connection, for calls IDBConnection doesn't expose. */
	public function getRawConnection(): Connection
	{
		return $this->db->getInner();
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
