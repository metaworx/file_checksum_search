<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\State\Format\FormatOptions;
use OCA\FileChecksumSearch\State\Format\HashRecordFormat;
use OCA\FileChecksumSearch\State\Format\JsonFormat;
use OCA\FileChecksumSearch\State\HashRecord;
use OCA\FileChecksumSearch\State\ImportPolicy;
use OCA\FileChecksumSearch\State\ImportReport;
use OCP\DB\Exception;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Hashes and configuration, coming the other way.
 *
 * The dangerous part of an import is not the hash — it is the **timestamp**.
 * Freshness is `updated_at >= mtime` everywhere in this app, so a hash
 * stamped later than the content it describes is invisible to every
 * correction path there is: the sweep will call the file fresh for ever and
 * no rule will recompute it. Every decision below exists to keep that from
 * happening by accident; {@see ImportPolicy} is where the choice is written
 * down.
 *
 * Records arrive one per algorithm and are applied one per **file**: a
 * backup names the same file once for each algorithm it holds, and writing
 * them separately would rewrite the same metadata document three times and
 * ask the same freshness question three times over.
 *
 * The mtime cannot see a file changed with its mtime kept, as a sync client
 * uploads it; the ETag can, on the instance that issued it. So a record's
 * ETag decides first, under `--stamp=source` ({@see etagFor()}).
 */
class ImportService
{

//  constants

	/**
	 * How many files are resolved and written per pass.
	 *
	 * The stream may be an instance's worth of records, so it is consumed in
	 * pages: one filecache query per page rather than one per record, and no
	 * more than this many records held at a time.
	 */
	private const PAGE_SIZE = 500;


//  constructor

	public function __construct(
		private readonly AppConfigService $appConfigService,
		private readonly MetadataService  $metadataService,
		private readonly FilecacheService $filecacheService,
		private readonly IConfig          $config,
		private readonly LoggerInterface  $logger,
	) {
	}


//  other non-static methods

	/**
	 * Read a backup document and apply what it holds.
	 *
	 * @param  resource  $stream
	 *
	 * @throws Exception
	 */
	public function import(
		HashRecordFormat $format,
		                 $stream,
		FormatOptions    $options,
		ImportPolicy     $policy,
		bool             $wantsConfig,
		bool             $wantsHashes,
	): ImportReport
	{
		$report = new ImportReport();

		if ( $wantsConfig && ! $format instanceof JsonFormat )
		{
			throw new RuntimeException(
				'Only a json backup carries configuration; this format holds hashes alone.',
			);
		}

		// One read of the stream serves both slices, because a stream is not
		// something that can be rewound in general — it may be a pipe.
		if ( $format instanceof JsonFormat )
		{
			$document = $format->readDocument( $stream, $options );

			$this->checkHeader( $document['header'], $policy );

			if ( $wantsConfig )
			{
				$this->importConfig( $document['config'] ?? [], $policy, $report );
			}

			if ( $wantsHashes )
			{
				$this->importHashes(
					$document['records'],
					$policy,
					$report,
					$this->isFromHere( $document['header'] ),
				);
			}

			return $report;
		}

		if ( $wantsHashes )
		{
			$this->importHashes( $format->read( $stream, $options ), $policy, $report, false );
		}

		return $report;
	}


//  getters / setters / is* / has*

	/**
	 * Whether a backup was written by this instance, and its ETags are this
	 * filecache's. Only a json backup says where it came from.
	 *
	 * @param  array<string, mixed>  $header
	 */
	private function isFromHere( array $header ): bool
	{
		$instanceId = $this->config->getSystemValueString( 'instanceid' );

		return $instanceId !== '' && ( $header['instance_id'] ?? null ) === $instanceId;
	}

	/**
	 * Refuse a backup document this version cannot honour.
	 *
	 * The schema is the one thing a restore must not guess about: a document
	 * from a future version may mean something different by the same field,
	 * and a half-applied import is worse than a refused one.
	 */
	private function checkHeader(
		array        $header,
		ImportPolicy $policy,
	): void
	{
		$schema = isset( $header['schema'] )
			? (int) $header['schema']
			: JsonFormat::SCHEMA_VERSION;

		if ( $schema > JsonFormat::SCHEMA_VERSION )
		{
			throw new RuntimeException(
				sprintf(
					'This backup is schema %d; this version understands %d. Upgrade the app first.',
					$schema,
					JsonFormat::SCHEMA_VERSION,
				),
			);
		}

		if ( $policy->dryRun )
		{
			return;
		}

		$this->logger->info(
			'FCIAS import: applying a backup from {app_version} of instance {instance_id}.',
			[
				'app'         => Application::APP_ID,
				'app_version' => $header['app_version'] ?? 'an unknown version',
				'instance_id' => $header['instance_id'] ?? 'an unknown instance',
			],
		);
	}

