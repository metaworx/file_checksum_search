<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\Files_Versions\Versions\IDeletableVersionBackend;
use OCA\Files_Versions\Versions\IMetadataVersion;
use OCA\Files_Versions\Versions\IVersionManager;
use OCA\Files_Versions\Versions\IVersionsImporterBackend;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Folder;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUser;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What the Duplicates page does to the rows it lists, for the account that
 * asks: deleting them, to the trash or for good.
 *
 * A row is a file, a version, a file in the trash or a version of one, each
 * by its own file id, as the listing gives it. Every row is opened through
 * the account's own reach — its folder for a file and for a version's file,
 * its trash for a trashed one — so a row it does not reach is refused, and
 * never acted on through anybody else's. And every row must still hold the
 * hash the page showed: a file changed since is not deleted as a copy.
 */
class DuplicateActionService
{

//  constants

	public const KIND_FILE            = 'file';
	public const KIND_VERSION         = 'version';
	public const KIND_TRASH           = 'trash';
	public const KIND_TRASHED_VERSION = 'trashedVersion';

	/** A file moved to the trash. */
	public const ACTION_TRASH = 'trash';

	/** A row deleted for good: a file asked so, and every other kind, which has no trash to go to. */
	public const ACTION_DELETE = 'delete';

	/** A file that may take the earlier versions of the files deleted beside it. */
	public const ACTION_MERGE_INTO = 'mergeInto';

	/** A trashed file that may be restored to its place ({@see AS_ORIGINAL}). */
	public const ACTION_RESTORE = 'restore';

	/** A trashed version that may go into the file at its place ({@see AS_VERSION}). */
	public const ACTION_RESTORE_AS_VERSION = 'restoreAsVersion';

	/** A version that may be saved as a new file ({@see AS_FILE}). */
	public const ACTION_SAVE_AS_FILE = 'saveAsFile';

	/** How {@see restore()} restores: a trashed file to its place. */
	public const AS_ORIGINAL = 'original';

	/** How {@see restore()} restores: a trashed version into the file at its place. */
	public const AS_VERSION = 'version';

	/** How {@see restore()} restores: a version as a new file. */
	public const AS_FILE = 'file';

	public const STATUS_RESTORED = 'restored';

	/** A dry run's answer for a row it would act on. */
	public const STATUS_READY = 'ready';

	public const STATUS_TRASHED = 'trashed';

	public const STATUS_DELETED = 'deleted';

	/** Not acted on, for a reason the account can do something about. */
	public const STATUS_REFUSED = 'refused';

	/** Acted on, and the act threw. */
	public const STATUS_FAILED = 'failed';

	/** The most rows one request may name. */
	public const MAX_ROWS = 500;

	/**
	 * The order rows are acted on in: a version before the file it is of,
	 * which would take it along, and a trashed row before a live one.
	 */
	private const ORDER = [
		self::KIND_TRASHED_VERSION => 0,
		self::KIND_VERSION         => 1,
		self::KIND_TRASH           => 2,
		self::KIND_FILE            => 3,
	];


//  constructor

	public function __construct(
		private readonly IRootFolder      $rootFolder,
		private readonly FilecacheService $filecacheService,
		private readonly MetadataService  $metadataService,
		private readonly IAppManager      $appManager,
		private readonly IDateTimeZone    $timeZone,
		private readonly IL10N            $l10n,
		private readonly LoggerInterface  $logger,
	) {
	}

	/**
	 * The trash's root items by account, while {@see withActions()} reads
	 * a page; null otherwise, when every lookup asks the trash afresh.
	 *
	 * @var array<string, list<\OCA\Files_Trashbin\Trash\ITrashItem>>|null
	 */
	private ?array $trashRoots = null;


//  other non-static methods

