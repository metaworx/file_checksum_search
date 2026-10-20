<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\HintException;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Hash computation and recalculation logic.
 *
 * Handles file-level hash operations: computing new hashes,
 * recalculating existing ones, and the two-phase collection+generate
 * pattern for batch hash generation.
 */
class HashCalculationService
{

//  constants

	public const CHUNK_SIZE = 8192;

	/**
	 * How long Nextcloud holds a storage it found unavailable before it
	 * tries it again: `OC\Files\Storage\Wrapper\Availability::RECHECK_TTL_SEC`,
	 * which is not public API. {@see isStorageDown()}
	 */
	private const STORAGE_RECHECK_SECONDS = 600;

	/** {@see readTypeAlone()}: the type read and put into the document. */
	private const TYPE_DETECTED = 'detected';

	/** {@see readTypeAlone()}: the content read, and libmagic naming none. */
	private const TYPE_NONE = 'none';

	/** {@see readTypeAlone()}: another of this app's workers holds the file. */
	private const TYPE_BUSY = 'busy';

	/** {@see readTypeAlone()}: the file was written to during the read. */
	private const TYPE_CHANGED = 'changed';


//  constructor

	public function __construct(
		private readonly FilecacheService $filecacheService,
		private readonly ILockingProvider $lockingProvider,
		private readonly MetadataService  $metadataService,
		private readonly RuleService      $ruleService,
		private readonly LoggerInterface  $logger,
		private readonly AlgorithmCatalogue $catalogue,
		private readonly IL10N $l10n,
		private readonly MimeTypeService $mimeTypes,
	) {
	}

	/** The --algo token that delegates to the governing rule's algorithm list. */
	public const ALGO_AUTO = 'auto';


//  getters / setters / is* / has*

	/**
	 * The algorithm to use when a caller names none and no rule decides.
	 * {@see ALGO_AUTO} is the other answer to "which algorithm": defer to
	 * whatever the governing rule lists.
	 */
	public function getDefaultAlgo(): string
	{
		return $this->catalogue->default();
	}

	/**
	 * Whether $algo names an algorithm the catalogue has in force.
	 *
	 * Accepts mixed so it can validate a raw, untrusted request value
	 * directly (e.g. one entry of a request body's `algos` array).
	 */
	public function isValidAlgo( mixed $algo ): bool
	{
		return $this->catalogue->isValid( $algo );
	}

	/**
	 * Whether the file's hashes describe it as it is, by the test a lookup
	 * applies ({@see MetadataService::isCurrent()}): hashes whose ETag the
	 * file has moved on from are neither kept unread nor stamped anew.
	 *
	 * One stamp covers the whole document, not one per algorithm: the file
	 * changed or it did not, and every hash of it is stale together. A file
	 * with no stamp at all has never been hashed, and counts as stale.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function isHashUpToDate(
		IFilesMetadata $metadata,
		int            $fileId,
	): bool
	{
		return $this->metadataService->isCurrent( $metadata, $this->filecacheService->stateOf( $fileId ) );
	}

	/**
	 * Whether a metadata document holds hashes its stamp no longer covers.
	 *
	 * A document holding none is not outdated, whatever its stamp says:
	 * there is nothing in it to drop. A file the filecache no longer has is
	 * not judged — the caller's own resolution reports it.
	 */
	private function isOutdated(
		IFilesMetadata $metadata,
		int|File       $file,
	): bool
	{
		if ( $this->metadataService->getHashes( $metadata ) === [] )
		{
			return false;
		}

		try
		{
			$state = $this->filecacheService->stateOf(
				$file instanceof File
					? $file->getId()
					: $file,
			);
		}
		catch ( Throwable )
		{
			return false;
		}

		return $state !== null && ! $this->metadataService->isCurrent( $metadata, $state );
	}

	/**
	 * Whether Nextcloud holds the file's storage unavailable, and will not try
	 * it again yet.
	 *
	 * Its availability wrapper, on every external storage that is not
	 * local, fails each call at once after the storage's first failure, and
	 * tries the storage again once {@see STORAGE_RECHECK_SECONDS} have passed
	 * since its record. Past that this answers no, so that a read goes
	 * through and Nextcloud's own check with it: else the flag would stand
	 * for as long as nothing else touched the storage. Unknown is not down.
	 */
	private function isStorageDown( File $file ): bool
	{
		try
		{
			$availability = $file->getStorage()
			                     ->getAvailability()
			;
		}
		catch ( Throwable )
		{
			return false;
		}

		return ( $availability['available'] ?? true ) === false
			&& time() - (int) ( $availability['last_checked'] ?? 0 ) <= self::STORAGE_RECHECK_SECONDS;
	}


//  other non-static methods

	/**
	 * A file's mtime, or 0 for one that cannot be resolved — whose
	 * processing then fails where it is resolved again.
	 */
	private function mtimeOf( int|File $file ): int
	{
		try
		{
			return $this->filecacheService->getFile( $file )
			                              ->getMTime()
			;
		}
		catch ( Throwable )
		{
			return 0;
		}
	}

	/**
	 * Take an exclusive lock on a file, or report that somebody else has it.
	 *
	 * False is not a failure: it means the file is busy — being written,
	 * being hashed by another worker — and the right response is to skip it
	 * and let the next sweep find it. Every true must be paired with a
	 * {@see releaseLock()}.
	 */
	private function acquireLock( int $fileId ): bool
	{
		try
		{
			$this->lockingProvider->acquireLock(
				'files/' . $fileId,
				ILockingProvider::LOCK_EXCLUSIVE,
			);

			return true;
		}
		catch ( LockedException )
		{
			return false;
		}
	}

	/**
	 * Walk one user's folders, collecting the files that need hashing.
	 *
	 * Recursive, and the recursion is why the signature is what it is:
	 * $collected and $stats are accumulators shared down the whole walk, not
	 * per-call results. It returns nothing; what it found is in $collected.
	 *
	 * Stopping early is normal. Once $collected reaches $batchSize the walk
	 * returns wherever it is, so a partial result is the expected outcome of
	 * a bounded run rather than a sign that something went wrong. A
	 * $batchSize of zero or less means no limit at all, which is what the
	 * generate command passes when --batch-size is omitted — on a large
	 * instance that walk holds every matching file in memory.
	 *
	 * With $skipQueued, a file already queued is left too, and does not
	 * count toward the batch: what `--mark` asks, which would otherwise
	 * queue the same first files on every run.
	 */
	private function collectFilesForUser(
		string           $folderPath,
		string           $userId,
		array            $explicitAlgos,
		bool             $useRuleAlgos,
		string           $mode,
		?string          $pathPattern,
		string           $userFolderPath,
		array            &$collected,
		int              $batchSize,
		RuleOverrides    $overrides,
		?OutputInterface $output = null,
		?array           &$stats = null,
		bool             $skipQueued = false,
	): void
	{
		// A non-positive batch size means "no limit" (the generate
		// command passes 0 when --batch-size is omitted).
		$unlimited = $batchSize <= 0;

		if ( ! $unlimited && count( $collected ) >= $batchSize )
		{
			return;
		}

		$userFolder = $this->filecacheService->getUserFolder( $userId );
		$relPath    = $this->relativeHashPath( $folderPath, $userFolderPath );

		try
		{
			$node = $userFolder->get( $relPath );
		}
		catch ( NotFoundException )
		{
			return;
		}

		if ( ! $node instanceof Folder )
		{
			return;
		}

		$listing = $node->getDirectoryListing();

		// Prefetch this listing's verdicts in one scan: same glob pre-filter
		// as the loop below (which also does the stats counting), so only
		// files that will actually be judged cost a lookup.
		$candidateIds = [];

		foreach ( $listing as $child )
		{
			if ( ! ( $child instanceof File ) )
			{
				continue;
			}

			$candidateRelative = $this->relativeHashPath(
				$child->getPath(),
				$userFolderPath,
			);

			if ( $pathPattern === null || PathUtil::matchesGlob( $pathPattern, $candidateRelative ) )
			{
				$candidateIds[] = $child->getId();
			}
		}

		$rulesByFileId = $this->ruleService->governingRulesForFileIds(
			$candidateIds,
			$overrides->ignoreRuleIds,
		);
		$queued        = $skipQueued && $candidateIds !== []
			? $this->metadataService->queuedAmong( $candidateIds )
			: [];

		foreach ( $listing as $child )
		{
			if ( ! $unlimited && count( $collected ) >= $batchSize )
			{
				return;
			}

			if ( $child instanceof Folder )
			{
				$this->collectFilesForUser(
					$child->getPath(),
					$userId,
					$explicitAlgos,
					$useRuleAlgos,
					$mode,
					$pathPattern,
					$userFolderPath,
					$collected,
					$batchSize,
					$overrides,
					$output,
					$stats,
					$skipQueued,
				);

				continue;
			}

			if ( ! ( $child instanceof File ) )
			{
				continue;
			}

			if ( $stats !== null )
			{
				$stats['files'] = ( $stats['files'] ?? 0 ) + 1;
			}

			$relativePath = $this->relativeHashPath(
				$child->getPath(),
				$userFolderPath,
			);

			if ( $pathPattern !== null && ! PathUtil::matchesGlob( $pathPattern, $relativePath ) )
			{
				if ( $stats !== null )
				{
					$stats['globSkipped'] = ( $stats['globSkipped'] ?? 0 ) + 1;
				}

				continue;
			}

			// By identity: the collector walks a home view, but that view
			// contains mounts — shares, group folders — whose files are not
			// this user's and answer to their own rules. Resolved above for
			// the whole listing in one scan.
			$rule = $rulesByFileId[ $child->getId() ] ?? null;

			if ( ! $overrides->allows( $rule ) )
			{
				if ( $stats !== null )
				{
					$stats['ruleSkipped'] = ( $stats['ruleSkipped'] ?? 0 ) + 1;
				}

				$overrides->report( $output, $child->getPath(), $rule, false );

				continue;
			}

			// Effective set: explicit names are exclusive; the 'auto' token
			// adds the governing rule's list; both together form the union.
			$fileAlgos = $explicitAlgos;

			if ( $useRuleAlgos )
			{
				$fileAlgos = array_values(
					array_unique(
						array_merge( $fileAlgos, $this->catalogue->computable( $rule['algos'] ?? [] ) ),
					),
				);
			}

			if ( $fileAlgos === [] )
			{
				// Only reachable for an unmatched file under a pure-auto run
				// the caller allowed through — nothing says which algorithms,
				// so nothing is computed.
				if ( $stats !== null )
				{
					$stats['noAlgos'] = ( $stats['noAlgos'] ?? 0 ) + 1;
				}

				$output?->writeln(
					sprintf( '    skip %s [no rule to supply algorithms]', $child->getPath() ),
					OutputInterface::VERBOSITY_VERBOSE,
				);

				continue;
			}

			if ( isset( $queued[ $child->getId() ] ) )
			{
				if ( $stats !== null )
				{
					$stats['queued'] = ( $stats['queued'] ?? 0 ) + 1;
				}

				continue;
			}

			if ( $mode !== MetadataService::PENDING_MODE_FORCE
				&& $this->hasAllAndFresh( $child, $fileAlgos ) )
			{
				if ( $stats !== null )
				{
					$stats['alreadyHashed'] = ( $stats['alreadyHashed'] ?? 0 ) + 1;
				}

				continue;
			}

			$collected[] = [
				'file'  => $child,
				'algos' => $fileAlgos,
			];

			// Reported only once the file is actually queued, so a "hash"
			// line always means work is about to happen.
			$overrides->report( $output, $child->getPath(), $rule, true );
		}
	}

