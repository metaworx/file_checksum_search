<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class DuplicatesController
	extends
	ApiController
{

	public function __construct(
		string                            $appName,
		IRequest                          $request,
		private readonly HashIndexService $hashIndexService,
		private readonly IUserSession     $userSession,
		private readonly IGroupManager    $groupManager,
		private readonly IUserManager     $userManager,
		private readonly LoggerInterface  $logger,
	) {

		parent::__construct( $appName, $request );
	}


	/**
	 * Find all duplicate hash groups for the current user (or a
	 * specified user if the requester is an admin).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function findAll(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = 50,
		int     $offset = 0,
		?string $user = null,
	): DataResponse {

		$this->logger->debug(
			'FCIAS DuplicatesController: findAll called',
			[
				'app'      => Application::APP_ID,
				'algo'     => $algo,
				'minCount' => $minCount,
				'limit'    => $limit,
				'offset'   => $offset,
			],
		);

		$limit = max( 1, min( $limit, 500 ) );

		$currentUser = $this->userSession->getUser();

		// Resolve target user
		if ( $user !== null && $currentUser !== null )
		{
			if ( ! $this->groupManager->isAdmin( $currentUser->getUID() ) )
			{
				return new DataResponse(
					[ 'error' => 'Only admins can query other users.' ],
					Http::STATUS_FORBIDDEN,
				);
			}

			$target = $this->userManager->get( $user );

			if ( $target === null )
			{
				return new DataResponse(
					[ 'error' => 'User not found.' ],
					Http::STATUS_NOT_FOUND,
				);
			}

			$uid = $target->getUID();
		}
		elseif ( $currentUser !== null )
		{
			$uid = $currentUser->getUID();
		}
		else
		{
			return new DataResponse(
				[
					'duplicates'   => [],
					'total_groups' => 0,
				],
			);
		}

		// Fetch all groups (limit=0 → LIMIT 0, so use large number)
		$groups = $this->hashIndexService->findAllDuplicates(
			$algo,
			$minCount,
			10000,
			$offset,
		);

		if ( empty( $groups ) )
		{
			return new DataResponse(
				[
					'duplicates'   => [],
					'total_groups' => 0,
					'pagination'   => [
						'offset' => $offset,
						'limit'  => $limit,
					],
				],
			);
		}

		// Collect all file IDs
		$allFileIds = [];

		foreach ( $groups as $group )
		{
			foreach ( $group['fileids'] as $fileId )
			{
				$allFileIds[] = $fileId;
			}
		}

		// Batch-lookup filecache paths filtered by user
		$fcPaths = $this->hashIndexService->batchLookupFilecachePaths( $allFileIds, $uid );

		$result = [];

		foreach ( $groups as $group )
		{
			$files = [];

			foreach ( $group['fileids'] as $fileId )
			{
				if ( isset( $fcPaths[ $fileId ] ) )
				{
					$files[] = [
						'fileid' => $fileId,
						'path'   => $fcPaths[ $fileId ]['path'],
						'name'   => $fcPaths[ $fileId ]['name'],
					];
				}
			}

			if ( count( $files ) < $minCount )
			{
				continue;
			}

			$result[] = [
				'algo'       => $group['algo'],
				'hash_value' => $group['hash_value'],
				'file_count' => count( $files ),
				'files'      => $files,
			];
		}

		// Apply user-specified limit after filtering
		if ( count( $result ) > $limit )
		{
			$result = array_slice( $result, 0, $limit );
		}

		return new DataResponse(
			[
				'duplicates'   => $result,
				'total_groups' => count( $result ),
				'pagination'   => [
					'offset' => $offset,
					'limit'  => $limit,
				],
			],
		);
	}


	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{

		return new TemplateResponse(
			'file_checksum_search',
			'duplicates',
			[],
			TemplateResponse::RENDER_AS_USER,
		);
	}

}