	/**
	 * Delete rows of one duplicate group: a file to the trash, or for good
	 * where $forGood asks; a version, a trashed file and a version of one
	 * for good, having no trash to go to.
	 *
	 * A dry run acts on nothing, and says per row what would happen, with
	 * the versions that go along with a file.
	 *
	 * With $mergeInto, each file's earlier versions are first imported into
	 * that file, which stays: one of the group, the account's own or a team
	 * folder's, and one it may change. A version of the same second as one
	 * the file has is skipped. Where the import fails, a file going to the
	 * trash goes, its versions with it, and one to be deleted for good is
	 * not deleted.
	 *
	 * @param  list<int>  $fileIds    The rows, by their own file ids.
	 * @param  string     $algo       The group's algorithm and full hash,
	 * @param  string     $hash       which each row must still hold.
	 * @param  int|null   $mergeInto  The file that takes the versions.
	 *
	 * @return list<array{fileid: int, kind: ?string, status: string, action?: string, reason?: string, versions?: list<array{timestamp: int, size: int, label: ?string}>, merge?: array{imported: int, skipped: int, failed: bool}}>
	 *         In the order the rows were acted on.
	 */
	public function delete(
		IUser  $user,
		array  $fileIds,
		string $algo,
		string $hash,
		bool   $forGood,
		bool   $dryRun,
		?int   $mergeInto = null,
	): array
	{
		$rows   = $this->open( $user, $fileIds, $algo, $hash );
		$target = $mergeInto === null
			? null
			: $this->mergeTarget( $user, $mergeInto, $fileIds, $algo, $hash );

		usort(
			$rows,
			static fn ( DuplicateRow $a, DuplicateRow $b ): int => ( self::ORDER[ $a->kind ?? '' ] ?? 9 ) <=> ( self::ORDER[ $b->kind ?? '' ] ?? 9 ),
		);

		$results = [];

		foreach ( $rows as $row )
		{
			$action  = $row->kind === self::KIND_FILE && ! $forGood
				? self::ACTION_TRASH
				: self::ACTION_DELETE;
			$refusal = $row->refusal ?? $target?->refusal ?? $this->refusalOf( $row, $action );
			$result  = [
				'fileid' => $row->fileId,
				'kind'   => $row->kind,
			];

			if ( $refusal === null && $row->kind === self::KIND_FILE && $row->node !== null )
			{
				$result['versions'] = $this->versionsOf( $user, $row->node );

				if ( $target?->node !== null )
				{
					$result['merge'] = $this->mergeInto( $user, $row->node, $target->node, $dryRun );

					$refusal = $result['merge']['failed'] && $action === self::ACTION_DELETE
						? $this->l10n->t( 'Its versions could not be merged, so it was not deleted.' )
						: null;
				}
			}

			if ( $refusal !== null )
			{
				$results[] = $result + [
					'status' => self::STATUS_REFUSED,
					'reason' => $refusal,
				];

				continue;
			}

			$result['action'] = $action;

			$results[] = $dryRun
				? $result + [ 'status' => self::STATUS_READY ]
				: $result + $this->act( $user, $row, $action );
		}

		return $results;
	}

	/**
	 * Each file of $groups gains `actions`: what the account may do to it
	 * here, of {@see ACTION_TRASH} and {@see ACTION_DELETE}. Opens a node
	 * per row, which is why the listing asks only on request.
	 *
	 * @param  list<array<string, mixed>>  $groups  As the listing gives
	 *                                             them, each with `files`.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function withActions(
		IUser $user,
		array $groups,
	): array
	{
		$fileIds = [];

		foreach ( $groups as $group )
		{
			foreach ( (array) ( $group['files'] ?? [] ) as $file )
			{
				$fileIds[] = (int) $file['fileid'];
			}
		}

		$rows = [];

		// The trash's items read once for the whole page, not once a row.
		$this->trashRoots = [];

		try
		{
			foreach ( $this->filecacheService->locateAll( $fileIds ) as $fileId => $location )
			{
				$rows[ $fileId ] = $this->openAs( $user, $location );
			}

			return array_map(
				fn ( array $group ): array => [
					'files' => array_map(
						fn ( array $file ): array => $file + [ 'actions' => $this->actionsOf( $user, $rows[ (int) $file['fileid'] ] ?? null ) ],
						(array) ( $group['files'] ?? [] ),
					),
				] + $group,
				$groups,
			);
		}
		finally
		{
			$this->trashRoots = null;
		}
	}

	/**
	 * Restore one row of a duplicate group, the account's own, as $as says:
	 *
	 * - {@see AS_ORIGINAL}, a file in the trash, to its place: the trash's
	 *   own restore, with the folders it was in made again where they are
	 *   gone, and the file moved there where the trash put it elsewhere;
	 * - {@see AS_VERSION}, a version of a trashed file, into the file now at
	 *   that file's place, as an earlier version of it;
	 * - {@see AS_FILE}, any version, as a new file beside its file, or at
	 *   the trashed file's place, named after it and the version's time.
	 *
	 * A name taken is made unique. The row must still hold $hash under
	 * $algo; a version restored stays where it was, as a copy does.
	 *
	 * @return array{fileid: int, kind: ?string, status: string, reason?: string, restored?: array{fileid: int, path: string}}
	 */
	public function restore(
		IUser  $user,
		int    $fileId,
		string $algo,
		string $hash,
		string $as,
	): array
	{
		$row     = $this->open( $user, [ $fileId ], $algo, $hash )[0];
		$result  = [
			'fileid' => $fileId,
			'kind'   => $row->kind,
		];
		$refusal = $row->refusal ?? $this->restoreRefusal( $user, $row, $as );

		if ( $refusal !== null )
		{
			return $result + [
				'status' => self::STATUS_REFUSED,
				'reason' => $refusal,
			];
		}

		try
		{
			$node = match ( $as )
			{
				self::AS_ORIGINAL => $this->restoreTrashed( $user, $row ),
				self::AS_VERSION  => $this->restoreAsVersion( $user, $row ),
				default           => $this->saveAsFile( $user, $row ),
			};
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS DuplicateActionService: a row could not be restored',
				[
					'app'       => Application::APP_ID,
					'user'      => $user->getUID(),
					'fileId'    => $fileId,
					'as'        => $as,
					'exception' => $e,
				],
			);

			return $result + [
				'status' => self::STATUS_FAILED,
				'reason' => $this->l10n->t( 'It could not be restored.' ),
			];
		}