	/**
	 * Whether $file already carries every algorithm of $algos, fresh.
	 *
	 * Presence is read from the filecache checksum string (no extra query);
	 * freshness compares the metadata updated_at against the file's mtime —
	 * a file modified after its hashes were computed is outdated, and 'missing'
	 * mode refreshes it rather than skipping it as done. The timestamp is a
	 * single indexed point lookup, and only files that pass the presence
	 * check pay for it.
	 */
	private function hasAllAndFresh(
		File  $file,
		array $algos,
	): bool
	{
		$existing = FilecacheService::parseChecksumString( $file->getChecksum() ?? '' );

		foreach ( $algos as $algo )
		{
			if ( ! isset( $existing[ $algo ] ) )
			{
				return false;
			}
		}

		$updatedAt = $this->metadataService->getUpdatedAt( $file->getId() );

		return $updatedAt !== null && $updatedAt >= $file->getMTime();
	}

	/**
	 * Two-phase hash generation: collect files needing hashes,
	 * then process them. Avoids interleaving reads and writes
	 * to oc_filecache (dirty reads in NC v33 debug mode).
	 *
	 * $algo may contain the token 'auto', which resolves per file to the
	 * governing rule's algorithm list: 'auto' alone delegates entirely to the
	 * rules, explicit names are exclusive, and both together form the union.
	 * A file whose effective set comes out empty (no rule to delegate to) is
	 * skipped and, at -v, says so.
	 *
	 * $mode is the existing rule-mode vocabulary applied to a direct run:
	 * 'missing' (default) computes each file's absent algorithms and — when
	 * the file's updated_at predates its mtime — refreshes the outdated ones;
	 * 'force' recomputes the full effective set regardless.
	 *
	 * @param  int  $batchSize  Maximum files to collect (a value <= 0 means unlimited)
	 *
	 * @return array{processed: int, skipped: int}
	 */
	public function generateMissingHashes(
		string           $userId,
		string|array     $algo,
		?string          $pathPattern = null,
		int              $batchSize = 100,
		?OutputInterface $output = null,
		?RuleOverrides   $overrides = null,
		string           $mode = MetadataService::PENDING_MODE_MISSING,
	): array
	{
		$overrides ??= new RuleOverrides();

		$tokens        = array_values( array_unique( array_map( 'strtolower', (array) $algo ) ) );
		$useRuleAlgos  = in_array( self::ALGO_AUTO, $tokens, true );
		$explicitAlgos = array_values( array_diff( $tokens, [ self::ALGO_AUTO ] ) );
		$algoLabel     = implode( ',', $tokens );

		$userFolderPath = $this->filecacheService->getUserFolderPath( $userId );

		// Phase 1: collect
		$files = [];
		$stats = [];
		$this->collectFilesForUser(
			$userFolderPath,
			$userId,
			$explicitAlgos,
			$useRuleAlgos,
			$mode,
			$pathPattern,
			$userFolderPath,
			$files,
			$batchSize,
			$overrides,
			$output,
			$stats,
		);

		$collected = count( $files );

		if ( $collected > 0 )
		{
			$this->logger->debug(
				'FCIAS: generateMissingHashes collected {count} files.',
				[
					'app'    => Application::APP_ID,
					'userId' => $userId,
					'algo'   => $algoLabel,
					'count'  => $collected,
				],
			);

			$output?->writeln(
				sprintf(
					'  Collected %d files without %s checksums.',
					$collected,
					$algoLabel,
				),
			);
		}
		elseif ( $output !== null && $output->isVerbose() )
		{
			$output->writeln(
				sprintf(
					'  No files collected (user: %s, path: %s, algo: %s).',
					$userId,
					$pathPattern ?? '**',
					$algoLabel,
				),
			);
			$output->writeln(
				sprintf(
					'    Scan stats: %d file(s) seen, %d already hashed, %d glob-mismatched.',
					$stats['files'] ?? 0,
					$stats['alreadyHashed'] ?? 0,
					$stats['globSkipped'] ?? 0,
				),
			);
		}

		// Phase 2: process
		return $this->hashCollected( $files, $mode, $algoLabel, $output );
	}

	/**
	 * Queue an account's files that need hashing as pending:<mode>, for the
	 * background job, which honours each file's rule at drain time
	 * ({@see processFile()}).
	 *
	 * The files are chosen as {@see generateMissingHashes()} chooses them,
	 * with each file's rule's algorithms: none a rule leaves, and under
	 * `missing` none holding them all, fresh. A file already queued is left
	 * as well, so that the batch is spent on files the queue does not hold
	 * yet and the next run starts where this one stopped.
	 *
	 * @param  int  $batchSize  Maximum files to queue (a value <= 0 means unlimited)
	 *
	 * @return array{marked: int, skipped: int}  skipped: the files a rule left
	 * @throws \OCP\DB\Exception
	 */
	public function markMissingHashes(
		string           $userId,
		?string          $pathPattern,
		int              $batchSize,
		RuleOverrides    $overrides,
		string           $mode,
		?OutputInterface $output = null,
	): array
	{
		$userFolderPath = $this->filecacheService->getUserFolderPath( $userId );
		$files          = [];
		$stats          = [];

		$this->collectFilesForUser(
			$userFolderPath,
			$userId,
			[],
			true,
			$mode,
			$pathPattern,
			$userFolderPath,
			$files,
			$batchSize,
			$overrides,
			$output,
			$stats,
			true,
		);

		foreach ( $files as $entry )
		{
			$this->metadataService->markPending( $entry['file']->getId(), MetadataService::PENDING_PREFIX . $mode );
		}

		return [
			'marked'  => count( $files ),
			'skipped' => $stats['ruleSkipped'] ?? 0,
		];
	}

