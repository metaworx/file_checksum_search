<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCA\FileChecksumSearch\Service\DuplicateService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCA\FileChecksumSearch\Service\SudoConfirmation;
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
		private readonly SudoConfirmation $confirmation,
		private readonly IAppConfig       $appConfig,
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
	#[UserRateLimit( limit: 60, period: 60 )]
	#[ApiRoute( verb: 'GET', url: '/duplicates/data' )]
	public function findAll(
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
		?string $user = null,
		?string $hash = null,
		bool    $anywhere = false,
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
			$this->hashIndexService->listDuplicatesForUser( $uid, $algo, $minCount, $limit, $offset, $hash, $anywhere ),
		);
	}

	/**
	 * {@see findAll()} for one named account, or for every account when
	 * `user` is omitted — the Duplicates page's *Show all users* switch. Only
	 * for those who may look across accounts, and only once confirmed
	 * ({@see SudoConfirmation}); a sub-admin may name a member of their
	 * groups and nothing wider.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit( limit: 60, period: 60 )]
	#[ApiRoute( verb: 'GET', url: '/duplicates/sudo' )]
	public function findAllSudo(
		?string $user = null,
		?string $algo = null,
		int     $minCount = 2,
		int     $limit = DuplicateService::DEFAULT_DUPLICATE_LIMIT,
		int     $offset = 0,
		?array  $users = null,
		?array  $groups = null,
		?string $hash = null,
		bool    $anywhere = false,
	): DataResponse {

		$currentUser = $this->userSession->getUser();

		if ( $currentUser === null )
		{
			return new DataResponse( [ 'error' => 'Not authenticated.' ], Http::STATUS_UNAUTHORIZED );
		}

		// Two shapes on one route: `user=` names one account (or is absent
		// for every account, the sudoer's view), and `users[]`/`groups[]`
		// name a set — the picker's shape. Groups are expanded and every
		// target authorised in SudoScope, never here and never in the client.
		if ( $users !== null || $groups !== null )
		{
			$scope = $this->sudo->resolveSet(
				$currentUser->getUID(),
				array_values( array_filter( (array) $users, 'is_string' ) ),
				array_values( array_filter( (array) $groups, 'is_string' ) ),
			);
		}
		else
		{
			$scope = $this->sudo->resolve( $currentUser->getUID(), $user );
		}

		if ( $scope === false )
		{
			return new DataResponse( [ 'error' => 'Not yours to look at.' ], Http::STATUS_FORBIDDEN );
		}

		// Permission first, confirmation second, as on the API's twins: the
		// message is core's own, so the page's dialog recognises it.
		if ( ! $this->confirmation->isConfirmed( $currentUser->getUID() ) )
		{
			return new DataResponse( [ 'message' => 'Password confirmation required' ], Http::STATUS_FORBIDDEN );
		}

		$limit = max( 1, min( $limit, 500 ) );

		return new DataResponse(
			$this->hashIndexService->listDuplicatesForUser( $scope, $algo, $minCount, $limit, $offset, $hash, $anywhere ),
		);
	}


	/**
	 * The groups and accounts the caller may name in the cross-account
	 * picker, and whether the lists are complete.
	 *
	 * `prefill` false means there are more than the picker holds at once, so
	 * it must come back with `?search=` as the user types. Refused for an
	 * account that may name nobody — the picker is not offered to them.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit( limit: 60, period: 60 )]
	#[ApiRoute( verb: 'GET', url: '/duplicates/selectable' )]
	public function selectable( ?string $search = null ): DataResponse
	{

		$currentUser = $this->userSession->getUser();

		if ( $currentUser === null )
		{
			return new DataResponse( [ 'error' => 'Not authenticated.' ], Http::STATUS_UNAUTHORIZED );
		}

		$offer = $this->sudo->selectableFor(
			$currentUser->getUID(),
			$search,
			$this->appConfig->getValueInt(
				Application::APP_ID,
				ConfigLexicon::CROSS_ACCOUNT_PREFILL_LIMIT,
			),
		);

		if ( $offer === false )
		{
			return new DataResponse( [ 'error' => 'Not yours to look at.' ], Http::STATUS_FORBIDDEN );
		}

		// Whether the caller may also ask for every account at once — the
		// picker offers "All accounts" only to a sudoer.
		$offer['all'] = $this->sudo->isSudoer( $currentUser->getUID() );

		return new DataResponse( $offer );
	}

}
