<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Search;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\ReachResolver;
use OCP\Files\IRootFolder;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;

/**
 * Finds a file by its hash from Nextcloud's unified search.
 *
 * A hash is a fingerprint of content, so answering "who else has this?"
 * across accounts would leak what other people hold. Three things stand
 * between the index and an answer, in this order: the caller's mounted
 * storages narrow the query, the full hash is confirmed against the
 * document because the index stores only 63 characters, and every surviving
 * row is resolved through the caller's own folder. The last is the
 * authority; the first only decides which rows are worth fetching.
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class HashSearchProvider
    implements
    IProvider
{

//  constructor

	public function __construct(
		private readonly MetadataService $metadataService,
		private readonly IRootFolder     $rootFolder,
		private readonly ReachResolver   $reach,
		private readonly IURLGenerator   $urlGenerator,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly HashIndexService $hashIndexService,
		private readonly IDateTimeFormatter $dateTimeFormatter,
	) {
	}


//  getters / setters / is* / has*

	/**
	 * The provider id, which is also the OCS route clients search through:
	 * `/ocs/v2.php/search/providers/<id>/search`. Changing it breaks them.
	 */
	#[\Override]
	public function getId(): string
	{
		return 'file_checksum_search_provider';
	}

	/**
	 * The heading the results appear under, kept short because the search
	 * modal gives it one line beside every other provider's.
	 */
	#[\Override]
	public function getName(): string
	{
		return $this->l10n->t( 'File checksums' );
	}

	/**
	 * Below the providers that answer what most searches are for — files by
	 * name, people, apps — since a hash is only ever searched deliberately.
	 * The same order everywhere: no route makes checksums more relevant.
	 */
	#[\Override]
	public function getOrder(
		string $route,
		array  $routeParameters,
	): int
	{
		return 20;
	}