	/**
	 * The versions in an account's home, hashed as its files are: those
	 * whose file's rule says `versions` ({@see RuleService::hashesVersions()}),
	 * or, with $all, every one the run may take ({@see RuleOverrides::allows()}).
	 * $algo, $mode and $batchSize are {@see generateMissingHashes()}'s, `auto`
	 * taking the file's rule's algorithms; a version is hashed by the
	 * recalculation the sidebar uses ({@see recalcHashes()}).
	 *
	 * The caller asks first whether any rule hashes versions
	 * ({@see RuleService::anyRuleHashesVersions()}): where none does and $all
	 * is not given, this reads every version's row for nothing.
	 *
	 * Collected and hashed a page at a time, so that a home with many
	 * versions is not held whole.
	 *
	 * @param  int  $batchSize  Maximum versions to collect (a value <= 0 means unlimited)
	 *
	 * @return array{processed: int, skipped: int}
	 * @throws \OCP\DB\Exception
	 */
	public function generateVersionHashes(
		string           $userId,
		string|array     $algo,
		?string          $pathPattern = null,
		int              $batchSize = 100,
		?OutputInterface $output = null,
		?RuleOverrides   $overrides = null,
		string           $mode = MetadataService::PENDING_MODE_MISSING,
		bool             $all = false,
	): array
	{
		$overrides ??= new RuleOverrides();

		$tokens        = array_values( array_unique( array_map( 'strtolower', (array) $algo ) ) );
		$useRuleAlgos  = in_array( self::ALGO_AUTO, $tokens, true );
		$explicitAlgos = array_values( array_diff( $tokens, [ self::ALGO_AUTO ] ) );
		$algoLabel     = implode( ',', $tokens );
		$unlimited     = $batchSize <= 0;
		$collected     = 0;
		$processed     = 0;
		$skipped       = 0;

		foreach ( $this->versionsInHome( $userId, $pathPattern, $overrides, $all, $output ) as $page )
		{
			$wanted = $this->wantedOf( $page, $explicitAlgos, $useRuleAlgos );
			$fresh  = $mode === MetadataService::PENDING_MODE_FORCE
				? []
				: $this->freshAmong( $wanted );
			$batch = [];

			foreach ( $wanted as $versionId => $algos )
			{
				if ( ! $unlimited && $collected >= $batchSize )
				{
					break;
				}

				if ( isset( $fresh[ $versionId ] ) )
				{
					continue;
				}

				$batch[] = [
					'file'  => $versionId,
					'algos' => $algos,
				];
				$collected ++;

				$overrides->report( $output, $page[ $versionId ]['path'], $page[ $versionId ]['rule'], true );
			}

			$result    = $this->hashCollected( $batch, $mode, $algoLabel, $output );
			$processed += $result['processed'];
			$skipped += $result['skipped'];

			if ( ! $unlimited && $collected >= $batchSize )
			{
				break;
			}
		}

		return [
			'processed' => $processed,
			'skipped'   => $skipped,
		];
	}

	/**
	 * Queue the versions in an account's home whose file's rule says
	 * `versions`, as pending:<mode>, for the background job, which honours
	 * the rule at drain time ({@see processFile()}). Asked first as
	 * {@see generateVersionHashes()} is.
	 *
	 * Only those that need hashing, as {@see markMissingHashes()} queues
	 * files: none already queued, and under `missing` none holding every
	 * algorithm of its rule, current.
	 *
	 * @param  int  $batchSize  Maximum versions to queue (a value <= 0 means unlimited)
	 *
	 * @return int  The versions queued.
	 * @throws \OCP\DB\Exception
	 */
	public function markVersions(
		string           $userId,
		?string          $pathPattern,
		int              $batchSize,
		RuleOverrides    $overrides,
		string           $mode,
		?OutputInterface $output = null,
	): int
	{
		$marked = 0;

		foreach ( $this->versionsInHome( $userId, $pathPattern, $overrides, false, $output ) as $page )
		{
			$wanted = $this->wantedOf( $page, [], true );
			$fresh  = $mode === MetadataService::PENDING_MODE_FORCE
				? []
				: $this->freshAmong( $wanted );
			$queued = $wanted === []
				? []
				: $this->metadataService->queuedAmong( array_keys( $wanted ) );

			foreach ( array_keys( $wanted ) as $versionId )
			{
				if ( $batchSize > 0 && $marked >= $batchSize )
				{
					return $marked;
				}

				if ( isset( $fresh[ $versionId ] ) || isset( $queued[ $versionId ] ) )
				{
					continue;
				}

				$overrides->report( $output, $page[ $versionId ]['path'], $page[ $versionId ]['rule'], true );

				$this->metadataService->markPending( $versionId, MetadataService::PENDING_PREFIX . $mode );
				$marked ++;
			}
		}

		return $marked;
	}

	/**
	 * Hash what a run collected ({@see generateMissingHashes()},
	 * {@see generateVersionHashes()}), each file with its algorithms. A file
	 * that was busy, or that needed nothing new, is skipped; one that fails
	 * is logged, and the run goes on.
	 *
	 * @param  list<array{file: int|File, algos: list<string>}>  $collected
	 *
	 * @return array{processed: int, skipped: int}
	 */
	private function hashCollected(
		array            $collected,
		string           $mode,
		string           $algoLabel,
		?OutputInterface $output,
	): array
	{
		$processed = 0;
		$skipped   = 0;

		foreach ( $collected as $entry )
		{
			$file      = $entry['file'];
			$fileId    = $file instanceof File
				? $file->getId()
				: $file;
			$fileAlgos = $entry['algos'];

			try
			{
				// Under 'missing', recalcHashes itself skips per-algorithm
				// hashes that are present and fresh; 'force' recomputes them.
				$batch = $this->recalcHashes(
					$file,
					$fileAlgos,
					$mode !== MetadataService::PENDING_MODE_FORCE,
				);

				if ( $batch['locked'] )
				{
					$skipped ++;

					if ( $output !== null && $output->isVeryVerbose() )
					{
						$output->writeln(
							sprintf( '    Skipped fileId %d (locked).', $fileId ),
						);
					}

					continue;
				}

				$anyHashed = false;
				foreach ( $batch['results'] as $result )
				{
					if ( $result['success'] && ! $result['existed'] )
					{
						$anyHashed = true;

						break;
					}
				}

				if ( $anyHashed )
				{
					$processed ++;

					if ( $output !== null && $output->isVeryVerbose() )
					{
						$output->writeln(
							sprintf( '    Hashed fileId %d (%s).', $fileId, $algoLabel ),
						);
					}
					elseif ( $processed % 10 == 0 && $output !== null )
					{
						$output->writeln(
							sprintf( '    %d files processed …', $processed ),
						);
					}
				}
				else
				{
					$skipped ++;

					if ( $output !== null && $output->isVeryVerbose() )
					{
						$output->writeln(
							sprintf( '    Skipped fileId %d (already hashed).', $fileId ),
						);
					}
				}
			}
			catch ( Throwable $e )
			{
				$this->logger->error(
					'FCIAS: recalcHashes failed in generateMissingHashes',
					[
						'app'       => Application::APP_ID,
						'fileId'    => $fileId,
						'algo'      => $algoLabel,
						'exception' => $e,
					],
				);

				// writeln(), not warning(): the command hands over its plain
				// console output, which has no warning(), and calling it here
				// turned one file's failure into the end of the whole run. The
				// message is escaped, since an exception's text may hold what
				// the console would read as a style tag.
				$output?->writeln(
					sprintf(
						'<comment>  WARNING: recalcHashes failed for fileId %d: %s</comment>',
						$fileId,
						OutputFormatter::escape( $e->getMessage() ),
					),
				);

				continue;
			}
		}

		return [
			'processed' => $processed,
			'skipped'   => $skipped,
		];
	}

	/**
	 * The algorithms each version of a page wants: $explicitAlgos, and with
	 * $useRuleAlgos its file's rule's, as {@see generateMissingHashes()}
	 * resolves them for a file. A version that wants none is not among them.
	 *
	 * @param  array<int, array{rule: array|null, path: string}>  $page
	 * @param  list<string>                                         $explicitAlgos
	 *
	 * @return array<int, list<string>>  by the version's id
	 */
	private function wantedOf(
		array $page,
		array $explicitAlgos,
		bool  $useRuleAlgos,
	): array
	{
		$wanted = [];

		foreach ( $page as $versionId => $taken )
		{
			$algos = $useRuleAlgos
				? array_values( array_unique( array_merge( $explicitAlgos, $this->catalogue->computable( $taken['rule']['algos'] ?? [] ) ) ) )
				: $explicitAlgos;

			if ( $algos !== [] )
			{
				$wanted[ $versionId ] = $algos;
			}
		}

		return $wanted;
	}

	/**
	 * Which of these files hold every algorithm they want, current: their
	 * stamp vouches for them ({@see MetadataService::fileStatesOf()}) and
	 * the index lists each one.
	 *
	 * @param  array<int, list<string>>  $wanted  algorithms by file id, at
	 *                                           most a thousand files
	 *
	 * @return array<int, true>  keyed by file id
	 * @throws \OCP\DB\Exception
	 */
	private function freshAmong( array $wanted ): array
	{
		$states  = $wanted === []
			? []
			: $this->metadataService->fileStatesOf( array_keys( $wanted ) );
		$current = array_keys( array_filter( $states, static fn ( array $state ): bool => $state['vouched'] ) );
		$held    = $current === []
			? []
			: $this->metadataService->listedHashes( $current, null );
		$fresh   = [];

		foreach ( $current as $fileId )
		{
			if ( array_diff( $wanted[ $fileId ], array_keys( $held[ $fileId ] ?? [] ) ) === [] )
			{
				$fresh[ $fileId ] = true;
			}
		}

		return $fresh;
	}

