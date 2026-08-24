<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use InvalidArgumentException;
use JsonException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Allow-list permissions, one triple per permission.
 *
 * Each permission is configured the same way: an allow-all-users flag, a
 * list of group IDs, and a list of user IDs.  A user is allowed when the
 * flag is set, when they are listed by name, or when they belong to one of
 * the listed groups.
 *
 * The shape was originally implemented once inside {@see RuleService} for
 * rule editing.  It lives here so further permissions reuse it rather than
 * copying the triple again; RuleService keeps its rule-editing methods as a
 * façade over this service.
 *
 * Config keys are mapped explicitly per permission rather than derived from
 * the permission name, so the keys already written to disk keep their
 * historical names.
 */
class PermissionService
{

// constants

	/** Who may create and edit hash-generation rules. */
	public const PERMISSION_RULE_EDITING = 'rule_editing';

	/**
	 * Permission key => the three config keys backing it.
	 *
	 * @var array<string, array{allUsers: string, groups: string, users: string}>
	 */
	private const CONFIG_KEYS
		= [
			self::PERMISSION_RULE_EDITING => [
				'allUsers' => 'rule_editors_all_users',
				'groups'   => 'rule_editors_groups',
				'users'    => 'rule_editors_users',
			],
		];


	public function __construct(
		private readonly IAppConfig     $appConfig,
		private readonly ?IGroupManager $groupManager = null,
	) {
	}


	/**
	 * Whether the given user holds the permission.
	 *
	 * @throws InvalidArgumentException on an unknown permission
	 */
	public function isAllowed(
		string $permission,
		string $userId,
	): bool {

		if ( $this->isAllUsersEnabled( $permission ) )
		{
			return true;
		}

		if ( in_array( $userId, $this->getUsers( $permission ), true ) )
		{
			return true;
		}

		if ( $this->groupManager !== null )
		{
			foreach ( $this->getGroups( $permission ) as $groupId )
			{
				if ( $this->groupManager->isInGroup( $userId, $groupId ) )
				{
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Whether the given user may create and edit hash-generation rules.
	 *
	 * A named shortcut for the one permission checked at guard sites rather
	 * than in a settings form, where spelling out
	 * `isAllowed( self::PERMISSION_RULE_EDITING, $userId )` on every early
	 * return buries what the guard is actually about.
	 */
	public function canUserEditRules( string $userId ): bool
	{

		return $this->isAllowed( self::PERMISSION_RULE_EDITING, $userId );
	}


	/**
	 * Whether the permission is granted to every user.
	 *
	 * @throws InvalidArgumentException on an unknown permission
	 */
	public function isAllUsersEnabled( string $permission ): bool
	{

		return $this->appConfig->getValueBool(
			Application::APP_ID,
			$this->configKey( $permission, 'allUsers' ),
			false,
		);
	}


	/**
	 * Grant or revoke the permission for every user.
	 *
	 * @throws InvalidArgumentException on an unknown permission
	 */
	public function setAllUsersEnabled(
		string $permission,
		bool   $enabled,
	): void {

		$this->appConfig->setValueBool(
			Application::APP_ID,
			$this->configKey( $permission, 'allUsers' ),
			$enabled,
		);
	}


	/**
	 * Load the group IDs holding the permission.
	 *
	 * @return string[]
	 * @throws InvalidArgumentException on an unknown permission
	 */
	public function getGroups( string $permission ): array
	{

		return $this->loadStringList( $this->configKey( $permission, 'groups' ) );
	}


	/**
	 * Persist the group IDs holding the permission.
	 *
	 * @param  string[]  $groups
	 *
	 * @throws InvalidArgumentException on an unknown permission
	 * @throws JsonException
	 */
	public function setGroups(
		string $permission,
		array  $groups,
	): void {

		$this->saveStringList( $this->configKey( $permission, 'groups' ), $groups );
	}


	/**
	 * Load the user IDs holding the permission.
	 *
	 * @return string[]
	 * @throws InvalidArgumentException on an unknown permission
	 */
	public function getUsers( string $permission ): array
	{

		return $this->loadStringList( $this->configKey( $permission, 'users' ) );
	}


	/**
	 * Persist the user IDs holding the permission.
	 *
	 * @param  string[]  $users
	 *
	 * @throws InvalidArgumentException on an unknown permission
	 * @throws JsonException
	 */
	public function setUsers(
		string $permission,
		array  $users,
	): void {

		$this->saveStringList( $this->configKey( $permission, 'users' ), $users );
	}


	/**
	 * Resolve one of a permission's three config keys.
	 *
	 * An unknown permission is a programming error, not user input: failing
	 * loudly beats reading an empty allow-list, which would silently deny
	 * everyone, or writing to a key nothing reads back.
	 *
	 * @param  string                       $permission
	 * @param  'allUsers'|'groups'|'users'  $which
	 *
	 * @throws InvalidArgumentException on an unknown permission
	 */
	private function configKey(
		string $permission,
		string $which,
	): string {

		if ( ! isset( self::CONFIG_KEYS[ $permission ] ) )
		{
			throw new InvalidArgumentException(
				sprintf( 'Unknown permission "%s".', $permission ),
			);
		}

		return self::CONFIG_KEYS[ $permission ][ $which ];
	}


	/**
	 * Load a JSON string-list config value.
	 *
	 * @return string[]
	 */
	private function loadStringList( string $key ): array
	{

		$json = $this->appConfig->getValueString(
			Application::APP_ID,
			$key,
			'[]',
		);

		try
		{
			$list = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		}
		catch ( JsonException )
		{
			return [];
		}

		if ( ! is_array( $list ) )
		{
			return [];
		}

		return array_values(
			array_filter(
				$list,
				static fn(
					$entry,
				): bool => is_string( $entry ) && $entry !== '',
			),
		);
	}


	/**
	 * Persist a JSON string-list config value.
	 *
	 * @param  string[]  $list
	 *
	 * @throws JsonException
	 */
	private function saveStringList(
		string $key,
		array  $list,
	): void {

		$list = array_values(
			array_filter(
				$list,
				static fn(
					$entry,
				): bool => is_string( $entry ) && $entry !== '',
			),
		);

		$this->appConfig->setValueString(
			Application::APP_ID,
			$key,
			json_encode( $list, JSON_THROW_ON_ERROR ),
		);
	}

}