	/**
	 * @param  array<string, string>  $config
	 */
	private function importConfig(
		array        $config,
		ImportPolicy $policy,
		ImportReport $report,
	): void
	{
		if ( $config === [] )
		{
			return;
		}

		if ( $policy->dryRun )
		{
			$report->configWritten = count( $config );

			return;
		}

		$result                    = $this->appConfigService->import( $config, ! $policy->merge );
		$report->configWritten     = $result['written'];
		$report->configRefused     = $result['skipped'];
		$report->configNotPortable = $result['not_portable'] ?? [];
	}

	/**
	 * @param  iterable<HashRecord>  $records
	 * @param  bool                  $fromHere  The records' ETags are this
	 *                                          instance's ({@see isFromHere()}).
	 *
	 * @throws Exception
	 */
	private function importHashes(
		iterable     $records,
		ImportPolicy $policy,
		ImportReport $report,
		bool         $fromHere,
	): void
	{
		$page = [];

		foreach ( $records as $record )
		{
			if ( ! $record->isComplete() )
			{
				$report->malformed ++;

				continue;
			}

			$key = FilecacheService::identityKey( $record->storageId, $record->path );

			$page[ $key ][ $record->algo ] = $record;

			if ( count( $page ) < self::PAGE_SIZE )
			{
				continue;
			}

			$this->applyPage( $page, $policy, $report, $fromHere );
			$page = [];
		}

		if ( $page !== [] )
		{
			$this->applyPage( $page, $policy, $report, $fromHere );
		}
	}

	/**
	 * Resolve one page of identities, then write each file once.
	 *
	 * @param  array<string, array<string, HashRecord>>  $page
	 *
	 * @throws Exception
	 */
	private function applyPage(
		array        $page,
		ImportPolicy $policy,
		ImportReport $report,
		bool         $fromHere,
	): void
	{
		$pathsByStorage = [];

		foreach ( $page as $byAlgo )
		{
			$first                                 = reset( $byAlgo );
			$pathsByStorage[ $first->storageId ][] = $first->path;
		}

		$located = $this->filecacheService->locateAllByPath( $pathsByStorage );
		$states  = $this->statesToWeigh( $page, $located, $policy );

		foreach ( $page as $key => $byAlgo )
		{
			$location = $located[ $key ] ?? null;

			if ( $location === null )
			{
				// A path this instance does not have. Never created: an
				// import restores what a file is, not that it exists.
				$report->unknownPath += count( $byAlgo );

				if ( $policy->strict )
				{
					throw new RuntimeException(
						sprintf( 'No such file on this instance: %s', reset( $byAlgo )->describe() ),
					);
				}

				continue;
			}

			$this->applyFile(
				$location,
				$byAlgo,
				$policy,
				$report,
				$this->etagFor(
					reset( $byAlgo ),
					$states[ $location->fileId ]['etag'] ?? null,
					$policy,
					$fromHere,
				),
			);
		}
	}

	/**
	 * The filecache's ETags of the page's files whose records carry one, the
	 * only ones {@see etagFor()} weighs: a backup without them costs no
	 * query.
	 *
	 * @param  array<string, array<string, HashRecord>>  $page
	 * @param  array<string, FileLocation>               $located
	 *
	 * @return array<int, array{etag: string, size: int, mtime: int, storageMtime: int}>
	 * @throws Exception
	 */
	private function statesToWeigh(
		array        $page,
		array        $located,
		ImportPolicy $policy,
	): array
	{
		if ( $policy->stamp !== ImportPolicy::STAMP_SOURCE )
		{
			return [];
		}

		$fileIds = [];

		foreach ( $page as $key => $byAlgo )
		{
			if ( isset( $located[ $key ] ) && reset( $byAlgo )->etag !== null )
			{
				$fileIds[] = $located[ $key ]->fileId;
			}
		}

		return $fileIds === []
			? []
			: $this->filecacheService->statesOf( $fileIds );
	}