	/**
	 * The versions in an account's home a run takes, a page at a time: each
	 * judged at its file's path, by its file's rule
	 * ({@see RuleService::governingRuleForLocation()}), and taken where that
	 * rule says `versions`, or with $all wherever the run may take the file
	 * ({@see RuleOverrides::allows()}). A version whose file is gone is no
	 * file's of the account, and is left. One left by a rule is reported as
	 * a file is ({@see RuleOverrides::report()}).
	 *
	 * @return \Generator<int, array<int, array{rule: array|null, path: string}>>
	 *         per page, by the version's id: its file's rule, and its path
	 * @throws \OCP\DB\Exception
	 */
	private function versionsInHome(
		string           $userId,
		?string          $pathPattern,
		RuleOverrides    $overrides,
		bool             $all,
		?OutputInterface $output,
	): \Generator
	{
		foreach ( $this->filecacheService->homeStorageNumericIds( [ $userId ] ) as $storageNumericId )
		{
			$lastFileId = 0;

			while ( ( $page = $this->filecacheService->pageStorageFiles( $storageNumericId, $lastFileId, 500, under: 'files_versions' ) ) !== [] )
			{
				$lastFileId = $page[ array_key_last( $page ) ]->fileId;
				$versions   = array_values( array_filter( $page, static fn ( FileLocation $location ): bool => $location->version !== null ) );
				$files      = $versions === []
					? []
					: $this->filecacheService->filesOfVersions( $versions );
				$taken      = [];

				foreach ( $versions as $version )
				{
					$file = $files[ $version->fileId ] ?? null;

					if ( $file?->relativePath === null
						|| ( $pathPattern !== null && ! PathUtil::matchesGlob( $pathPattern, ltrim( $file->relativePath, '/' ) ) ) )
					{
						continue;
					}

					$rule = $this->ruleService->governingRuleForLocation( $version->withVersionFile( $file ), $overrides->ignoreRuleIds );
					$path = '/' . $userId . '/' . $version->internalPath;

					if ( ! $overrides->allows( $rule ) || ( ! $all && ! RuleService::hashesVersions( $rule ) ) )
					{
						$overrides->report( $output, $path, $rule, false );

						continue;
					}

					$taken[ $version->fileId ] = [
						'rule' => $rule,
						'path' => $path,
					];
				}

				if ( $taken !== [] )
				{
					yield $taken;
				}
			}
		}
	}

	/**
	 * Centralized processing logic for a single file drained from the queue.
	 *
	 * With $algos omitted (the queue-drain case) the governing rule is
	 * resolved here, at action time: an `include` rule supplies the
	 * algorithm list, and anything else — `ignore`, `exclude`, or no rule
	 * at all — drops the pending mark without hashing. The mark encoded the
	 * *mode* someone asked for; whether the file should be hashed at all is
	 * the rules' call, and rules may have changed between mark and drain.
	 * Before this, the drain hashed every marked file with every supported
	 * algorithm, ignoring the rule's own list.
	 *
	 * An explicit $algos bypasses the rule lookup — for callers that have
	 * already made the decision (the CLI's direct path resolves verdicts,
	 * overrides included, before ever queueing or calling this).
	 *
	 * Where the file's hashes are outdated, it recomputes the rule's
	 * algorithms and drops the rest: a hash computed once by hand is not
	 * recomputed on every change, and an outdated one is worse than none.
	 * With no include rule to recompute anything, outdated hashes are eroded,
	 * as a write to a file no rule maintains erodes them.
	 *
	 * A file it cannot hash now — a read that failed, a lock, a write during
	 * the read — stays queued, its failed attempt counted
	 * ({@see MetadataService::recordFailedAttempt()}); so does one a write
	 * queued again while it was hashed. Neither throws: the outcome is how
	 * the drains tell them from a file done with, and say why.
	 *
	 * @param  string[]|null  $algos  Algorithms to process, or null to take
	 *                                them from the governing rule
	 *
	 * @return DrainOutcome  What it did with the file; whether the file left
	 *                       the queue is {@see DrainOutcome::hasLeftTheQueue()}.
	 * @throws \OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException
	 * @throws \OCP\FilesMetadata\Exceptions\FilesMetadataException
	 */
	public function processFile(
		int    $fileId,
		string $mode,
		?array $algos = null,
	): DrainOutcome
	{
		$file    = null;
		$started = time();

		if ( $algos === null )
		{
			try
			{
				$file = $this->filecacheService->getFile( $fileId );
				$rule = $this->ruleService->findFirstMatchingRule( $fileId );
			}
			catch ( Throwable $e )
			{
				$this->logger->warning(
					'FCIAS: processFile unable to resolve rule for fileId {fileId}, dropping the mark.',
					[
						'app'       => Application::APP_ID,
						'fileId'    => $fileId,
						'exception' => $e,
					],
				);

				$this->metadataService->markPending( $fileId, '' );

				return DrainOutcome::dropped( 'it cannot be resolved: ' . $e->getMessage() );
			}

			if ( ! RuleService::maintainsHashes( $rule ) )
			{
				return $this->dropUnmaintained( $fileId, $file, 'no include rule governs it' );
			}

			// A version is read only where its rule asks for its file's
			// versions; else it keeps what its file handed it, while that
			// holds.
			$location = $this->filecacheService->locate( $fileId );

			if ( ! RuleService::hashesVersions( $rule ) && $location?->version !== null )
			{
				return $this->dropUnmaintained( $fileId, $file, 'it is a version, and its rule does not hash versions' );
			}

			// A trashed file is read only where its rule asks for the trash,
			// and so are its versions; else they keep what they had. A team
			// folder's version stays beside its trashed file, outside the trash.
			if ( ! RuleService::hashesTrash( $rule )
				&& $location !== null
				&& ( $location->trash !== null
					|| ( $location->version !== null && ( $this->filecacheService->filesOfVersions( [ $location ] )[ $fileId ] ?? null )?->trash !== null ) ) )
			{
				return $this->dropUnmaintained( $fileId, $file, 'it is in the trash, and its rule does not hash the trash' );
			}

			// Only what the server computes: narrowing the allowlist leaves
			// the rules as they were, and a file read for an algorithm it
			// would only fail stays queued for ever, read in full each time.
			$algos = $this->catalogue->computable( $rule['algos'] ?? [ $this->getDefaultAlgo() ] );

			if ( $algos === [] && $mode !== MetadataService::PENDING_MODE_MIME )
			{
				return $this->dropUnmaintained( $fileId, $file, 'none of its rule\'s algorithms is computed on this server' );
			}

			// A storage Nextcloud holds unavailable fails every read at once:
			// the attempt is counted without the metadata, the lock or the
			// read. Lazy reads nothing, and goes on.
			if ( $mode !== MetadataService::PENDING_MODE_LAZY && $this->isStorageDown( $file ) )
			{
				$attempts = $this->metadataService->recordFailedAttempt(
					$fileId,
					MetadataService::PENDING_PREFIX . $mode,
				);

				$this->logger->log(
					$attempts > 1
						? LogLevel::DEBUG
						: LogLevel::WARNING,
					'FCIAS: processFile — the storage of fileId {fileId} is unavailable; the file stays queued (failed attempts: {attempts}).',
					[
						'app'      => Application::APP_ID,
						'fileId'   => $fileId,
						'attempts' => $attempts,
						'reason'   => 'storage unavailable',
					],
				);

				return DrainOutcome::failed( 'its storage is unavailable' );
			}
		}

		$metadata = $this->metadataService->getMetadata( $fileId );
		$outdated = $this->isOutdated( $metadata, $file ?? $fileId );
		// Never below the file's mtime, for a clock ahead of the server's;
		// a write landing meanwhile makes recalcHashes() report it busy.
		$stamp    = max( $started, $this->mtimeOf( $file ?? $fileId ) );

		switch ( $mode )
		{
		case MetadataService::PENDING_MODE_MIME:
			return $this->processMimeType( $file ?? $this->filecacheService->getFile( $fileId ), $metadata );

		case 'lazy':
			// Lazy computes nothing: the hashes go, and with them the file's
			// place on the queue. Not through the save below, which reads the
			// stamp of 0 this leaves as "not current" and would keep the file
			// queued for ever.
			$this->metadataService->clearMetadata( $metadata );

			return DrainOutcome::emptied();

			/**
			 * 'force' mode: clear all existing metadata, then intentionally
			 * fall through to 'missing' mode to recompute every algo.
			 *
			 * @noinspection PhpMissingBreakStatementInspection
			 */
		case 'force':
			$this->metadataService->clearMetadata( $metadata, false );

		case 'missing':
			if ( $outdated )
			{
				$this->keepOnly( $metadata, $algos );
			}

			$batch   = $this->recalcHashes( $file ?? $fileId, $algos, true, $metadata );
			$failure = $this->applyBatchResults(
				$batch,
				$algos,
				$file ?? $fileId,
				$mode,
				$metadata,
			);

			if ( $failure !== null )
			{
				return $failure;
			}

			// The ETag the read was against, which recalcHashes() stamped;
			// kept as it is where nothing was missing to read.
			$this->metadataService->stamp( $metadata, $stamp );
			$done = self::outcomeOf( $batch );

			break;

		case 'auto':
			$algosToRecalc = $this->algosFor( $mode, $algos, $metadata, $outdated );

			if ( $outdated )
			{
				$this->keepOnly( $metadata, $algosToRecalc );
			}

			$done = DrainOutcome::stamped();

			if ( $algosToRecalc !== [] )
			{
				$batch   = $this->recalcHashes( $file ?? $fileId, $algosToRecalc, true, $metadata );
				$failure = $this->applyBatchResults(
					$batch,
					$algosToRecalc,
					$file ?? $fileId,
					$mode,
					$metadata,
				);

				if ( $failure !== null )
				{
					return $failure;
				}

				$done = self::outcomeOf( $batch );
			}

			$this->metadataService->stamp( $metadata, $stamp );

			break;

		default:
			// A mode this code no longer knows (a leftover 'pending:new'
			// from before the quiet-start migration, or hand-edited data).
			// Clearing the mark is what keeps it from being re-fetched and
			// re-dropped forever.
			$this->logger->warning(
				'FCIAS: processFile — unknown pending mode "{mode}" for fileId {fileId}, dropping the mark.',
				[
					'app'    => Application::APP_ID,
					'fileId' => $fileId,
					'mode'   => $mode,
				],
			);

			$this->metadataService->markPending( $fileId, '' );

			return DrainOutcome::dropped( sprintf( 'the queue mode "%s" is unknown', $mode ) );
		}

		// Off the queue only once what it asked for is current: a write that
		// landed meanwhile has queued the file again.
		$current = $this->metadataService->saveMetadata( $metadata );

		if ( $current )
		{
			$this->metadataService->clearComputedMarker( $fileId );
		}

		$this->logger->debug(
			'FCIAS HashCalculationService: processFile completed',
			[
				'app'    => Application::APP_ID,
				'fileId' => $fileId,
				'mode'   => $mode,
				'algos'  => $algos,
			],
		);

		return $current
			? $done
			: DrainOutcome::failed( 'written to while it was hashed, and queued again' );
	}

