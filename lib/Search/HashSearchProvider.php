<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Search;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
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
		private readonly HashIndexService $hashIndexService,
		private readonly IRootFolder      $rootFolder,
		private readonly IURLGenerator    $urlGenerator,
		private readonly LoggerInterface  $logger,
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
		$algo = null;

		if ( preg_match( '/^([a-z0-9]+):([a-f0-9]{32,64})$/i', $term, $matches ) )
		{
			$algo = strtolower( $matches[1] );
			$hash = strtolower( $matches[2] );
		}
		elseif ( ! preg_match( '/^[a-f0-9]{32,64}$/i', $term ) )
		{
			// Not a valid hex hash
			return SearchResult::complete( $this->getName(), [] );
		}
		else
		{
			$hash = strtolower( $term );
		}

		$rows = $this->hashIndexService->findByHash( $hash, $algo, $query->getLimit() );

		$userFolder = $this->rootFolder->getUserFolder( $user->getUID() );
		$entries    = [];

		foreach ( $rows as $row )
		{
			$fileId = (int) $row['fileid'];
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

			$entries[] = new SearchResultEntry(
				thumbnailUrl: '',
				title: $row['name'],
				subline: sprintf( '%s: %s — %s', $row['algo'], $row['hash_value'], $fullPath ),
				resourceUrl: $this->urlGenerator->linkToRoute( 'files.view.index', [
					'dir'      => dirname( $fullPath ),
					'scrollto' => $row['name'],
				] ),
				icon: 'icon-file',
			);
		}

		return SearchResult::complete( $this->getName(), $entries );
	}

}
