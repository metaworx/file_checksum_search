<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\ISession;
use Throwable;

/**
 * Whether a cross-account read may go ahead without asking again.
 *
 * Two ways to be confirmed. Interactively: the session confirmed its
 * password within the last thirty minutes, which core records as
 * `last-password-confirm` when its own dialog succeeds — the same window
 * core's middleware uses, read from the same key. Non-interactively: the
 * request authenticated with an app password that carries a grant
 * ({@see SudoTokens}). A grant replaces the prompt, not the permission,
 * which {@see SudoScope} applies before this is asked.
 *
 * This exists instead of core's `#[PasswordConfirmationRequired]` because
 * that middleware refuses an app-password session outright — it has no
 * confirmation, and its strict mode checks the account password — so a
 * granted token could never pass a route carrying the attribute. The two
 * session keys are core-private names in a public store: the same class of
 * coupling as reading `oc_authtoken`, kept to this one class.
 */
class SudoConfirmation
{

	/** Core's window, `PasswordConfirmationMiddleware`: thirty minutes. */
	public const WINDOW = 30 * 60;


	public function __construct(
		private readonly ISession       $session,
		private readonly ITokenProvider $tokenProvider,
		private readonly SudoTokens     $sudoTokens,
		private readonly ITimeFactory   $time,
	) {
	}


	public function isConfirmed( string $uid ): bool
	{

		$confirmedAt = (int) $this->session->get( 'last-password-confirm' );

		if ( $confirmedAt > $this->time->getTime() - self::WINDOW )
		{
			return true;
		}

		// For a request that authenticated with an app password, core keeps
		// the password itself in the session under this key and creates no
		// session token for it — so the session id is not a token here, and
		// the app password is the only handle on the one that was used.
		$appPassword = $this->session->get( 'app_password' );

		if ( ! is_string( $appPassword ) || $appPassword === '' )
		{
			return false;
		}

		try
		{
			$tokenId = $this->tokenProvider->getToken( $appPassword )->getId();
		}
		catch ( Throwable )
		{
			// None core can still find: nothing to hold a grant.
			return false;
		}

		return $this->sudoTokens->isGranted( $uid, $tokenId );
	}

}