	/**
	 * Persist a recalcHashes() result set for one mode, counting a failed
	 * attempt and saying why on the first failing algorithm.
	 *
	 * The file stays queued, behind the files with fewer failed attempts
	 * ({@see MetadataService::recordFailedAttempt()}), unless the read
	 * failed for a file gone from its storage ({@see failedOrGone()}). Its
	 * first failure is a warning; the retries after it, a file the drain
	 * takes on every pass until it reads again, are debug.
	 *
	 * @param  array{results: array<string, array{success: bool, hash: string, existed: bool, error?: string, reason?: string}>, locked: bool}  $batch
	 *                                                                                                                                         What recalcHashes() returned.
	 * @param  string[]                                                                                                                        $algos
	 *
	 * @return DrainOutcome|null  Null when every algorithm was applied, else
	 *                            the failure, or the file gone.
	 */
	private function applyBatchResults(
		array          $batch,
		array          $algos,
		int|File       $file,
		string         $mode,
		IFilesMetadata $metadata,
	): ?DrainOutcome
	{
		$fileId = $file instanceof File
			? $file->getId()
			: $file;

		foreach ( $algos as $algo )
		{
			$result = $batch['results'][ $algo ] ?? null;

			if ( $result === null || ! $result['success'] )
			{
				$attempts = $this->metadataService->recordFailedAttempt(
					$fileId,
					MetadataService::PENDING_PREFIX . $mode,
				);

				$reason = $result['reason']
					?? (
					$batch['locked']
						? 'busy, or written to during the read'
						: $result['error'] ?? 'unknown'
					);

				$this->logger->log(
					$attempts > 1
						? LogLevel::DEBUG
						: LogLevel::WARNING,
					'FCIAS: processFile recalcHashes failed for algo {algo}; the file stays queued (failed attempts: {attempts}).',
					[
						'app'      => Application::APP_ID,
						'fileId'   => $fileId,
						'algo'     => $algo,
						'attempts' => $attempts,
						'error'    => $reason,
					],
				);

				// A busy file, or one written to during the read, is there.
				return $batch['locked']
					? DrainOutcome::failed( $reason )
					: $this->failedOrGone( $file, $reason );
			}

			// Not indexed by Nextcloud — see MetadataService::syncHashIndex(),
			// which writes the index row truncated to the column's width.
			//
			// Through getHashKey(), never spelled out here: an inline prefix
			// is how this wrote the pre-rename key long after the rename, so
			// every hash the background job computed landed under a name no
			// reader looks for.
			$metadata->setString(
				MetadataService::getHashKey( $algo ),
				$result['hash'],
				false,
			);
		}

		return null;
	}

	/**
	 * A queued file nothing will hash, for $why, off the queue: its hashes
	 * eroded where they no longer describe it, else its mark dropped, the
	 * hashes it has standing.
	 *
	 * @throws \OCP\DB\Exception
	 * @throws \OCP\FilesMetadata\Exceptions\FilesMetadataException
	 */
	private function dropUnmaintained(
		int    $fileId,
		File   $file,
		string $why,
	): DrainOutcome
	{
		if ( $this->isOutdated( $this->metadataService->getMetadata( $fileId ), $file ) )
		{
			$this->logger->debug(
				'FCIAS: processFile — fileId {fileId}: {why}; eroding its outdated hashes.',
				[
					'app'    => Application::APP_ID,
					'fileId' => $fileId,
					'why'    => $why,
				],
			);

			$this->metadataService->markEroded( $fileId );

			return DrainOutcome::dropped( $why . ', and its outdated hashes are eroded' );
		}

		$this->logger->debug(
			'FCIAS: processFile — fileId {fileId}: {why}; dropping the mark.',
			[
				'app'    => Application::APP_ID,
				'fileId' => $fileId,
				'why'    => $why,
			],
		);

		$this->metadataService->markPending( $fileId, '' );

		return DrainOutcome::dropped( $why );
	}

	/**
	 * A failed read's outcome: a failure, the file left queued; or, where the
	 * storage has answered "not found" for the file on two failed reads in a
	 * row, the file gone ({@see MetadataService::markGone()}), off the queue
	 * for good rather than retried for ever.
	 *
	 * "Not found" counts only while the storage's root answers: a storage
	 * that cannot be reached, though Nextcloud has not marked it so, can say
	 * a file is not there. Two answers, because one from a remote storage can
	 * be a passing fault. Failures of any other kind — a storage held down,
	 * a busy file, a write during the read — count toward the queue's order
	 * only. A write landing before the file is marked gone keeps it queued.
	 */
	private function failedOrGone(
		int|File $file,
		string   $reason,
	): DrainOutcome
	{
		try
		{
			$file    = $this->filecacheService->getFile( $file );
			$fileId  = $file->getId();
			$storage = $file->getStorage();

			if ( $storage->file_exists( $file->getInternalPath() ) || ! $storage->file_exists( '' ) )
			{
				$this->metadataService->forgetAbsent( $fileId );

				return DrainOutcome::failed( $reason );
			}

			if ( $this->metadataService->recordAbsent( $fileId ) < 2
				|| $this->filecacheService->etagOf( $fileId ) !== $file->getEtag() )
			{
				return DrainOutcome::failed( $reason );
			}

			$this->metadataService->markGone( $fileId, max( time(), $file->getMTime() ), $file->getEtag() );
		}
		catch ( Throwable )
		{
			return DrainOutcome::failed( $reason );
		}

		$this->logger->info(
			'FCIAS: fileId {fileId} is in the filecache, and its storage does not have it; it leaves the queue as stale:gone. `occ files:scan` brings the filecache in line where it can reach the file.',
			[
				'app'    => Application::APP_ID,
				'fileId' => $file->getId(),
				'reason' => $reason,
			],
		);

		return DrainOutcome::gone();
	}

	/**
	 * Recalculate all currently-indexed algos for a file.
	 *
	 * If no prior hash rows exist, falls back to the default algo.
	 *
	 * @return array{processed: int, algos: string[], locked: bool}
	 */
	public function recalcAllExistingAlgos( int|File $file ): array
	{
		$file     = $this->filecacheService->getFile( $file );
		$metadata = $this->metadataService->getMetadata( $file );

		// Check if any hash keys exist for this file in metadata
		$count = $this->metadataService->countByFileId( $file->getId() );

		if ( $count === 0 )
		{
			$algos = [ $this->getDefaultAlgo() ];
		}
		else
		{
			// getHashes() answers algo => hash. The names are the keys; the
			// values are the hashes themselves, and passing those as
			// algorithm names is how this used to recompute nothing at all —
			// recalcHashes() rejects each one as unsupported.
			$algos = array_keys( $this->metadataService->getHashes( $metadata ) );
		}

		$batch = $this->recalcHashes( $file, $algos, true, $metadata );

		$processed = 0;

		foreach ( $algos as $algo )
		{
			$result = $batch['results'][ $algo ] ?? null;

			if ( $result !== null && $result['success'] )
			{
				$processed ++;
			}
		}

		$this->metadataService->saveMetadata( $metadata );

		return [
			'processed' => $processed,
			'algos'     => $algos,
			'locked'    => $batch['locked'],
		];
	}