		$this->logger->info(
			'FCIAS DuplicateActionService: a duplicate was restored',
			[
				'app'    => Application::APP_ID,
				'user'   => $user->getUID(),
				'fileId' => $fileId,
				'as'     => $as,
			],
		);

		return $result + [
			'status'   => self::STATUS_RESTORED,
			'restored' => [
				'fileid' => $node->getId(),
				'path'   => (string) $this->rootFolder->getUserFolder( $user->getUID() )->getRelativePath( $node->getPath() ),
			],
		];
	}

	/**
	 * What may be done to $row, of {@see ACTION_TRASH}, {@see ACTION_DELETE}
	 * and {@see ACTION_MERGE_INTO}, which are a file's alone but deleting,
	 * and of {@see ACTION_RESTORE}, {@see ACTION_RESTORE_AS_VERSION} and
	 * {@see ACTION_SAVE_AS_FILE}, which are a trashed file's and a
	 * version's.
	 *
	 * @return list<string>
	 */
	private function actionsOf(
		IUser         $user,
		?DuplicateRow $row,
	): array
	{
		if ( $row === null || $row->refusal !== null )
		{
			return [];
		}

		$actions = [];

		foreach ( [ self::ACTION_TRASH, self::ACTION_DELETE ] as $action )
		{
			if ( ( $action === self::ACTION_DELETE || $row->kind === self::KIND_FILE )
			     && $this->refusalOf( $row, $action ) === null )
			{
				$actions[] = $action;
			}
		}

		if ( $this->mayTakeVersions( $row ) )
		{
			$actions[] = self::ACTION_MERGE_INTO;
		}

		foreach (
			[
				self::AS_ORIGINAL => self::ACTION_RESTORE,
				self::AS_VERSION  => self::ACTION_RESTORE_AS_VERSION,
				self::AS_FILE     => self::ACTION_SAVE_AS_FILE,
			] as $as => $action
		)
		{
			if ( $this->restoreRefusal( $user, $row, $as ) === null )
			{
				$actions[] = $action;
			}
		}

		return $actions;
	}

	/**
	 * Why $row may not be restored as $as, or null where it may: a row of
	 * another kind; for a version into a file, no file at its place that
	 * may take it, or one with a version of that second already.
	 */
	private function restoreRefusal(
		IUser        $user,
		DuplicateRow $row,
		string       $as,
	): ?string
	{
		$fits = match ( $as )
		{
			self::AS_ORIGINAL => $row->kind === self::KIND_TRASH && $row->trashItem !== null,
			self::AS_VERSION  => $row->kind === self::KIND_TRASHED_VERSION,
			self::AS_FILE     => $row->kind === self::KIND_VERSION || $row->kind === self::KIND_TRASHED_VERSION,
			default           => false,
		};

		if ( ! $fits )
		{
			return $this->l10n->t( 'It cannot be restored that way.' );
		}

		if ( $as !== self::AS_VERSION )
		{
			return null;
		}

		$target = $this->fileAtPlaceOf( $user, $row );

		if ( $target === null || ! $this->mayTakeVersions( new DuplicateRow( $target->getId(), self::KIND_FILE, node: $target ) ) )
		{
			return $this->l10n->t( 'No file of yours is at its place to take it.' );
		}

		$timestamp = (string) $this->timestampOf( $row );

		foreach ( $this->listVersions( $user, $target ) as $version )
		{
			if ( (string) $version->getRevisionId() === $timestamp )
			{
				return $this->l10n->t( 'The file at its place already has a version of that time.' );
			}
		}

		return null;
	}

	/**
	 * The trashed file back at its place, its folders made again where
	 * gone; moved there where the trash put it elsewhere, as it does a file
	 * inside a trashed folder, one whose folder is gone, and, in a team
	 * folder, one whose name is taken in a subfolder.
	 */
	private function restoreTrashed(
		IUser        $user,
		DuplicateRow $row,
	): File
	{
		$userFolder = $this->rootFolder->getUserFolder( $user->getUID() );
		$place      = ltrim( (string) $row->trashItem?->getOriginalLocation(), '/' );
		$folder     = self::folderAt( $userFolder, dirname( $place ) );

		$this->trashManager()?->restoreItem( $row->trashItem );

		$node = $userFolder->getFirstNodeById( $row->fileId );

		if ( ! $node instanceof File )
		{
			throw new \RuntimeException( 'The trash restored the file where it cannot be found.' );
		}

		if ( $node->getParent()->getId() !== $folder->getId() )
		{
			$node = $node->move( $folder->getPath() . '/' . $folder->getNonExistingName( basename( $place ) ) );
		}

		if ( ! $node instanceof File )
		{
			throw new \RuntimeException( 'The restored file could not be moved to its place.' );
		}

		return $node;
	}

	/**
	 * A version of a trashed file imported into the file now at that
	 * file's place. A home's is handed to the importer through
	 * {@see TrashedVersion}, a team folder's as the versions app lists it.
	 */
	private function restoreAsVersion(
		IUser        $user,
		DuplicateRow $row,
	): File
	{
		$target  = $this->fileAtPlaceOf( $user, $row );
		$source  = $this->trashedFileOf( $user, $row );
		$version = $row->version;

		if ( $target === null || $source === null )
		{
			throw new \RuntimeException( 'The trashed file, or the file at its place, is gone.' );
		}

		if ( $version === null && $row->node !== null )
		{
			$version = new TrashedVersion( $row->node, $source, $this->timestampOf( $row ), $user );
		}

		$importer = $this->importerFor( $target );

		if ( $importer === null || $version === null )
		{
			throw new \RuntimeException( 'The version cannot be imported into the file at its place.' );
		}

		$importer->importVersionsForFile( $user, $source, $target, [ $version ] );

		return $target;
	}

	/**
	 * A version's content as a new file: beside its file, or at its trashed
	 * file's place, made again where gone; named after the file and the
	 * version's time, in the account's time zone, unique in its folder.
	 */
	private function saveAsFile(
		IUser        $user,
		DuplicateRow $row,
	): File
	{
		$timestamp = $this->timestampOf( $row );

		if ( $row->kind === self::KIND_VERSION && $row->node !== null )
		{
			$name    = $row->node->getName();
			$folder  = $row->node->getParent();
			$content = $this->versionManager()?->getVersionFile( $user, $row->node, $timestamp )->fopen( 'r' );
		}
		else
		{
			$source  = $this->trashedFileOf( $user, $row );
			$place   = ltrim( (string) ( $source === null ? '' : $this->placeOf( $user, $source ) ), '/' );
			$name    = basename( $place );
			$folder  = self::folderAt( $this->rootFolder->getUserFolder( $user->getUID() ), dirname( $place ) );
			$content = $row->version === null
				? $row->node?->fopen( 'r' )
				: ( $source === null ? null : $this->versionManager()?->getVersionFile( $user, $source, $timestamp )->fopen( 'r' ) );
		}

		if ( ! is_resource( $content ) || $name === '' )
		{
			throw new \RuntimeException( 'The version could not be read.' );
		}

		$at = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $this->timeZone->getTimeZone( $timestamp, $user->getUID() ) );

		return $folder->newFile( $folder->getNonExistingName( self::versionedName( $name, $at->format( 'Y-m-d H-i-s' ) ) ), $content );
	}

	/**
	 * The file now at the place of $row's trashed file, or null: none
	 * there, or a folder.
	 */
	private function fileAtPlaceOf(
		IUser        $user,
		DuplicateRow $row,
	): ?File
	{
		$source = $this->trashedFileOf( $user, $row );
		$place  = $source === null
			? null
			: $this->placeOf( $user, $source );

		try
		{
			$node = $place === null
				? null
				: $this->rootFolder->getUserFolder( $user->getUID() )->get( ltrim( $place, '/' ) );
		}
		catch ( Throwable )
		{
			return null;
		}

		return $node instanceof File
			? $node
			: null;
	}

	/**
	 * The trashed file a version of one is of: a team folder's row was
	 * opened through it; a home's is found by the version's own path.
	 */
	private function trashedFileOf(
		IUser        $user,
		DuplicateRow $row,
	): ?File
	{
		if ( $row->kind === self::KIND_TRASH || $row->version !== null )
		{
			return $row->node;
		}

		$location = $this->filecacheService->locate( $row->fileId );
		$file     = $location === null
			? null
			: $this->filecacheService->filesOfVersions( [ $location ] )[ $row->fileId ] ?? null;
		$node     = $file === null
			? null
			: $this->trashManager()?->getTrashNodeById( $user, $file->fileId );

		return $node instanceof File
			? $node
			: null;
	}

	/**
	 * Where a trashed file was, below the account's folder, as its trash
	 * item says; null where the trash cannot say.
	 */
	private function placeOf(
		IUser $user,
		File  $trashed,
	): ?string
	{
		$manager = $this->trashManager();
		$item    = $manager === null
			? null
			: $this->trashItemOf( $user, $manager, $trashed );

		return $item?->getOriginalLocation();
	}

	/**
	 * When the file held the version $row is.
	 */
	private function timestampOf( DuplicateRow $row ): int
	{
		return $row->version !== null
			? (int) $row->version->getRevisionId()
			: (int) $this->filecacheService->locate( $row->fileId )?->version?->timestamp;
	}

	/**
	 * The file the versions go into, opened as a row is, or a row refusing
	 * every other: one the request also deletes, one out of the group, or
	 * one that may not take them.
	 *
	 * @param  list<int>  $fileIds
	 */
	private function mergeTarget(
		IUser  $user,
		int    $fileId,
		array  $fileIds,
		string $algo,
		string $hash,
	): DuplicateRow
	{
		$row = in_array( $fileId, array_map( intval( ... ), $fileIds ), true )
			? null
			: $this->open( $user, [ $fileId ], $algo, $hash )[0] ?? null;

		return $row !== null && $this->mayTakeVersions( $row )
			? $row
			: new DuplicateRow( $fileId, $row?->kind, refusal: $this->l10n->t( 'The file chosen for the versions cannot take them.' ) );
	}

	/**
	 * Whether $row may take the versions of others: a file, opened, that
	 * the account may change, held in its own home or a team folder, on a
	 * storage whose versions backend can import. A received share's
	 * versions are its owner's, which an import as the account would miss.
	 */
	private function mayTakeVersions( DuplicateRow $row ): bool
	{
		$node = $row->node;

		return $row->refusal === null
		       && $row->kind === self::KIND_FILE
		       && $node !== null
		       && $node->isUpdateable()
		       && ! $node->getStorage()->instanceOfStorage( ISharedStorage::class )
		       && $this->importerFor( $node ) !== null;
	}

	/**
	 * $source's earlier versions imported into $target, but those of a
	 * second $target already has a version of; on a dry run, only counted.
	 *
	 * @return array{imported: int, skipped: int, failed: bool}
	 */
	private function mergeInto(
		IUser $user,
		File  $source,
		File  $target,
		bool  $dryRun,
	): array
	{
		$taken = [ (string) $target->getMTime() => true ];

		foreach ( $this->listVersions( $user, $target ) as $version )
		{
			$taken[ (string) $version->getRevisionId() ] = true;
		}

		$versions = [];
		$skipped  = 0;

		foreach ( $this->listVersions( $user, $source ) as $version )
		{
			$revision = (string) $version->getRevisionId();

			if ( $revision === (string) $source->getMTime() )
			{
				continue;
			}

			if ( isset( $taken[ $revision ] ) )
			{
				$skipped++;

				continue;
			}

			$versions[] = $version;
		}

		$failed = false;

		if ( ! $dryRun && $versions !== [] )
		{
			try
			{
				$importer = $this->importerFor( $target );

				if ( $importer === null )
				{
					throw new \RuntimeException( 'The versions backend of the target cannot import.' );
				}

				$importer->importVersionsForFile( $user, $source, $target, $versions );
			}
			catch ( Throwable $e )
			{
				$failed = true;

				$this->logger->warning(
					'FCIAS DuplicateActionService: versions could not be merged',
					[
						'app'       => Application::APP_ID,
						'user'      => $user->getUID(),
						'source'    => $source->getId(),
						'target'    => $target->getId(),
						'exception' => $e,
					],
				);
			}
		}

		return [
			'imported' => $failed
				? 0
				: count( $versions ),
			'skipped'  => $skipped,
			'failed'   => $failed,
		];
	}

	/**
	 * The versions backend of $file's storage where it can import
	 * versions, or null.
	 *
	 * @return \OCA\Files_Versions\Versions\IVersionsImporterBackend|null
	 */
	private function importerFor( File $file ): ?object
	{
		try
		{
			$backend = $this->versionManager()?->getBackendForStorage( $file->getStorage() );
		}
		catch ( Throwable )
		{
			return null;
		}

		return $backend instanceof IVersionsImporterBackend
			? $backend
			: null;
	}

	/**
	 * Every version the versions app lists for $file, its current one
	 * among them; none where it cannot say.
	 *
	 * @return list<\OCA\Files_Versions\Versions\IVersion>
	 */
	private function listVersions(
		IUser $user,
		File  $file,
	): array
	{
		try
		{
			return array_values( $this->versionManager()?->getVersionsForFile( $user, $file ) ?? [] );
		}
		catch ( Throwable $e )
		{
			$this->logger->debug(
				'FCIAS DuplicateActionService: the versions of a file could not be listed',
				[
					'app'       => Application::APP_ID,
					'fileId'    => $file->getId(),
					'exception' => $e,
				],
			);

			return [];
		}
	}

	/**
	 * The rows of $fileIds, each opened through $user's reach, or refused:
	 * gone, out of reach, or no longer holding $hash under $algo.
	 *
	 * @param  list<int>  $fileIds
	 *
	 * @return list<DuplicateRow>
	 */
	private function open(
		IUser  $user,
		array  $fileIds,
		string $algo,
		string $hash,
	): array
	{
		$fileIds   = array_values( array_unique( array_map( intval( ... ), $fileIds ) ) );
		$locations = $this->filecacheService->locateAll( $fileIds );
		$hashes    = $this->metadataService->listedHashes( array_keys( $locations ), $algo );
		$rows      = [];

		foreach ( $fileIds as $fileId )
		{
			$location = $locations[ $fileId ] ?? null;
			$listed   = $hashes[ $fileId ][ $algo ] ?? null;

			if ( $location === null )
			{
				$rows[] = new DuplicateRow( $fileId, null, refusal: $this->notFound() );
			}
			elseif ( $listed === null
			         || strtolower( $listed ) !== strtolower( $hash )
			         || ! $this->metadataService->isVouched( $fileId ) )
			{
				$rows[] = new DuplicateRow(
					$fileId,
					$this->kindOf( $location ),
					refusal: $this->l10n->t( 'Its content changed after the list was loaded.' ),
				);
			}
			else
			{
				$rows[] = $this->openAs( $user, $location );
			}
		}

		return $rows;
	}

	/**
	 * One row opened through $user's reach, by its kind; refused where the
	 * reach does not hold it, or the app it belongs to is not there.
	 */
	private function openAs(
		IUser        $user,
		FileLocation $location,
	): DuplicateRow
	{
		$kind = $this->kindOf( $location );

		try
		{
			return match ( $kind )
			{
				self::KIND_FILE    => $this->openFile( $user, $location ),
				self::KIND_VERSION => $this->openVersion( $user, $location ),
				self::KIND_TRASH   => $this->openTrashed( $user, $location ),
				default            => $this->openTrashedVersion( $user, $location ),
			};
		}
		catch ( Throwable $e )
		{
			$this->logger->debug(
				'FCIAS DuplicateActionService: a row could not be opened',
				[
					'app'       => Application::APP_ID,
					'fileId'    => $location->fileId,
					'exception' => $e,
				],
			);

			return new DuplicateRow( $location->fileId, $kind, refusal: $this->notFound() );
		}
	}

	/**
	 * A version of a file in the trash in a team folder sits beside its
	 * file, outside the folder's trash, so its file says it is trashed.
	 */
	private function kindOf( FileLocation $location ): string
	{
		if ( $location->version !== null )
		{
			$file = $location->trash === null
				? $this->filecacheService->filesOfVersions( [ $location ] )[ $location->fileId ] ?? null
				: null;

			return $location->trash !== null || $file?->trash !== null
				? self::KIND_TRASHED_VERSION
				: self::KIND_VERSION;
		}

		return $location->trash !== null
			? self::KIND_TRASH
			: self::KIND_FILE;
	}

	private function openFile(
		IUser        $user,
		FileLocation $location,
	): DuplicateRow
	{
		$node = $this->rootFolder->getUserFolder( $user->getUID() )->getFirstNodeById( $location->fileId );

		return $node instanceof File
			? new DuplicateRow( $location->fileId, self::KIND_FILE, node: $node )
			: new DuplicateRow( $location->fileId, self::KIND_FILE, refusal: $this->notFound() );
	}

	private function openVersion(
		IUser        $user,
		FileLocation $location,
	): DuplicateRow
	{
		$file    = $this->filecacheService->filesOfVersions( [ $location ] )[ $location->fileId ] ?? null;
		$node    = $file === null
			? null
			: $this->rootFolder->getUserFolder( $user->getUID() )->getFirstNodeById( $file->fileId );
		$version = $node instanceof File && $location->version !== null
			? $this->versionOf( $user, $node, $location->version->timestamp )
			: null;

		return $version === null || ! $node instanceof File
			? new DuplicateRow( $location->fileId, self::KIND_VERSION, refusal: $this->notFound() )
			: new DuplicateRow( $location->fileId, self::KIND_VERSION, node: $node, version: $version );
	}

	private function openTrashed(
		IUser        $user,
		FileLocation $location,
	): DuplicateRow
	{
		$manager = $this->trashManager();
		$node    = $manager?->getTrashNodeById( $user, $location->fileId );
		$item    = $node instanceof File && $manager !== null
			? $this->trashItemOf( $user, $manager, $node )
			: null;

		return $item === null || ! $node instanceof File
			? new DuplicateRow( $location->fileId, self::KIND_TRASH, refusal: $this->notFound() )
			: new DuplicateRow( $location->fileId, self::KIND_TRASH, node: $node, trashItem: $item );
	}

	/**
	 * A home's version of a trashed file is a file of its own in the
	 * trash's versions area, deleted as one. A team folder's stays among the
	 * folder's versions, and is deleted through its trashed file, as the
	 * versions app deletes any version.
	 */
	private function openTrashedVersion(
		IUser        $user,
		FileLocation $location,
	): DuplicateRow
	{
		if ( $location->namespace === FileLocation::NS_HOME )
		{
			$node = $location->owner === $user->getUID()
				? $this->rootFolder->getUserFolder( $user->getUID() )->getParent()->getFirstNodeById( $location->fileId )
				: null;

			return $node instanceof File && str_starts_with( $node->getInternalPath(), 'files_trashbin/versions/' )
				? new DuplicateRow( $location->fileId, self::KIND_TRASHED_VERSION, node: $node )
				: new DuplicateRow( $location->fileId, self::KIND_TRASHED_VERSION, refusal: $this->notFound() );
		}

		$file    = $this->filecacheService->filesOfVersions( [ $location ] )[ $location->fileId ] ?? null;
		$node    = $file === null
			? null
			: $this->trashManager()?->getTrashNodeById( $user, $file->fileId );
		$version = $node instanceof File && $location->version !== null
			? $this->versionOf( $user, $node, $location->version->timestamp )
			: null;

		return $version === null || ! $node instanceof File
			? new DuplicateRow( $location->fileId, self::KIND_TRASHED_VERSION, refusal: $this->notFound() )
			: new DuplicateRow( $location->fileId, self::KIND_TRASHED_VERSION, node: $node, version: $version );
	}

	/**
	 * Why $row may not be acted on as $action, or null where it may: the
	 * trash or the versions app not there to do it, a file the account may
	 * not delete, or a received share's root, whose deletion in Files only
	 * leaves the share and frees nothing.
	 */
	private function refusalOf(
		DuplicateRow $row,
		string       $action,
	): ?string
	{
		$node = $row->node;

		if ( $action === self::ACTION_TRASH && $this->trashManager() === null )
		{
			return $this->l10n->t( 'The trash is not available, so it can only be deleted permanently.' );
		}

		if ( $row->kind === self::KIND_FILE && $node !== null && self::isShareRoot( $node ) )
		{
			return $this->l10n->t( 'It is shared with you. Leave the share in Files instead.' );
		}

		if ( ( $row->kind === self::KIND_FILE || $row->kind === self::KIND_VERSION ) && ! $node?->isDeletable() )
		{
			return $this->l10n->t( 'You may not delete it.' );
		}

		if ( $row->version !== null && ! $this->versionManager() instanceof IDeletableVersionBackend )
		{
			return $this->l10n->t( 'Its versions cannot be deleted here.' );
		}

		return null;
	}

	/**
	 * Do it: the trash paused around a file deleted for good, so that it
	 * does not go there regardless.
	 *
	 * @return array{status: string, reason?: string}
	 */
	private function act(
		IUser        $user,
		DuplicateRow $row,
		string       $action,
	): array
	{
		try
		{
			if ( $row->version !== null )
			{
				$this->deleteVersion( $row->version );
			}
			elseif ( $row->trashItem !== null )
			{
				$this->trashManager()?->removeItem( $row->trashItem );
			}
			elseif ( $row->node !== null && $row->kind === self::KIND_FILE && $action === self::ACTION_DELETE )
			{
				$this->deleteForGood( $row->node );
			}
			else
			{
				$row->node?->delete();
			}
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS DuplicateActionService: a row could not be deleted',
				[
					'app'       => Application::APP_ID,
					'user'      => $user->getUID(),
					'fileId'    => $row->fileId,
					'kind'      => $row->kind,
					'action'    => $action,
					'exception' => $e,
				],
			);

			return [
				'status' => self::STATUS_FAILED,
				'reason' => $this->l10n->t( 'It could not be deleted.' ),
			];
		}

		$this->logger->info(
			'FCIAS DuplicateActionService: a duplicate was deleted',
			[
				'app'    => Application::APP_ID,
				'user'   => $user->getUID(),
				'fileId' => $row->fileId,
				'kind'   => $row->kind,
				'action' => $action,
			],
		);

		return [
			'status' => $action === self::ACTION_TRASH
				? self::STATUS_TRASHED
				: self::STATUS_DELETED,
		];
	}

	private function deleteForGood( File $node ): void
	{
		$trash = $this->trashManager();
		$trash?->pauseTrash();

		try
		{
			$node->delete();
		}
		finally
		{
			$trash?->resumeTrash();
		}
	}

	/**
	 * @param  \OCA\Files_Versions\Versions\IVersion  $version
	 */
	private function deleteVersion( object $version ): void
	{
		$manager = $this->versionManager();

		if ( ! $manager instanceof IDeletableVersionBackend )
		{
			throw new \RuntimeException( 'The versions app cannot delete a version.' );
		}

		$manager->deleteVersion( $version );
	}

	/**
	 * The version of $file kept at $timestamp, as the versions app lists
	 * it, or null.
	 *
	 * @return \OCA\Files_Versions\Versions\IVersion|null
	 */
	private function versionOf(
		IUser $user,
		File  $file,
		int   $timestamp,
	): ?object
	{
		foreach ( $this->listVersions( $user, $file ) as $version )
		{
			if ( (string) $version->getRevisionId() === (string) $timestamp )
			{
				return $version;
			}
		}

		return null;
	}

	/**
	 * The earlier versions of $file that go along with it, named ones
	 * labelled; none where the versions app is not there to say.
	 *
	 * @return list<array{timestamp: int, size: int, label: ?string}>
	 */
	private function versionsOf(
		IUser $user,
		File  $file,
	): array
	{
		$versions = [];

		foreach ( $this->listVersions( $user, $file ) as $version )
		{
			// The file's current content is listed among them, and is the
			// file itself.
			if ( (string) $version->getRevisionId() === (string) $file->getMTime() )
			{
				continue;
			}

			$label      = $version instanceof IMetadataVersion
				? $version->getMetadataValue( 'label' )
				: null;
			$versions[] = [
				'timestamp' => (int) $version->getTimestamp(),
				'size'      => (int) $version->getSize(),
				'label'     => $label === '' ? null : $label,
			];
		}

		return $versions;
	}

	/**
	 * The trash's own item for $node, which removing it needs: among the
	 * account's trash, descending only into the trashed folder $node lies
	 * in.
	 *
	 * @return \OCA\Files_Trashbin\Trash\ITrashItem|null
	 */
	private function trashItemOf(
		IUser         $user,
		ITrashManager $manager,
		File          $node,
	): ?object
	{
		$path    = $node->getPath() . '/';
		$pending = $this->trashRoots === null
			? $manager->listTrashRoot( $user )
			: $this->trashRoots[ $user->getUID() ] ??= $manager->listTrashRoot( $user );

		while ( $pending !== [] )
		{
			$item = array_shift( $pending );

			if ( $item->getId() === $node->getId() )
			{
				return $item;
			}

			if ( $item->getType() === FileInfo::TYPE_FOLDER && str_contains( $path, '/' . basename( $item->getTrashPath() ) . '/' ) )
			{
				array_push( $pending, ...$manager->listTrashFolder( $item ) );
			}
		}

		return null;
	}

	private function trashManager(): ?ITrashManager
	{
		return interface_exists( ITrashManager::class ) && $this->appManager->isEnabledForUser( 'files_trashbin' )
			? Server::get( ITrashManager::class )
			: null;
	}

	/**
	 * The versions app's manager, typed as no more than an object: the
	 * interfaces it implements beside {@see IVersionManager}, deleting a
	 * version among them, are its own to declare.
	 */
	private function versionManager(): ?object
	{
		return interface_exists( IVersionManager::class ) && $this->appManager->isEnabledForUser( 'files_versions' )
			? Server::get( IVersionManager::class )
			: null;
	}

	private function notFound(): string
	{
		return $this->l10n->t( 'It is not among your files.' );
	}


