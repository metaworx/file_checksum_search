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
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCA\FileChecksumSearch\Service\SudoScope;
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
		private readonly SudoScope        $sudo,
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

		// Another account's duplicates are a different route, behind a
		// password confirmation: {@see findAllSudo()}. This one is always
		// the caller's own, and says so rather than silently answering that.
		if ( $user !== null && $currentUser !== null )
		{
			return new DataResponse(
				[ 'error' => 'Use /duplicates/sudo to list another account\'s duplicates.' ],
				Http::STATUS_FORBIDDEN,
			);
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

	/**
	 * {@see findAll()} for one named account, or for every account when
	 * `user` is omitted — the Duplicates page's *Show all users* switch. A
	 * password confirmation, and only for those who may look across accounts;
	 * a sub-admin may name a member of their groups and nothing wider.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PasswordConfirmationRequired]
	#[ApiRoute( verb: 'GET', url: '/duplicates/sudo' )]
	public function findAllSudo(
		?string $user = null,
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
	): DataResponse {

		$currentUser = $this->userSession->getUser();

		if ( $currentUser === null )
		{
			return new DataResponse( [ 'error' => 'Not authenticated.' ], Http::STATUS_UNAUTHORIZED );
		}

		$scope = $this->sudo->resolve( $currentUser->getUID(), $user );

		if ( $scope === false )
		{
			return new DataResponse( [ 'error' => 'Not yours to look at.' ], Http::STATUS_FORBIDDEN );
		}

		$limit = max( 1, min( $limit, 500 ) );

		return new DataResponse(
			$this->hashIndexService->listDuplicatesForUser( $scope, $algo, $minCount, $limit, $offset ),
		);
	}

}