	/**
	 * Compute hashes for one or more algorithms in a single pass.
	 *
	 * For a single algorithm the existing per-algo path is used unchanged
	 * (hash_file() for local storage, a single streamed read otherwise).
	 * For two or more algorithms the file is opened once and each read
	 * chunk is fanned out into one hash context per algorithm, so remote
	 * or external storage is read only once instead of once per algorithm.
	 *
	 * Whatever it computes, it stamps: `file-checksum-updated_at` takes the
	 * time the read began. Only `processFile()` used to, so a file hashed by
	 * `occ fcias:hash`, the sidebar or the API carried hashes and no stamp.
	 *
	 * Without $metadata the call is an on-demand one, and saves what it
	 * computed itself. On a file that is queued, or whose hashes are
	 * outdated, it then does what the queue would besides what was asked
	 * ({@see asTheQueueWould()}). With $metadata, the caller has decided what
	 * to compute, and saves.
	 *
	 * `locked` is true when the file was busy: held by another of this app's
	 * workers, or written to while it was being read. Nothing is computed or
	 * kept then.
	 *
	 * @return array{
	 *   results: array<string, array{success: bool, hash: string, existed: bool, error?: string, reason?: string}>,
	 *   locked: bool
	 * }
	 */
	public function recalcHashes(
		int|File        $file,
		array           $algos,
		bool            $skipExisting = true,
		?IFilesMetadata $metadata = null,
	): array
	{
		$algos = array_values( array_unique( array_map( 'strtolower', $algos ) ) );

		// Validate first; invalid algos fail without touching the file.
		$results = [];
		$valid   = [];
		foreach ( $algos as $algo )
		{
			if ( $this->catalogue->isValid( $algo ) )
			{
				$valid[] = $algo;

				continue;
			}

			$results[ $algo ] = [
				'success' => false,
				'hash'    => '',
				'existed' => false,
				'error'   => $this->l10n->t( 'Algorithm not allowed on this server: %s', [ $algo ] ),
				// English for the log, as the error is the reader's language.
				'reason'  => 'Unsupported algorithm: ' . $algo,
			];
		}

		if ( ! $file instanceof File )
		{
			try
			{
				$file = $this->filecacheService->getNodeById( $file );
			}
			catch ( NotFoundException )
			{
				foreach ( $valid as $algo )
				{
					$results[ $algo ] = [
						'success' => false,
						'hash'    => '',
						'existed' => false,
						'error'   => $this->l10n->t( 'File not found.' ),
						'reason'  => 'File not found.',
					];
				}

				return [
					'results' => $results,
					'locked'  => false,
				];
			}

			if ( ! $file instanceof File )
			{
				foreach ( $valid as $algo )
				{
					$results[ $algo ] = [
						'success' => false,
						'hash'    => '',
						'existed' => false,
						'error'   => $this->l10n->t( 'This is not a file.' ),
						'reason'  => 'Node is not a file.',
					];
				}

				return [
					'results' => $results,
					'locked'  => false,
				];
			}
		}

		$fileId    = $file->getId();
		$needsSave = $this->metadataService->ensureMetadata( $fileId, $metadata );

		// A document this call loaded itself is an on-demand caller's.
		if ( $needsSave )
		{
			$valid = $this->asTheQueueWould( $file, $metadata, $valid );
		}

		// Only the pairs this instance could have produced: the column is
		// client-supplied. {@see AlgorithmCatalogue::keepPlausible()}.
		$checksums = $this->catalogue->keepPlausible(
			$this->filecacheService->getChecksums( $file ),
		);

		// Sync: copy hash from filecache.checksum → metadata
		foreach ( $checksums as $prefix => $hexHash )
		{
			$metaKey = MetadataService::getHashKey( $prefix );
			if ( ! $metadata->hasKey( $metaKey ) )
			{
				// Not indexed by Nextcloud: it would write the full value
				// into a varchar(63) column and fail for every hash longer
				// than that. {@see MetadataService::syncHashIndex()} writes
				// the row, truncated to fit.
				$metadata->setString( $metaKey, $hexHash, false );
			}
		}

		$mtime   = $file->getMTime();
		$current = $this->isHashUpToDate( $metadata, $fileId );
		$needed  = [];
		foreach ( $valid as $algo )
		{
			$metaKey = MetadataService::getHashKey( $algo );
			if ( $skipExisting && $metadata->hasKey( $metaKey ) && $current )
			{
				$results[ $algo ] = [
					'success' => true,
					'hash'    => $metadata->getString( $metaKey ),
					'existed' => true,
				];

				continue;
			}

			$needed[] = $algo;
		}

		// The read that hashes the file detects its MIME type as well, from
		// its first bytes. Off, nothing is detected, and a type already
		// stored stays as long as the content it describes.
		$sniff = $this->mimeTypes->isActive();

		if ( $needed === [] )
		{
			if ( $sniff
				&& $valid !== []
				&& $this->metadataService->getMimeType( $metadata ) === null
				&& $current )
			{
				$this->detectTypeAlone( $file, $metadata, $needsSave );
			}

			return [
				'results' => $results,
				'locked'  => false,
			];
		}

		if ( ! $this->acquireLock( $fileId ) )
		{
			foreach ( $needed as $algo )
			{
				$results[ $algo ] = [
					'success' => false,
					'hash'    => '',
					'existed' => false,
				];
			}

			return [
				'results' => $results,
				'locked'  => true,
			];
		}

		// Taken before the read, so that a write landing during it leaves
		// the stamp older than the file rather than vouching for content the
		// read never saw. Never below the mtime of the file as read, which
		// the etag below confirms: a client's clock ahead of the server's
		// would otherwise leave the hashes outdated the moment they are made.
		$started = time();
		$etag    = $file->getEtag();
		$changed = false;

		try
		{
			$storage = $file->getStorage();
			$head    = null;

			$hashes = count( $needed ) === 1
				? [ $needed[0] => $this->computeSingleHash( $file, $storage, $needed[0], $sniff, $head ) ]
				: $this->computeMultiHash( $file, $needed, $sniff, $head );

			// The lock is this app's, and keeps out only this app: a write
			// takes Nextcloud's own locks. Its etag says whether one landed.
			$now     = $this->filecacheService->etagOf( $fileId );
			$changed = $now !== null && $now !== $etag;

			foreach ( $hashes as $algo => $hash )
			{
				if ( $changed )
				{
					$results[ $algo ] = [
						'success' => false,
						'hash'    => '',
						'existed' => false,
					];

					continue;
				}

				// Not indexed by Nextcloud: it would write the full value
				// into a varchar(63) column and fail for every hash longer
				// than that. {@see MetadataService::syncHashIndex()} writes
				// the row, truncated to fit.
				$metadata->setString( MetadataService::getHashKey( $algo ), $hash, false );
				$results[ $algo ] = [
					'success' => true,
					'hash'    => $hash,
					'existed' => false,
				];
			}

			if ( $changed )
			{
				$needsSave = false;
			}
			else
			{
				// The ETag before the read, which the filecache still holds
				// after it: the content the hashes describe.
				$this->metadataService->stamp( $metadata, max( $started, $mtime ), $etag );

				// Content read and no answer is no type, rather than the type
				// of the content as it was.
				if ( $head !== null )
				{
					$this->metadataService->setMimeType( $metadata, $this->mimeTypes->detect( $head ) );
				}
			}
		}
		catch ( Throwable $e )
		{
			foreach ( $needed as $algo )
			{
				$results[ $algo ] = [
					'success' => false,
					'hash'    => '',
					'existed' => false,
					'error'   => $e instanceof HintException ? $e->getHint() : $e->getMessage(),
					'reason'  => $e->getMessage(),
				];
			}

			$needsSave = false;
		}
		finally
		{
			// An on-demand calculation of a queued file did the queue's work
			// as well ({@see asTheQueueWould()}).
			if ( $needsSave && $this->metadataService->saveMetadata( $metadata ) )
			{
				$this->metadataService->clearComputedMarker( $fileId );
			}

			$this->releaseLock( $fileId );
		}

		// A file written to during the read is busy, as a locked one is: the
		// result is discarded, and whoever asked comes back to it.
		return [
			'results' => $results,
			'locked'  => $changed,
		];
	}

	/**
	 * Widen an on-demand calculation to what the queue would compute, where
	 * the file is queued or its hashes are outdated.
	 *
	 * The stamp a calculation writes covers the whole metadata document, so
	 * it may only be written over hashes that describe the content as it is.
	 * Where the document is outdated, the algorithms the file's governing
	 * rule computes in its mode are computed in the same read as the ones
	 * asked for, and every other hash is dropped: a hash computed once by
	 * hand is not kept alive past a change of the content, which is what a
	 * rule is for. Where the file is merely queued, its hashes still
	 * current, nothing is dropped and the queue's algorithms are added.
	 *
	 * @param  list<string>  $asked  Valid algorithms the caller asked for.
	 *
	 * @return list<string>  The algorithms to compute.
	 * @throws \OCP\DB\Exception
	 */
	private function asTheQueueWould(
		File           $file,
		IFilesMetadata $metadata,
		array          $asked,
	): array
	{
		$fileId   = $file->getId();
		$marker   = $this->metadataService->getMarker( $fileId );
		$queued   = $marker !== null && str_starts_with( $marker, MetadataService::PENDING_PREFIX );
		$outdated = $this->isOutdated( $metadata, $file );

		if ( ! $queued && ! $outdated )
		{
			return $asked;
		}

		$rule  = $this->ruleService->findFirstMatchingRule( $fileId );
		$algos = [];

		if ( RuleService::maintainsHashes( $rule ) )
		{
			$algos = $this->algosFor(
				$queued
					? MetadataService::parseMode( $marker )
					: (string) ( $rule['mode'] ?? MetadataService::PENDING_MODE_AUTO ),
				$this->catalogue->computable( (array) ( $rule['algos'] ?? [ $this->getDefaultAlgo() ] ) ),
				$metadata,
				$outdated,
			);
		}

		$compute = array_values(
			array_unique(
				[
					...$asked,
					...$algos,
				],
			),
		);

		if ( $outdated )
		{
			$this->keepOnly( $metadata, $compute );
		}

		return $compute;
	}

