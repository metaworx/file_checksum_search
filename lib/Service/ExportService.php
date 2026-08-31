<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use Generator;
use InvalidArgumentException;
use OCP\DB\Exception;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\HashRecordFormat;
use OCA\FileChecksumSearch\State\Format\JsonFormat;
use OCA\FileChecksumSearch\State\HashRecord;
use OCP\App\IAppManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Everything this app owns, written out.
 *
 * Three slices, because they answer different questions and are restored by
 * different means:
 *
 * | Slice | Is | Restored by |
 * |---|---|---|
 * | `config` | the app's own appconfig keys | `fcias:import --config` |
 * | `hashes` | the checksums and their freshness stamps | `fcias:import --hashes` |
 * | `status` | what each file is waiting for, or why its hashes are not to be trusted | **nothing** — the queue is
 * derived, and an import may not hand-set it |
 *
 * The status slice is written anyway, because a reset clears it and the
 * operator who ran the reset is entitled to know what was there.
 *
 * Nothing here holds the instance in memory: hashes and status are pulled a
 * page at a time from {@see MetadataService} and handed to the format as
 * generators, and the formats stream them out.
 */
class ExportService
{

	public const SLICE_CONFIG = 'config';

	public const SLICE_STATUS = 'status';

	public const SLICE_HASHES = 'hashes';

	public const SLICES
		= [
			self::SLICE_CONFIG,
			self::SLICE_STATUS,
			self::SLICE_HASHES,
		];

	/**
	 * How many files' identities are resolved per filecache query.
	 *
	 * One query per file would make an export of a large instance a few
	 * hundred thousand round trips; the ceiling is
	 * {@see FilecacheService::locateAll()}'s own chunking at 1000.
	 */
	private const RESOLVE_BATCH = 500;


	public function __construct(
		private readonly AppConfigService $appConfigService,
		private readonly MetadataService  $metadataService,
		private readonly FilecacheService $filecacheService,
		private readonly IAppManager      $appManager,
		private readonly IConfig          $config,
		private readonly LoggerInterface  $logger,
	) {
	}


	/**
	 * Write a backup.
	 *
	 * @param  resource      $stream
	 * @param  list<string>  $slices  Any of `config`, `status`, `hashes`.
	 *
	 * @return array<string, int>  What each slice contributed.
	 * @throws Exception
	 */
	public function export(
		HashRecordFormat $format,
		array            $slices,
		                 $stream,
		FormatOptions    $options,
	): array {

		$wantsConfig = in_array( self::SLICE_CONFIG, $slices, true );
		$wantsStatus = in_array( self::SLICE_STATUS, $slices, true );
		$wantsHashes = in_array( self::SLICE_HASHES, $slices, true );

		// The refusal belongs here rather than in the command, so that the
		// PHP API cannot write a backup document that silently drops a slice
		// the caller asked for.
		if ( ( $wantsConfig || $wantsStatus ) && ! $format->carriesConfig() )
		{
			throw new InvalidArgumentException(
				'This format carries hashes only; it has nowhere to put the configuration or the queue state.',
			);
		}

		$config = $wantsConfig
			? $this->appConfigService->export()
			: null;

		if ( ! $format instanceof JsonFormat )
		{
			$written = $format->write(
				$wantsHashes
					? $this->hashRecords()
					: [],
				$stream,
				$options,
			);

			return [
				self::SLICE_HASHES => $written,
			];
		}

		// Counted as they stream past, because a generator cannot be asked
		// how long it is without consuming it.
		$statusCount = 0;
		$written     = $format->writeDocument(
			$this->header( $slices ),
			$config,
			$wantsStatus
				? $this->statusRows( $statusCount )
				: null,
			$wantsHashes
				? $this->hashRecords()
				: null,
			$stream,
			$options,
		);

		// Only what was asked for: a report saying "config 0" for a slice
		// nobody requested reads as an empty backup rather than a narrow one.
		$counts = [];

		if ( $wantsConfig )
		{
			$counts[ self::SLICE_CONFIG ] = count( $config ?? [] );
		}

		if ( $wantsStatus )
		{
			$counts[ self::SLICE_STATUS ] = $statusCount;
		}

		if ( $wantsHashes )
		{
			$counts[ self::SLICE_HASHES ] = $written;
		}

		return $counts;
	}


