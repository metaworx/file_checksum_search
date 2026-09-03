<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use InvalidArgumentException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;

/**
 * App passwords that may use the cross-account routes without a password
 * prompt — the non-interactive half of sudo.
 *
 * A grant is a standing authorisation with no password moment, which is why
 * it is a separate thing from the interactive confirmation, is only ever
 * given to an app password (a browser session is made and discarded by a
 * login), only to one that may reach files at all, and is listed in one
 * place for the administrator. Grants live in user config, keyed by token
 * id: core deletes them with the account, `IUserConfig::getValuesByUsers()`
 * enumerates them across accounts in one call, and a token deleted on the
 * Security page can never authenticate again, so a grant left behind is
 * unreachable by construction — it is only dropped as it is listed, for
 * tidiness. Ids are never reused: `oc_authtoken.id` is auto-increment.
 *
 * Who may look across accounts at all is {@see SudoScope}'s question and is
 * asked regardless: a grant replaces the password prompt, not the
 * permission.
 */
class SudoTokens
{

	// Not a readonly class: the controllers' tests mock it, and PHPUnit
	// cannot extend a readonly class. The properties are readonly instead.
	public function __construct(
		private readonly IUserConfig         $userConfig,
		private readonly AuthTokenRepository $tokens,
		private readonly ITimeFactory        $time,
	) {
	}


	/**
	 * This account's grants, as stored.
	 *
	 * @return array<int, array{granted_by: string, granted_at: int}>  keyed by token id
	 */
	public function grantsFor( string $uid ): array
	{

		$stored = $this->userConfig->getValueArray( $uid, Application::APP_ID, ConfigLexicon::USER_SUDO_TOKENS );
		$grants = [];

		foreach ( $stored as $id => $grant )
		{
			if ( ! is_array( $grant ) )
			{
				continue;
			}

			$grants[ (int) $id ] = [
				'granted_by' => (string) ( $grant['granted_by'] ?? '' ),
				'granted_at' => (int) ( $grant['granted_at'] ?? 0 ),
			];
		}

		return $grants;
	}


	/**
	 * Whether the token behind a request carries a grant.
	 */
	public function isGranted(
		string $uid,
		int    $tokenId,
	): bool {

		return array_key_exists( $tokenId, $this->grantsFor( $uid ) );
	}


	/**
	 * This account's app passwords, each with its grant if it has one.
	 *
	 * Browser sessions are left out: nobody should be offered a grant on a
	 * token their next login discards. A stored grant whose token is gone
	 * is dropped here, and the store is tidied to match.
	 *
	 * @return list<array{id: int, name: string, last_activity: int, filesystem: bool, granted: bool, granted_by: string, granted_at: int}>
	 */
	public function listForUser( string $uid ): array
	{

		$grants = $this->grantsFor( $uid );
		$rows   = [];
		$seen   = [];

		foreach ( $this->tokens->listForUser( $uid ) as $token )
		{
			if ( $token['type'] !== AuthTokenRepository::TYPE_APP_PASSWORD )
			{
				continue;
			}

			$seen[] = $token['id'];
			$grant  = $grants[ $token['id'] ] ?? null;

			$rows[] = [
				'id'            => $token['id'],
				'name'          => $token['name'],
				'last_activity' => $token['last_activity'],
				'filesystem'    => $token['filesystem'],
				'granted'       => $grant !== null,
				'granted_by'    => $grant['granted_by'] ?? '',
				'granted_at'    => $grant['granted_at'] ?? 0,
			];
		}

		$dangling = array_diff( array_keys( $grants ), $seen );

		if ( $dangling !== [] && $this->tokens->listForUser( $uid ) !== [] )
		{
			// Only when the table answered: an unreadable table would
			// otherwise look like "every token is gone" and wipe the grants.
			$this->store( $uid, array_diff_key( $grants, array_flip( $dangling ) ) );
		}

		return $rows;
	}


	/**
	 * Grant one of $uid's app passwords.
	 *
	 * @throws InvalidArgumentException  Not $uid's token, not an app
	 *                                   password, or kept out of the
	 *                                   filesystem — each is a reason the
	 *                                   caller should hear, not a silent no.
	 */
	public function grant(
		string $uid,
		int    $tokenId,
		string $grantedBy,
	): void {

		$token = null;

		foreach ( $this->tokens->listForUser( $uid ) as $candidate )
		{
			if ( $candidate['id'] === $tokenId )
			{
				$token = $candidate;
				break;
			}
		}

		if ( $token === null )
		{
			throw new InvalidArgumentException( 'No such app password on this account.' );
		}

		if ( $token['type'] !== AuthTokenRepository::TYPE_APP_PASSWORD )
		{
			throw new InvalidArgumentException( 'Only an app password can be granted; a browser session is discarded by the next login.' );
		}

		if ( ! $token['filesystem'] )
		{
			throw new InvalidArgumentException( 'This app password is kept out of the filesystem, so it cannot be granted file reads.' );
		}

		$grants              = $this->grantsFor( $uid );
		$grants[ $tokenId ] = [
			'granted_by' => $grantedBy,
			'granted_at' => $this->time->getTime(),
		];

		$this->store( $uid, $grants );
	}


	/**
	 * Revoke a grant. Revoking one that does not exist is not an error: the
	 * outcome asked for is the outcome.
	 */
	public function revoke(
		string $uid,
		int    $tokenId,
	): void {

		$grants = $this->grantsFor( $uid );

		if ( ! array_key_exists( $tokenId, $grants ) )
		{
			return;
		}

		unset( $grants[ $tokenId ] );
		$this->store( $uid, $grants );
	}


	/**
	 * Every grant on the instance, joined against the live token table — the
	 * administrator's tab. A grant whose token is gone is reported as such
	 * rather than hidden, so the tab is also where leftovers become visible.
	 *
	 * @return list<array{uid: string, id: int, name: string, last_activity: int, exists: bool, granted_by: string, granted_at: int}>
	 */
	public function allGrants(): array
	{

		$byUser = $this->userConfig->getValuesByUsers( Application::APP_ID, ConfigLexicon::USER_SUDO_TOKENS );
		$ids    = [];
		$rows   = [];

		foreach ( $byUser as $grants )
		{
			if ( ! is_array( $grants ) )
			{
				continue;
			}

			foreach ( array_keys( $grants ) as $id )
			{
				$ids[] = (int) $id;
			}
		}

		$live = $this->tokens->byIds( $ids );

		foreach ( $byUser as $uid => $grants )
		{
			if ( ! is_array( $grants ) )
			{
				continue;
			}

			foreach ( $grants as $id => $grant )
			{
				$id    = (int) $id;
				$token = $live[ $id ] ?? null;

				$rows[] = [
					'uid'           => (string) $uid,
					'id'            => $id,
					'name'          => $token['name'] ?? '',
					'last_activity' => $token['last_activity'] ?? 0,
					'exists'        => $token !== null,
					'granted_by'    => (string) ( $grant['granted_by'] ?? '' ),
					'granted_at'    => (int) ( $grant['granted_at'] ?? 0 ),
				];
			}
		}

		return $rows;
	}


	/**
	 * @param  array<int, array{granted_by: string, granted_at: int}>  $grants
	 */
	private function store(
		string $uid,
		array  $grants,
	): void {

		if ( $grants === [] )
		{
			$this->userConfig->deleteUserConfig( $uid, Application::APP_ID, ConfigLexicon::USER_SUDO_TOKENS );

			return;
		}

		$this->userConfig->setValueArray( $uid, Application::APP_ID, ConfigLexicon::USER_SUDO_TOKENS, $grants );
	}

}
