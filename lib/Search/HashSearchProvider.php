<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Search;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\IRootFolder;
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

	public function __construct(
		private readonly MetadataService $metadataService,
		private readonly IRootFolder     $rootFolder,
		private readonly IUserMountCache $userMountCache,
		private readonly IURLGenerator   $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}


	/**
	 * The provider id, which is also the OCS route clients search through:
	 * `/ocs/v2.php/search/providers/<id>/search`. Changing it breaks them.
	 */
	public function getId(): string
	{

		return 'file_checksum_search_provider';
	}


	/**
	 * The heading the results appear under, kept short because the search
	 * modal gives it one line beside every other provider's.
	 */
	public function getName(): string
	{

		return 'File Checksums';
	}


	/**
	 * Below the providers that answer what most searches are for — files by
	 * name, people, apps — since a hash is only ever searched deliberately.
	 * The same order everywhere: no route makes checksums more relevant.
	 */
	public function getOrder(
		string $route,
		array  $routeParameters,
	): int {

		return 20;
	}


	/**
	 * Answer with the files this user can open whose hash is the term.
	 *
	 * A term that is not a hash is not an error: it is somebody searching
	 * for something else, and the answer is an empty complete result rather
	 * than a reason.
	 */
	public function search(
		IUser        $user,
		ISearchQuery $query,
	): SearchResult {

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
		$visibleStorageIds = array_map(
			static fn ( $mount ) => $mount->getStorageId(),
			$this->userMountCache->getMountsForUser( $user ),
		);

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

		foreach ( $rows as $row )
		{
			$fileId = (int) $row[ MetadataService::FIELD_FILE_ID ];
			$nodes  = $userFolder->getById( $fileId );

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
			$extracted = $this->metadataService->extractAlgorithm( $fileId, $row );

			$entries[] = new SearchResultEntry(
				thumbnailUrl: '',
				title: $node->getName(),
				subline: sprintf( '%s: %s — %s', $extracted['hash'] ?? $parsed['hash'], $extracted['algo'], $fullPath ),
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

}
