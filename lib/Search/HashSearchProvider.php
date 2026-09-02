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


	public function getId(): string
	{

		return 'file_checksum_search_provider';
	}


	public function getName(): string
	{

		return 'File Checksums';
	}


	public function getOrder(
		string $route,
		array  $routeParameters,
	): int {

		return 20;
	}


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

		$rows = $this->metadataService->confirmFullHash(
			$this->metadataService->queryByHash(
				$parsed['hash'],
				$parsed['algo'],
				$query->getLimit(),
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