	/**
	 * The algorithms the queue computes for a file in $mode, given its rule's
	 * list: under `missing` and `force` all of them, under `lazy` none, and
	 * none under `mime`, which asks for the MIME type alone.
	 *
	 * Under `auto`, all of them where the file has no current hash — none
	 * yet, or only outdated ones — since it is read anyway; where its hashes
	 * are current, only those of them it has, so that `auto` never reads a
	 * file only to add an algorithm.
	 *
	 * @param  list<string>  $ruleAlgos
	 *
	 * @return list<string>
	 */
	private function algosFor(
		string         $mode,
		array          $ruleAlgos,
		IFilesMetadata $metadata,
		bool           $outdated,
	): array
	{
		return match ( $mode )
		{
			MetadataService::PENDING_MODE_AUTO => $outdated || $this->metadataService->getHashes( $metadata ) === []
				? array_values( $ruleAlgos )
				: array_values(
					array_filter(
						$ruleAlgos,
						static fn(
							string $algo,
						): bool => $metadata->hasKey( MetadataService::getHashKey( $algo ) ),
					),
				),
			MetadataService::PENDING_MODE_LAZY,
			MetadataService::PENDING_MODE_MIME => [],
			default                            => array_values( $ruleAlgos ),
		};
	}

	/**
	 * Drop every hash from a metadata document but those of $algos.
	 *
	 * Called for a document whose stamp no longer covers the file, and so
	 * the MIME type goes as well: the read that hashes the file detects it
	 * again where detection is on, and the stamp written after it must not
	 * vouch for the type of the content as it was.
	 *
	 * @param  list<string>  $algos
	 */
	private function keepOnly(
		IFilesMetadata $metadata,
		array          $algos,
	): void
	{
		foreach ( array_keys( $this->metadataService->getHashes( $metadata ) ) as $algo )
		{
			if ( ! in_array( $algo, $algos, true ) )
			{
				$metadata->unset( MetadataService::getHashKey( $algo ) );
			}
		}

		$this->metadataService->setMimeType( $metadata, null );
	}

	/**
	 * Read a file's first {@see MimeTypeService::HEAD_BYTES} for its MIME
	 * type alone, where every hash asked for is held and current and the type
	 * is not: a file hashed before detection was on, or whose hashes came
	 * without a read.
	 *
	 * Not a hash, and nobody's failure: a file busy, changed or unreadable
	 * keeps no type, and its hashes stand.
	 *
	 * @param  bool  $save  Whether the document is the caller's to save, or
	 *                      this call's, as on demand.
	 */
	private function detectTypeAlone(
		File           $file,
		IFilesMetadata $metadata,
		bool           $save,
	): void
	{
		try
		{
			if ( $this->readTypeAlone( $file, $metadata ) === self::TYPE_DETECTED && $save )
			{
				$this->metadataService->saveMetadata( $metadata, $file );
			}
		}
		catch ( Throwable $e )
		{
			$this->logger->debug(
				'FCIAS: could not read fileId {fileId} for its MIME type; it stays without one.',
				[
					'app'       => Application::APP_ID,
					'fileId'    => $file->getId(),
					'exception' => $e,
				],
			);
		}
	}

	/**
	 * `pending:mime`: a hashed file's MIME type, read for alone, the stamp
	 * left as it is.
	 *
	 * The mark is dropped unread where detection is off, since what it asked
	 * for is not wanted any more; and where the document holds no hashes, or
	 * none the stamp still covers: the content changed, and the hash mode the
	 * write or the sweep queues the file for detects its type as it reads.
	 *
	 * A read that fails, a busy file and one written to meanwhile are counted
	 * as a hash mode's are, and the file stays queued.
	 *
	 * @throws \OCP\DB\Exception
	 * @throws \OCP\FilesMetadata\Exceptions\FilesMetadataException
	 */
	private function processMimeType(
		File           $file,
		IFilesMetadata $metadata,
	): DrainOutcome
	{
		$fileId = $file->getId();

		if ( ! $this->mimeTypes->isActive() )
		{
			$this->metadataService->markPending( $fileId, '' );

			return DrainOutcome::dropped( 'MIME type detection is off' );
		}

		if ( $this->metadataService->getHashes( $metadata ) === []
			|| ! $this->isHashUpToDate( $metadata, $fileId ) )
		{
			$this->metadataService->markPending( $fileId, '' );

			return DrainOutcome::dropped( 'it has no current hashes, and the read for them detects the type' );
		}

		try
		{
			$outcome = $this->readTypeAlone( $file, $metadata );
		}
		catch ( Throwable $e )
		{
			return $this->typeReadFailed( $fileId, $e->getMessage(), $file );
		}

		if ( $outcome === self::TYPE_BUSY || $outcome === self::TYPE_CHANGED )
		{
			return $this->typeReadFailed(
				$fileId,
				$outcome === self::TYPE_BUSY
					? 'busy'
					: 'written to during the read',
			);
		}

		// Read, with a type or without one: content libmagic cannot name has
		// none, rather than the one it had.
		$current = $this->metadataService->saveMetadata( $metadata, $file );

		if ( $current )
		{
			$this->metadataService->clearComputedMarker( $fileId );
		}

		return $current
			? DrainOutcome::typed()
			: DrainOutcome::failed( 'written to while it was read, and queued again' );
	}

	/**
	 * Count a failed read for the MIME type, as {@see applyBatchResults()}
	 * counts a hash's: a warning on the file's first, debug after.
	 *
	 * @param  File|null  $read  The file whose read failed, which may be gone
	 *                           from its storage; null for a busy one.
	 *
	 * @return DrainOutcome  A failure, the file left queued; or the file gone.
	 * @throws \OCP\DB\Exception
	 */
	private function typeReadFailed(
		int     $fileId,
		string  $reason,
		?File   $read = null,
	): DrainOutcome
	{
		$attempts = $this->metadataService->recordFailedAttempt( $fileId, MetadataService::PENDING_MIME );

		$this->logger->log(
			$attempts > 1
				? LogLevel::DEBUG
				: LogLevel::WARNING,
			'FCIAS: processFile could not read fileId {fileId} for its MIME type; the file stays queued (failed attempts: {attempts}).',
			[
				'app'      => Application::APP_ID,
				'fileId'   => $fileId,
				'attempts' => $attempts,
				'error'    => $reason,
			],
		);

		return $read === null
			? DrainOutcome::failed( $reason )
			: $this->failedOrGone( $read, $reason );
	}

	/**
	 * Read a file's first {@see MimeTypeService::HEAD_BYTES} and put the
	 * type they show into $metadata, or take the type out where libmagic
	 * names none.
	 *
	 * Under the same guards as a hash: the app's lock, and the etag before
	 * and after the read. A busy file, or one written to meanwhile, changes
	 * nothing.
	 *
	 * @return string  One of the `TYPE_*`.
	 * @throws Throwable  Where the file cannot be read.
	 */
	private function readTypeAlone(
		File           $file,
		IFilesMetadata $metadata,
	): string
	{
		$fileId = $file->getId();

		if ( ! $this->acquireLock( $fileId ) )
		{
			return self::TYPE_BUSY;
		}

		try
		{
			$etag = $file->getEtag();
			$head = $this->readHead( $file );
			$now  = $this->filecacheService->etagOf( $fileId );

			if ( $now !== null && $now !== $etag )
			{
				return self::TYPE_CHANGED;
			}

			$mimeType = $this->mimeTypes->detect( $head );
			$this->metadataService->setMimeType( $metadata, $mimeType );

			return $mimeType === null
				? self::TYPE_NONE
				: self::TYPE_DETECTED;
		}
		finally
		{
			$this->releaseLock( $fileId );
		}
	}

	/**
	 * A file's first {@see MimeTypeService::HEAD_BYTES}, or all of it where
	 * it is shorter.
	 */
	private function readHead( File $file ): string
	{
		$handle = $file->fopen( 'rb' );

		if ( $handle === false )
		{
			throw new HintException( 'Unable to open file for reading.', $this->l10n->t( 'Could not open the file for reading.' ) );
		}

		try
		{
			return self::readFrom( $handle, MimeTypeService::HEAD_BYTES );
		}
		finally
		{
			fclose( $handle );
		}
	}

