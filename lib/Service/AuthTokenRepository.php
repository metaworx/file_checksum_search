<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * A read-only view of core's `oc_authtoken`: which app passwords an account
 * has, by id and name, never by secret.
 *
 * Core offers no way to list an account's tokens — not in OCP, not over
 * HTTP; the Security page's list is server-rendered initial state — and
 * without a list a grant can only be bound by name, first caller wins. This
 * app already reads five core tables through the query builder, so one more
 * `SELECT` of five old columns adds no new class of dependency. The private
 * token provider would absorb a schema change for us but is `OC\`, which the
 * app-store check flags and this app has kept out of.
 *
 * The coupling is kept to this one class. Should a column go, every reader
 * gets an empty list and a logged warning, and the pages say "listing
 * unavailable" rather than the app breaking.
 */
class AuthTokenRepository
{

	public const TABLE = 'authtoken';

	/** A session opened by logging in; made and discarded by the login itself. */
	public const TYPE_BROWSER = 0;

	/** An app password: created on the Security page, long-lived, the only kind worth granting. */
	public const TYPE_APP_PASSWORD = 1;


	public function __construct(
		private readonly IDBConnection   $db,
		private readonly LoggerInterface $logger,
	) {
	}


	/**
	 * Every token of one account.
	 *
	 * @return list<array{id: int, uid: string, name: string, type: int, last_activity: int, filesystem: bool}>
	 */
	public function listForUser( string $uid ): array
	{

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'id', 'uid', 'name', 'type', 'last_activity', 'scope' )
		   ->from( self::TABLE )
		   ->where( $qb->expr()->eq( 'uid', $qb->createNamedParameter( $uid ) ) )
		   ->orderBy( 'id', 'ASC' )
		;

		return $this->rows( $qb );
	}


	/**
	 * The tokens behind a set of ids, whoever owns them — for the
	 * administrator's tab, which joins stored grants against the live table.
	 *
	 * @param  list<int>  $ids
	 *
	 * @return array<int, array{id: int, uid: string, name: string, type: int, last_activity: int, filesystem: bool}>  keyed by id
	 */
	public function byIds( array $ids ): array
	{

		if ( $ids === [] )
		{
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select( 'id', 'uid', 'name', 'type', 'last_activity', 'scope' )
		   ->from( self::TABLE )
		   ->where( $qb->expr()->in( 'id', $qb->createNamedParameter( array_values( $ids ), IQueryBuilder::PARAM_INT_ARRAY ) ) )
		;

		$byId = [];

		foreach ( $this->rows( $qb ) as $row )
		{
			$byId[ $row['id'] ] = $row;
		}

		return $byId;
	}


	/**
	 * @return list<array{id: int, uid: string, name: string, type: int, last_activity: int, filesystem: bool}>
	 */
	private function rows( IQueryBuilder $qb ): array
	{

		try
		{
			$result = $qb->executeQuery();
			$rows   = [];

			while ( ( $row = $result->fetch() ) !== false )
			{
				$rows[] = [
					'id'            => (int) $row['id'],
					'uid'           => (string) $row['uid'],
					'name'          => (string) $row['name'],
					'type'          => (int) $row['type'],
					'last_activity' => (int) $row['last_activity'],
					// The scope column is JSON; "no scope" means unrestricted,
					// which is what core's LockdownManager reads it as too.
					'filesystem'    => self::filesystemAllowed( (string) ( $row['scope'] ?? '' ) ),
				];
			}

			$result->closeCursor();

			return $rows;
		}
		catch ( Exception $e )
		{
			$this->logger->warning(
				'FCIAS AuthTokenRepository: oc_authtoken could not be read; the token listing is unavailable',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return [];
		}
	}


	/**
	 * Whether a token's stored scope lets it reach files, by core's own
	 * reading: no scope at all is unrestricted, and a scope without the
	 * `filesystem` key is too.
	 */
	public static function filesystemAllowed( string $scopeJson ): bool
	{

		if ( $scopeJson === '' )
		{
			return true;
		}

		$scope = json_decode( $scopeJson, true );

		if ( ! is_array( $scope ) || ! array_key_exists( 'filesystem', $scope ) )
		{
			return true;
		}

		return (bool) $scope['filesystem'];
	}

}
