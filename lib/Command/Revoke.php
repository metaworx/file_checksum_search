<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\Service\ExportService;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Revoke every checksum this app has stored.
 *
 * {@see Reset} with the hashes alone: the same plan first, the same `--force`
 * and `--backup`, the same audit line. `fcias:reset --hashes` was its name.
 *
 * @noinspection PhpUnused
 */
class Revoke
    extends
    Reset
{

//  config/init/exe/run methods

	/**
	 * @noinspection PhpUnused
	 */
	#[\Override]
	protected function configure(): void
	{
		$this->setName( 'file-checksum-search:revoke' )
		     ->setAliases( [ 'fcias:revoke' ] )
		     ->setDescription( 'Revoke every checksum this app has stored' )
		;

		$this->addCarryOutOptions()
		     ->setHelp(
			     <<<'HELP'
The <info>%command.name%</info> command revokes every checksum this app has stored.

<comment>It reports and changes nothing until you add --force.</comment> What it removes cannot be
recovered by any other means: the hashes took an instance-wide read to compute.

Revoking marks the hashes rather than deleting them there and then. Each file is
marked <comment>stale:revoked</comment>, which takes its hashes out of search and out of
duplicate groups immediately, and the background job clears them as it goes —
one database write per thousand files rather than one document rewrite each —
queueing again the files a rule still covers. <info>--now</info> does the clearing in the
foreground instead, which is what a test fixture wants and what an impatient
operator with a small instance may prefer.

Examples:

  <info>occ fcias:revoke</info>                                 what would happen
  <info>occ fcias:revoke --force</info>                         revoke every checksum
  <info>occ fcias:revoke --force --backup=/backups/before.json</info>
HELP,
		     )
		;
	}


//  other non-static methods

	/**
	 * The hashes, whatever was named.
	 *
	 * @return list<string>
	 */
	#[\Override]
	protected function slices( InputInterface $input ): array
	{
		return [ ExportService::SLICE_HASHES ];
	}
}
