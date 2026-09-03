<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\DuplicateService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The Duplicates page's endpoint.
 *
 * Duplicates are answered for one account at a time, because a hash is a
 * fingerprint of content and a list spanning accounts would say what other
 * people hold. An administrator may name another account; anyone else gets
 * their own.
 */
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
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/duplicates/data' )]
	public function findAll(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
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

		return new DataResponse(
			$this->hashIndexService->listDuplicatesForUser( $uid, $algo, $minCount, $limit, $offset ),
		);
	}

}