	/**
	 * What a restore checks itself against before it writes anything.
	 *
	 * @param  list<string>  $slices
	 *
	 * @return array<string, mixed>
	 */
	public function header( array $slices ): array
	{

		return [
			'app_version' => $this->appManager->getAppVersion( Application::APP_ID ),
			'instance_id' => $this->config->getSystemValueString( 'instanceid' ),
			'created_at'  => time(),
			'slices'      => array_values( $slices ),
		];
	}


	/**
	 * Every stored hash as a portable record.
	 *
	 * A file id is meaningless outside the instance that issued it, so each
	 * one is resolved to its canonical identity — the storage it lives on and
	 * the path inside it — a page at a time. A file whose filecache row has
	 * gone is skipped and logged: its hashes describe something that is no
	 * longer there.
	 *
	 * @return Generator<HashRecord>
	 * @throws Exception
	 */
	public function hashRecords(): Generator
	{

		$page = [];

		foreach ( $this->metadataService->exportHashes() as $entry )
		{
			$page[] = $entry;

			if ( count( $page ) < self::RESOLVE_BATCH )
			{
				continue;
			}

			yield from $this->resolve( $page );
			$page = [];
		}

		if ( $page !== [] )
		{
			yield from $this->resolve( $page );
		}
	}


	/**
	 * What each file is waiting for, or why its hashes are not to be trusted.
	 *
	 * @param  int  $count  Filled in as rows go past, for the caller's report.
	 *
	 * @return Generator<array<string, string>>
	 * @throws Exception
	 */
	public function statusRows( int &$count = 0 ): Generator
	{

		$page = [];

		foreach ( $this->metadataService->exportStates() as $entry )
		{
			$page[ $entry['file_id'] ] = $entry['state'];

			if ( count( $page ) < self::RESOLVE_BATCH )
			{
				continue;
			}

			yield from $this->resolveStates( $page, $count );
			$page = [];
		}

		if ( $page !== [] )
		{
			yield from $this->resolveStates( $page, $count );
		}
	}


	/**
	 * Turn a page of hash entries into records, resolving their identities in
	 * one query rather than one per file.
	 *
	 * @param  list<array{file_id: int, hashes: array<string, string>, updated_at: ?int}>  $page
	 *
	 * @return Generator<HashRecord>
	 * @throws Exception
	 */
	private function resolve( array $page ): Generator
	{

		$locations = $this->filecacheService->locateAll(
			array_map( static fn(
				array $entry,
			): int => $entry['file_id'], $page ),
		);

		foreach ( $page as $entry )
		{
			$location = $locations[ $entry['file_id'] ] ?? null;

			if ( $location === null )
			{
				$this->logger->debug(
					'FCIAS export: fileId {fileId} has hashes but no filecache row; skipped.',
					[
						'app'    => Application::APP_ID,
						'fileId' => $entry['file_id'],
					],
				);

				continue;
			}

			// A stored zero is this app's "never stamped" — `backfillHashes()`
			// stamps only when unset, and freshness is `updated_at >= mtime`,
			// which zero can never satisfy. Exporting it as a number would
			// hand a restore a claim about 1970 instead of an absence.
			$stamp = $entry['updated_at'] > 0
				? $entry['updated_at']
				: null;

			foreach ( $entry['hashes'] as $algo => $hash )
			{
				yield new HashRecord(
					$location->storageId,
					$location->internalPath,
					$algo,
					$hash,
					$stamp,
				);
			}
		}
	}


	/**
	 * @param  array<int, string>  $page  file id => state
	 *
	 * @return Generator<array<string, string>>
	 * @throws Exception
	 */
	private function resolveStates(
		array $page,
		int   &$count,
	): Generator {

		$locations = $this->filecacheService->locateAll( array_keys( $page ) );

		foreach ( $page as $fileId => $state )
		{
			$location = $locations[ $fileId ] ?? null;

			if ( $location === null )
			{
				continue;
			}

			$count ++;

			yield [
				'storage' => $location->storageId,
				'path'    => $location->internalPath,
				'state'   => $state,
			];
		}
	}

}