//  static methods

	/**
	 * Whether $node is what a share received mounts: a file shared alone,
	 * whose storage is the share and whose path in it is empty.
	 */
	private static function isShareRoot( File $node ): bool
	{
		return $node->getInternalPath() === ''
		       && $node->getStorage()->instanceOfStorage( ISharedStorage::class );
	}

	/**
	 * The folder at $path below $root, its missing folders made a segment
	 * at a time; $root itself for `.`, as dirname() gives for a name
	 * alone, or nothing.
	 */
	private static function folderAt(
		Folder $root,
		string $path,
	): Folder
	{
		$folder = $root;

		foreach ( explode( '/', $path === '.' ? '' : trim( $path, '/' ) ) as $segment )
		{
			if ( $segment === '' )
			{
				continue;
			}

			$next = $folder->nodeExists( $segment )
				? $folder->get( $segment )
				: $folder->newFolder( $segment );

			if ( ! $next instanceof Folder )
			{
				throw new \RuntimeException( 'A file stands where a folder of the path was.' );
			}

			$folder = $next;
		}

		return $folder;
	}

	/**
	 * $name with $stamp before its extension: `report (2026-10-21
	 * 13-37-05).pdf`; after the whole name where it has none, or is all
	 * extension, as `.env` is.
	 */
	private static function versionedName(
		string $name,
		string $stamp,
	): string
	{
		$dot = strrpos( $name, '.' );

		return $dot === false || $dot === 0
			? "$name ($stamp)"
			: substr( $name, 0, $dot ) . " ($stamp)" . substr( $name, $dot );
	}
}