	/**
	 * What a record's ETag says, under `--stamp=source`, where the source's
	 * word is weighed.
	 *
	 * | The record's ETag | Is | The file |
	 * |---|---|---|
	 * | the file's | anywhere | stamped with it: vouched for by it |
	 * | another | a backup of this instance | changed since: refused, unless `--allow-stale` |
	 * | another | from elsewhere, or a csv | as without one: ETags are per instance |
	 * | none | | held by the mtime alone, as before ETags were recorded |
	 *
	 * `--stamp=mtime` and `now` are the operator's word, and stamp none.
	 *
	 * @return string|null|false  The ETag to stamp; null for none; false for
	 *                            a file changed since the record was read.
	 */
	private function etagFor(
		HashRecord   $record,
		?string      $present,
		ImportPolicy $policy,
		bool         $fromHere,
	): string|null|false
	{
		if ( $policy->stamp !== ImportPolicy::STAMP_SOURCE || $record->etag === null || $present === null )
		{
			return null;
		}

		if ( $record->etag === $present )
		{
			return $present;
		}

		return $fromHere
			? false
			: null;
	}

	/**
	 * @param  array<string, HashRecord>  $byAlgo
	 * @param  string|null|false          $etag    From {@see etagFor()}.
	 */
	private function applyFile(
		FileLocation      $location,
		array             $byAlgo,
		ImportPolicy      $policy,
		ImportReport      $report,
		string|null|false $etag,
	): void
	{
		// A backup of this instance whose file has changed since: its hashes
		// describe content the file no longer has, whatever the mtime says.
		// `--allow-stale` stores them under the ETag they were read against,
		// which keeps them out of every search until they are read again.
		if ( $etag === false )
		{
			if ( ! $policy->allowOutdated )
			{
				$report->skippedChanged += count( $byAlgo );

				return;
			}

			$etag = reset( $byAlgo )->etag;
		}

		// The whole reason --stamp exists. A hash stamped at or after the
		// file's mtime claims to describe the content as it stands; one
		// stamped before it describes something the file no longer is, and
		// storing it as though it were current would make the file
		// permanently un-correctable — freshness is `updated_at >= mtime`,
		// so nothing would ever look at it again. One record's stamp decides
		// for the whole file: they all came from the same backup of it.
		$stamp = $this->stampFor( reset( $byAlgo ), $location, $policy );

		if ( $stamp === null )
		{
			$report->skippedOutdated += count( $byAlgo );

			return;
		}

		$acceptable = array_map(
			static fn(
				HashRecord $record,
			): string => $record->hash,
			$byAlgo,
		);

		if ( $policy->dryRun )
		{
			$report->written += count( $acceptable );

			return;
		}

		try
		{
			$result = $this->metadataService->writeHashes(
				$location->fileId,
				$acceptable,
				$stamp,
				$policy->merge,
				$etag,
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS import: could not write hashes for {path}; continuing.',
				[
					'app'       => Application::APP_ID,
					'path'      => $location->describe(),
					'exception' => $e,
				],
			);

			return;
		}

		$report->written += $result['written'];
		$report->overwritten += $result['overwritten'];
		$report->skippedExisting += $result['skipped'];

		if ( $result['markerCleared'] )
		{
			$report->markerCleared ++;
		}
	}

	/**
	 * What timestamp to store, or null for "do not store this at all".
	 *
	 * | `--stamp=` | Stores | When it is right |
	 * |---|---|---|
	 * | `source` | the record's own stamp, refusing one older than the file | restoring a backup |
	 * | `mtime`  | the file's mtime — the claim `backfillHashes()` already makes | a sumfile run just now |
	 * | `now`    | this moment. Asserts more than the data supports | rarely |
	 *
	 * `--allow-stale` takes what `source` would refuse, at the caller's
	 * insistence and with a warning per run.
	 */
	private function stampFor(
		HashRecord   $record,
		FileLocation $location,
		ImportPolicy $policy,
	): ?int
	{
		return match ( $policy->stamp )
		{
			ImportPolicy::STAMP_MTIME => $location->mtime,
			ImportPolicy::STAMP_NOW   => time(),
			default                   => $this->sourceStamp( $record, $location, $policy ),
		};
	}

	private function sourceStamp(
		HashRecord   $record,
		FileLocation $location,
		ImportPolicy $policy,
	): ?int
	{
		// A source that did not say when it hashed has told us nothing to
		// weigh, so the file's own mtime is the most this can honestly claim.
		$stamp = $record->updatedAt ?? $location->mtime;

		if ( $stamp >= $location->mtime || $policy->allowOutdated )
		{
			return $stamp;
		}

		return null;
	}
}
