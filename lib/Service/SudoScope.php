<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Who may look across accounts, and how far.
 *
 * Three answers, tried in this order. A *sudoer* — a member of `admin`, or
 * anyone the `instance_view` permission names — may look at anyone, and at
 * everyone at once. A *sub-admin* may look at the members of the groups
 * they administer, one at a time, which is core's own delegation model
 * ({@see ISubAdmin::isUserAccessible()}) and needs no configuration of ours.
 * Everyone else is refused.
 *
 * This decides only *who may be asked*. The asking — a password
 * confirmation on the interactive routes, a granted app password on the
 * others — is the caller's business, and happens before this is consulted.
 */
class SudoScope
{

	public function __construct(
		private readonly IGroupManager     $groupManager,
		private readonly ISubAdmin         $subAdmin,
		private readonly IUserManager      $userManager,
		private readonly PermissionService $permissions,
	) {
	}


	/**
	 * Whether $uid may look across accounts at all.
	 */
	public function isSudoer( string $uid ): bool
	{

		return $this->groupManager->isAdmin( $uid )
		       || $this->permissions->isAllowed( PermissionService::PERMISSION_INSTANCE_VIEW, $uid );
	}


	/**
	 * The scope $uid may read when asking about $target.
	 *
	 * @param  string|null  $target  An account to look at, or null for the
	 *                               whole instance.
	 *
	 * @return string|null|false  The scope to pass down — the target's uid,
	 *                            or null for everyone — or false when $uid
	 *                            may not have it: a non-sudoer asking for
	 *                            everyone, a sub-admin asking outside their
	 *                            groups, or a target that does not exist.
	 */
	public function resolve(
		string  $uid,
		?string $target,
	): string|null|false {

		if ( $this->isSudoer( $uid ) )
		{
			return $target;
		}

		if ( $target === null )
		{
			return false;
		}

		$leader = $this->userManager->get( $uid );
		$member = $this->userManager->get( $target );

		if ( $leader === null || $member === null )
		{
			return false;
		}

		return $this->subAdmin->isUserAccessible( $leader, $member )
			? $target
			: false;
	}

}