	/**
	 * Compute a single algorithm's hash using the pre-batch fast paths.
	 *
	 * With $sniff, $head receives the first {@see MimeTypeService::HEAD_BYTES}
	 * of the content: from the stream as it is hashed, or, beside
	 * `hash_file()`, which reads the file without PHP seeing it, read apart.
	 */
	private function computeSingleHash(
		File     $file,
		IStorage $storage,
		string   $algo,
		bool     $sniff = false,
		?string  &$head = null,
	): string
	{
		if ( $storage->isLocal() )
		{
			// Both calls say failure with false. Returned, it met the string
			// return type as a TypeError; thrown, it is the same refusal the
			// stream path below gives an unreadable file. A file gone from
			// the disk is asked after first: hash_file() would warn of it
			// besides, on every attempt the drain makes.
			$absolutePath = $storage->getLocalFile( $file->getInternalPath() );

			if ( $absolutePath === false || ! is_readable( $absolutePath ) )
			{
				throw new HintException( 'Unable to open file for reading.', $this->l10n->t( 'Could not open the file for reading.' ) );
			}

			// Without the type, hash_file() is the fastest read there is. With
			// it, the one read the hash takes gives the head as well: a second
			// open would cost a read of its own, and could fail the hash.
			$hash = $sniff
				? $this->hashLocalWithHead( $absolutePath, $algo, $head )
				: hash_file( $algo, $absolutePath );

			if ( $hash === false )
			{
				throw new HintException( 'Unable to open file for reading.', $this->l10n->t( 'Could not open the file for reading.' ) );
			}

			return $hash;
		}

		$handle = $file->fopen( 'rb' );

		if ( $handle === false )
		{
			throw new HintException( 'Unable to open file for reading.', $this->l10n->t( 'Could not open the file for reading.' ) );
		}

		try
		{
			$ctx = hash_init( $algo );

			if ( $sniff )
			{
				$head = self::readFrom( $handle, MimeTypeService::HEAD_BYTES );
				hash_update( $ctx, $head );
			}

			hash_update_stream( $ctx, $handle );

			return hash_final( $ctx );
		}
		finally
		{
			fclose( $handle );
		}
	}

	/**
	 * A local file's hash in $algo, its first {@see MimeTypeService::HEAD_BYTES}
	 * into $head from the same read; false where it cannot be opened.
	 */
	private function hashLocalWithHead(
		string  $absolutePath,
		string  $algo,
		?string &$head,
	): string|false
	{
		$handle = fopen( $absolutePath, 'rb' );

		if ( $handle === false )
		{
			return false;
		}

		try
		{
			$ctx  = hash_init( $algo );
			$head = self::readFrom( $handle, MimeTypeService::HEAD_BYTES );
			hash_update( $ctx, $head );
			hash_update_stream( $ctx, $handle );

			return hash_final( $ctx );
		}
		finally
		{
			fclose( $handle );
		}
	}

	/**
	 * Stream a file once and feed each chunk into one hash context per
	 * algorithm.
	 *
	 * With $sniff, $head receives the first {@see MimeTypeService::HEAD_BYTES}
	 * of what was read.
	 *
	 * @param  string[]  $algos
	 *
	 * @return array<string, string>  Algo => hex hash
	 */
	private function computeMultiHash(
		File    $file,
		array   $algos,
		bool    $sniff = false,
		?string &$head = null,
	): array
	{
		$handle = $file->fopen( 'rb' );

		if ( $handle === false )
		{
			throw new HintException( 'Unable to open file for reading.', $this->l10n->t( 'Could not open the file for reading.' ) );
		}

		try
		{
			$contexts = [];
			foreach ( $algos as $algo )
			{
				$contexts[ $algo ] = hash_init( $algo );
			}

			$head = $sniff
				? ''
				: null;

			while ( ! feof( $handle ) )
			{
				$chunk = fread( $handle, self::CHUNK_SIZE );

				if ( $chunk === false )
				{
					break;
				}

				if ( $head !== null && strlen( $head ) < MimeTypeService::HEAD_BYTES )
				{
					$head .= substr( $chunk, 0, MimeTypeService::HEAD_BYTES - strlen( $head ) );
				}

				foreach ( $contexts as $ctx )
				{
					hash_update( $ctx, $chunk );
				}
			}

			$hashes = [];
			foreach ( $contexts as $algo => $ctx )
			{
				$hashes[ $algo ] = hash_final( $ctx );
			}

			return $hashes;
		}
		finally
		{
			fclose( $handle );
		}
	}

	/**
	 * Compute a hash for a File node if it does not already exist
	 * in the filecache, then write it back.
	 *
	 * If the checksum already exists for this algo, it is returned
	 * without recomputation. Pass $skipExisting = false to recompute it
	 * anyway.
	 *
	 * @return array{success: bool, algo: string, hash: string, existed: bool, locked?: bool, error?: string}
	 *         `locked` means another process holds the file and nothing was
	 *         computed — a reason to come back later, not a failure.
	 */
	public function recalcFileHash(
		File            $file,
		string          $algo,
		bool            $skipExisting = true,
		?IFilesMetadata $metadata = null,
	): array
	{
		$algo   = strtolower( $algo );
		$result = $this->recalcHashes( $file, [ $algo ], $skipExisting, $metadata );
		$single = $result['results'][ $algo ];

		$out = [
			'success' => $single['success'],
			'algo'    => $algo,
			'hash'    => $single['hash'],
			'existed' => $single['existed'],
		];

		if ( $result['locked'] )
		{
			$out['locked'] = true;
		}
		elseif ( ( $single['error'] ?? '' ) !== '' )
		{
			$out['error'] = $single['error'];
		}

		return $out;
	}

	/**
	 * Recalculate one algorithm's hash for a file, resolving a fileId
	 * through the root folder first.
	 *
	 * Never throws: a fileid that resolves to nothing, or to something that
	 * is not a file, comes back as an unsuccessful result carrying the
	 * reason, because every caller is answering a request that should say
	 * what went wrong rather than failing.
	 *
	 * @return array{success: bool, algo: string, hash: string, existed: bool, locked?: bool, error?: string}
	 */
	public function recalcHash(
		int|File        $file,
		string          $algo,
		bool            $skipExisting = true,
		?IFilesMetadata $metadata = null,
	): array
	{
		$algo = strtolower( $algo );

		if ( ! $file instanceof File )
		{
			try
			{
				$file = $this->filecacheService->getNodeById( $file );
			}
			catch ( NotFoundException )
			{
				return [
					'success' => false,
					'algo'    => $algo,
					'hash'    => '',
					'existed' => false,
					'error'   => $this->l10n->t( 'File not found.' ),
				];
			}

			if ( ! $file instanceof File )
			{
				return [
					'success' => false,
					'algo'    => $algo,
					'hash'    => '',
					'existed' => false,
					'error'   => $this->l10n->t( 'This is not a file.' ),
				];
			}
		}

		return $this->recalcFileHash( $file, $algo, $skipExisting, $metadata );
	}

	/**
	 * A path expressed relative to a base, for display and pattern matching.
	 *
	 * Two answers worth knowing: the base itself is the empty string, not
	 * `/`; and a path that is not under the base at all comes back merely
	 * stripped of its leading slash rather than raising anything. The second
	 * is a deliberate degradation — the callers are reporting progress and
	 * matching globs, where a slightly wrong path beats an exception.
	 */
	private function relativeHashPath(
		string $path,
		string $basePath,
	): string
	{
		$basePath = rtrim( $basePath, '/' );

		if ( $path === $basePath )
		{
			return '';
		}

		$basePath .= '/';

		if ( str_starts_with( $path, $basePath ) )
		{
			return substr( $path, strlen( $basePath ) );
		}

		return ltrim( $path, '/' );
	}

	/**
	 * Release the lock {@see acquireLock()} took. Only call it when that
	 * returned true: this does not check, and Nextcloud's locking provider
	 * treats an unbalanced release as an error.
	 */
	private function releaseLock( int $fileId ): void
	{
		$this->lockingProvider->releaseLock(
			'files/' . $fileId,
			ILockingProvider::LOCK_EXCLUSIVE,
		);
	}


//  static methods

	/**
	 * What a successful batch did: `hashed` where it read anything, `stamped`
	 * where every hash it was asked for existed and only the stamp moved.
	 *
	 * @param  array{results: array<string, array{existed: bool, ...}>, ...}  $batch
	 */
	private static function outcomeOf( array $batch ): DrainOutcome
	{
		foreach ( $batch['results'] as $result )
		{
			if ( ! $result['existed'] )
			{
				return DrainOutcome::hashed();
			}
		}

		return DrainOutcome::stamped();
	}

	/**
	 * Up to $length bytes from a stream, fewer only at its end.
	 *
	 * One `fread()` may return less than it was asked for before the end — a
	 * network stream returns what has arrived — so this reads until it has
	 * $length or the stream has nothing more.
	 *
	 * @param  resource  $handle
	 */
	private static function readFrom(
		$handle,
		int $length,
	): string
	{
		$read = '';

		while ( strlen( $read ) < $length && ! feof( $handle ) )
		{
			$chunk = fread( $handle, $length - strlen( $read ) );

			if ( $chunk === false || $chunk === '' )
			{
				break;
			}

			$read .= $chunk;
		}

		return $read;
	}
}
