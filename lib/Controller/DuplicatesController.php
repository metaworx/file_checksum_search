<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\FileChecksumSearch\Service\SudoScope;

/**
 * What the Duplicates page needs that the public API does not offer.
 *
 * The listings themselves are `/api/v1/duplicates` and its cross-account
 * twin — the page calls those. This controller once carried a second pair
 * of its own (`/duplicates/data`, `/duplicates/sudo`); nothing ever called
 * them, and keeping two implementations of one listing cost two bugs, each
 * a parameter wired into the twin the page does not use. They are gone.
 */
class DuplicatesController
	extends
	ApiController
{

	public function __construct(
		string                        $appName,
		IRequest                      $request,
		private readonly IUserSession $userSession,
		private readonly SudoScope    $sudo,
		private readonly IAppConfig   $appConfig,
	) {

		parent::__construct( $appName, $request );
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
