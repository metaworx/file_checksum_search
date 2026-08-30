<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\State\Format;

use Generator;
use OCA\FileChecksumSearch\State\HashRecord;

/**
 * One serialisation of hash records, readable and writable.
 *
 * Both directions live behind one interface so that every format the import
 * accepts, the backup can produce — and so a round trip through any of them
 * is one test rather than two that can drift apart.
 *
 * The formats are not equal, and the asymmetry is the point:
 *
 * - **json** is the only one that is a *backup*. It alone carries the header
 *   — schema version, app version, instance id — that lets a restore refuse a
 *   file it cannot honour.
 * - **csv** carries every field but no header, so it round-trips the data
 *   while telling a reader nothing about where it came from.
 * - **sum** is the native output of `sha1sum` and its relatives: hash and
 *   path, nothing else. No algorithm, no timestamp, no storage. It is the
 *   format an instance's filesystem can already produce, which is exactly
 *   why it is worth accepting.
 */
interface HashRecordFormat
{

	/**
	 * Read records from an open stream.
	 *
	 * A generator, not an array: an instance's worth of hashes should not
	 * have to fit in memory to be imported.
	 *
	 * @param  resource  $stream
	 *
	 * @return Generator<HashRecord>
	 */
	public function read(
		$stream,
		FormatOptions $options,
	): Generator;


	/**
	 * Write records to an open stream.
	 *
	 * @param  iterable<HashRecord>  $records
	 * @param  resource              $stream
	 *
	 * @return int  Records written.
	 */
	public function write(
		iterable      $records,
		              $stream,
		FormatOptions $options,
	): int;


	/**
	 * Whether this format can carry the app's configuration as well as its
	 * hashes. Only the backup format can; the others are hash tables.
	 */
	public function carriesConfig(): bool;


	/**
	 * What this format cannot express, for a caller to warn about before
	 * writing one — or an empty list where nothing is lost.
	 *
	 * @return list<string>
	 */
	public function losses(): array;

}
