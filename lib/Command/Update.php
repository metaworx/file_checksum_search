<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Vendor\mwx\Nextcloud\App\Update\UpdateCommand;

/**
 * Updates the app from the store, to a stable or a nightly release: reads
 * the store, clears the server's cached list of it, and lets the server's
 * own installer download, verify and install.
 *
 * The app has it because the server's own paths cannot reach a nightly,
 * and every release below 1.0.0 reaches the store as one: the Apps page
 * never offers a nightly, and `occ app:update --allow-unstable` reads the
 * fetcher's cached list, filtered for stable releases, until the store's
 * list changes. The command is the scoped package's; this names the app,
 * the command and its alias.
 *
 * Exits 0 when it updated, or under `--check` when there is something to
 * take; 1 when there is nothing to take; 2 on a failure.
 *
 * @noinspection PhpUnused
 */
class Update
    extends
    UpdateCommand
{

//  other non-static methods

	#[\Override]
	protected function appId(): string
	{
		return Application::APP_ID;
	}

	#[\Override]
	protected function commandName(): string
	{
		return 'file-checksum-search:update';
	}

	#[\Override]
	protected function commandAliases(): array
	{
		return [ 'fcias:update' ];
	}
}
