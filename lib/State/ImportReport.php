<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\State;

/**
 * What an import actually did.
 *
 * "Imported 4,000 hashes" is not a result anybody can act on: the questions
 * that matter afterwards are how many were refused for being older than the
 * file they describe, how many named files this instance does not have, and
 * how many were left alone because the file already had that algorithm. Each
 * of those is a different thing to go and fix.
 */
class ImportReport
{

	public int $written = 0;

	/** Refused: the record is older than the file it describes. */
	public int $skippedOutdated = 0;

	/** Left alone: the file already has that algorithm, and this is a merge. */
	public int $skippedExisting = 0;

	/** Named a path this instance has no file for. */
	public int $unknownPath = 0;

	/** Replaced a hash the file already had. */
	public int $overwritten = 0;

	/** Files whose `stale:` marker the import cleared by giving them hashes. */
	public int $markerCleared = 0;

	/** Records that said too little to use at all. */
	public int $malformed = 0;

	/** Configuration keys written. */
	public int $configWritten = 0;

	/**
	 * Configuration keys the lexicon does not declare, so they were refused
	 * rather than written — typically a backup from a newer version.
	 *
	 * @var list<string>
	 */
	public array $configRefused = [];


	/**
	 * @return array<string, int>
	 */
	public function toArray(): array
	{

		return [
			'written'          => $this->written,
			'skipped_outdated' => $this->skippedOutdated,
			'skipped_existing' => $this->skippedExisting,
			'unknown_path'     => $this->unknownPath,
			'overwritten'      => $this->overwritten,
			'marker_cleared'   => $this->markerCleared,
			'malformed'        => $this->malformed,
			'config_written'   => $this->configWritten,
		];
	}


	/**
	 * Whether anything at all reached storage.
	 */
	public function changedAnything(): bool
	{

		return $this->written > 0 || $this->overwritten > 0 || $this->configWritten > 0;
	}

}
