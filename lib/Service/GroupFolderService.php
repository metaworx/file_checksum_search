<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Soft-dependency bridge to the groupfolders app.
 *
 * The groupfolders app is optional; nothing here may fail when it is
 * missing. Its tables are the authoritative source for which folders exist
 * and what they are called — storage ids alone cannot tell a group folder
 * from a local external mount ({@see FileLocation}), and mount names are
 * the only human-readable identity a folder has.
 *
 * Not readonly: controller tests double this class, and PHPUnit cannot
 * mock readonly classes (TESTING.md §6.1).
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class GroupFolderService
{

//  constants

	private const GROUPFOLDERS_APP_ID = 'groupfolders';


//  constructor

	public function __construct(
		private readonly IAppManager     $appManager,
		private readonly IDBConnection   $db,
		private readonly LoggerInterface $logger,
	) {
	}


//  getters / setters / is* / has*

	/**
	 * Whether the groupfolders app is installed and enabled — the gate for
	 * offering groupfolder: selectors anywhere in the UI.
	 */
	public function isAvailable(): bool
	{
		try
		{
			return $this->appManager->isEnabledForUser( self::GROUPFOLDERS_APP_ID );
		}
		catch ( Throwable )
		{
			return false;
		}
	}


//  other non-static methods

	/**
	 * What the groupfolders app calls itself — "Team Folders" on current
	 * releases — so this app speaks the same language the rest of the
	 * settings UI does. Null when the app is unavailable: there is no app
	 * to ask, and the caller decides how to name what is missing.
	 */
	public function appName(): ?string
	{
		if ( ! $this->isAvailable() )
		{
			return null;
		}

		try
		{
			$name = (string) ( $this->appManager->getAppInfo( self::GROUPFOLDERS_APP_ID )['name'] ?? '' );

			return $name !== ''
				? $name
				: 'Team folders';
		}
		catch ( Throwable )
		{
			return 'Team folders';
		}
	}

	/**
	 * The folders that exist, as id => mount point name, sorted by name.
	 *
	 * Empty when the app is unavailable or its schema is not what this
	 * release expects — a picker with nothing in it, never an error.
	 *
	 * @return list<array{id: int, name: string}>
	 */
	public function listFolders(): array
	{
		if ( ! $this->isAvailable() )
		{
			return [];
		}

		try
		{
			$qb = $this->db->getQueryBuilder();
			$qb->select( 'folder_id', 'mount_point' )
			   ->from( 'group_folders' )
			   ->orderBy( 'mount_point', 'ASC' )
			;

			$result  = $qb->executeQuery();
			$folders = [];

			while ( ( $row = $result->fetch() ) !== false )
			{
				$folders[] = [
					'id'   => (int) $row['folder_id'],
					'name' => (string) $row['mount_point'],
				];
			}

			$result->closeCursor();

			return $folders;
		}
		catch ( Throwable $e )
		{
			$this->logger->debug(
				'FCIAS: could not list group folders — offering none.',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return [];
		}
	}
}
