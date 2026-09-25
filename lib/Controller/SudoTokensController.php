<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use InvalidArgumentException;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\SudoTokens;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Grants on app passwords: the personal page's list and the
 * administrator's tab.
 *
 * Deliberately not part of the public API — these serve two pages and may
 * change with them. Granting is behind a password confirmation, since a
 * grant is a standing authorisation with no password moment of its own;
 * listing and revoking are not, because neither widens anything.
 */
class SudoTokensController
    extends
    Controller
{

//  constructor

	public function __construct(
		string                             $appName,
		IRequest                           $request,
		private readonly IUserSession      $userSession,
		private readonly IGroupManager     $groupManager,
		private readonly PermissionService $permissions,
		private readonly SudoTokens        $sudoTokens,
	)
	{
		parent::__construct( $appName, $request );
	}


//  other non-static methods

	/**
	 * The caller's app passwords, each with its grant if it has one.
	 *
	 * Empty-handed for an account the API permission does not name: a grant
	 * would buy them nothing, so the page has nothing to offer. The listing
	 * says so in `canUseApi` rather than answering 403, so the page can
	 * explain instead of erroring.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[ApiRoute( verb: 'GET', url: '/settings/personal/sudo-tokens' )]
	public function mine(): DataResponse
	{
		$uid = $this->userSession->getUser()?->getUID();

		if ( $uid === null )
		{
			return new DataResponse( [ 'error' => 'Not authenticated.' ], Http::STATUS_UNAUTHORIZED );
		}

		if ( ! $this->mayUseApi( $uid ) )
		{
			return new DataResponse( [ 'canUseApi' => false, 'tokens' => [] ] );
		}

		$tokens = $this->sudoTokens->listForUser( $uid );

		return new DataResponse( [
			'canUseApi' => true,
			'tokens'    => $tokens,
			'available' => $this->sudoTokens->listingAvailable(),
		] );
	}


//  getters / setters / is* / has*

	/**
	 * Grant or revoke one of the caller's own app passwords. Body:
	 * `{"granted": true|false}`.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[ApiRoute( verb: 'PUT', url: '/settings/personal/sudo-tokens/{id}' )]
	public function setMine( int $id ): DataResponse
	{
		$uid = $this->userSession->getUser()?->getUID();

		if ( $uid === null )
		{
			return new DataResponse( [ 'error' => 'Not authenticated.' ], Http::STATUS_UNAUTHORIZED );
		}

		if ( ! $this->mayUseApi( $uid ) )
		{
			return new DataResponse( [ 'error' => 'This account may not use the API.' ], Http::STATUS_FORBIDDEN );
		}

		$granted = $this->request->getParam( 'granted' );

		try
		{
			if ( $granted === true || $granted === 'true' || $granted === 1 || $granted === '1' )
			{
				$this->sudoTokens->grant( $uid, $id, $uid );
			}
			else
			{
				$this->sudoTokens->revoke( $uid, $id );
			}
		}
		catch ( InvalidArgumentException $e )
		{
			return new DataResponse( [ 'error' => $e->getMessage() ], Http::STATUS_BAD_REQUEST );
		}

		$tokens = $this->sudoTokens->listForUser( $uid );

		return new DataResponse( [
			'success'   => true,
			'tokens'    => $tokens,
			'available' => $this->sudoTokens->listingAvailable(),
		] );
	}

	/**
	 * Every grant on the instance, for the administrator's tab.
	 *
	 * @noinspection PhpUnused
	 */
	#[ApiRoute( verb: 'GET', url: '/settings/sudo-tokens' )]
	public function all(): DataResponse
	{
		return new DataResponse( $this->allGrants() );
	}

	/**
	 * Revoke anybody's grant. Revoking is never a widening, so it needs no
	 * confirmation; an administrator taking a grant away should not be made
	 * to wait for a dialog.
	 *
	 * @noinspection PhpUnused
	 */
	#[ApiRoute( verb: 'DELETE', url: '/settings/sudo-tokens/{uid}/{id}' )]
	public function revoke(
		string $uid,
		int    $id,
	): DataResponse
	{
		$this->sudoTokens->revoke( $uid, $id );

		return new DataResponse( $this->allGrants() );
	}

	/**
	 * The administrator's listing, with whether it is one: `available` is
	 * false when the token table could not be read, so the page can say
	 * "unavailable" rather than "no grants".
	 *
	 * @return array{grants: list<array<string, mixed>>, available: bool}
	 */
	private function allGrants(): array
	{
		$grants = $this->sudoTokens->allGrants();

		return [
			'grants'    => $grants,
			'available' => $this->sudoTokens->listingAvailable(),
		];
	}

	/**
	 * Whether the account may use the API at all — the administrator always,
	 * as everywhere else.
	 */
	private function mayUseApi( string $uid ): bool
	{
		return $this->groupManager->isAdmin( $uid )
		       || $this->permissions->isAllowed( PermissionService::PERMISSION_API_ACCESS, $uid );
	}
}