//  other non-static methods

	/**
	 * Answer with the files this user can open whose hash is the term.
	 *
	 * A term that is not a hash is not an error: it is somebody searching
	 * for something else, and the answer is an empty complete result rather
	 * than a reason.
	 */
	#[\Override]
	public function search(
		IUser        $user,
		ISearchQuery $query,
	): SearchResult
	{
		$term = trim( $query->getTerm() );

		$this->logger->debug(
			'FCIAS HashSearchProvider: search called',
			[
				'app'  => Application::APP_ID,
				'user' => $user->getUID(),
			],
		);

		if ( $term === '' )
		{
			return SearchResult::complete( $this->getName(), [] );
		}

		// Parse algo:hash or raw hash
		$parsed = MetadataService::parseQueryTerm( $term );

		if ( $parsed === null )
		{
			// Not a valid hex hash
			return SearchResult::complete( $this->getName(), [] );
		}

		// queryByHash() compares against the index, which holds at most 63
		// characters, so a long-hash lookup can return a file that only
		// shares that prefix. The unified search used to trust the row and
		// show it — the API and the duplicate finder each had their own copy
		// of the confirmation, and this had none.
		// The storages this user has mounted, so the limit below is spent on
		// rows they might actually be able to open. Without it the limit is
		// applied first and the ownership check second, and five foreign
		// copies of a hash — the unified search's default limit — are enough
		// to hide somebody's own file from them. getById() below remains the
		// authority; this only decides which rows are worth fetching.
		$visibleStorageIds = $this->reach->storageIdsFor( $user->getUID() ) ?? [];

		// Capped regardless of what core hands us: each surviving row costs a
		// getById() below, and a search for a common hash — the empty file —
		// with a large limit would otherwise be a cheap way to spend
		// thousands of queries. 100 is well past what a search dropdown shows.
		$limit = min( max( 1, $query->getLimit() ), 100 );

		$rows = $this->metadataService->confirmFullHash(
			$this->metadataService->queryByHash(
				$parsed['hash'],
				$parsed['algo'],
				$limit,
				array_values( $visibleStorageIds ),
			),
			$parsed['hash'],
		);

		$userFolder = $this->rootFolder->getUserFolder( $user->getUID() );
		$entries    = [];
		// A version is opened, and offered, as its file; a trashed file is
		// offered where it was before the deletion, and opens the trash.
		$fileIds    = array_map( static fn ( array $row ): int => (int) $row[ MetadataService::FIELD_FILE_ID ], $rows );
		$versions   = $this->hashIndexService->versionsAmong( $fileIds );
		$trashed    = $this->hashIndexService->trashAmong( $fileIds, [ $user->getUID() ] );

		foreach ( $rows as $row )
		{
			$trash = $trashed[ (int) $row[ MetadataService::FIELD_FILE_ID ] ] ?? null;

			if ( $trash !== null )
			{
				$entries[] = $this->trashEntry( (int) $row[ MetadataService::FIELD_FILE_ID ], $row, $trash, $parsed['hash'] );

				continue;
			}

			$version = $versions[ (int) $row[ MetadataService::FIELD_FILE_ID ] ] ?? null;
			$fileId  = $version['fileid'] ?? (int) $row[ MetadataService::FIELD_FILE_ID ];
			$nodes   = $userFolder->getById( $fileId );

			if ( empty( $nodes ) )
			{
				continue;
			}

			$node     = $nodes[0];
			$fullPath = $userFolder->getRelativePath( $node->getPath() );

			if ( $fullPath === null )
			{
				continue;
			}

			// Read authoritative hash from oc_files_metadata.json
			$extracted = $this->metadataService->extractAlgorithm( (int) $row[ MetadataService::FIELD_FILE_ID ], $row );
			$subline   = sprintf( '%s: %s — %s', $extracted['hash'] ?? $parsed['hash'], $extracted['algo'], $fullPath );
			$title     = $node->getName();

			// Beside the name, where the search's list does not cut it off,
			// and in the account's time zone.
			if ( $version !== null )
			{
				// TRANSLATORS: the title of a search result that is an earlier version of a file: %1$s is the file's name, %2$s when the file had that content, a date and time
				$title = $this->l10n->t( '%1$s (version of %2$s)', [
					$title,
					$this->dateTimeFormatter->formatDateTime( (int) strtotime( $version['timestamp'] ), 'medium', 'medium', null, $this->l10n ),
				] );
			}

			$entries[] = new SearchResultEntry(
				thumbnailUrl: '',
				title: $title,
				subline: $subline,
				resourceUrl: $this->urlGenerator->linkToRoute( 'files.view.showFile', [
					'fileid'      => $fileId,
					'dir'         => dirname( $fullPath ),
					'opendetails' => 'true',
					'openfile'    => 'false',
					'scrollto'    => $node->getName(),
				] ),
				icon: 'icon-file',
			);
		}

		return SearchResult::complete( $this->getName(), $entries );
	}

	/**
	 * A trashed file's result: its name and, in brackets, when it was
	 * deleted, in the account's time zone; the path it had before, after
	 * {@see \OCA\FileChecksumSearch\Service\TrashOf::SCHEME}; and the trash,
	 * where it can be restored, to open.
	 *
	 * @param  array<string, mixed>                                               $row
	 * @param  array{path: string, name: string, trash: array{deletedAt: string}}  $trash
	 */
	private function trashEntry(
		int    $fileId,
		array  $row,
		array  $trash,
		string $hash,
	): SearchResultEntry
	{
		$extracted = $this->metadataService->extractAlgorithm( $fileId, $row );

		return new SearchResultEntry(
			thumbnailUrl: '',
			// TRANSLATORS: the title of a search result that is a file in the trash: %1$s is the file's name, %2$s when it was deleted, a date and time
			title: $this->l10n->t( '%1$s (deleted %2$s)', [
				$trash['name'],
				$this->dateTimeFormatter->formatDateTime( (int) strtotime( $trash['trash']['deletedAt'] ), 'medium', 'medium', null, $this->l10n ),
			] ),
			subline: sprintf( '%s: %s — %s', $extracted['hash'] ?? $hash, $extracted['algo'], $trash['path'] ),
			resourceUrl: $this->urlGenerator->linkToRoute( 'files.view.indexViewFileid', [
				'view'   => 'trashbin',
				'fileid' => $fileId,
			] ),
			icon: 'icon-delete',
		);
	}
}
