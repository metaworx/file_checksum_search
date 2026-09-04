<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\Files\Config\IUserMountCache;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUserManager;
use Throwable;

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
 * Asked about an account, that is {@see resolve()} or {@see resolveSet()}.
 * Asked about one file, it is {@see mayReachFile()} — a separate question
 * because a per-file route holds a file id and not an account, and putting
 * it to `resolve()` as "everyone" refuses a sub-admin for files their own
 * listing shows them.
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
		private readonly IUserMountCache   $mountCache,
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


	/**
	 * The accounts $uid may read when naming several of them at once.
	 *
	 * Groups are expanded here and never by the client: membership is not
	 * the caller's to enumerate, and a sub-admin's own picker is built from
	 * {@see selectableFor()} which already answers only what they may see.
	 *
	 * Refuses the whole request when any named target is out of reach rather
	 * than quietly dropping it — a listing that silently answers for fewer
	 * accounts than were asked for is worse than one that says no.
	 *
	 * @param  list<string>  $uids      Accounts named directly.
	 * @param  list<string>  $groupIds  Groups whose members are meant.
	 *
	 * @return list<string>|false  The accounts to read, deduplicated, or
	 *                             false when $uid may not have one of them.
	 */
	public function resolveSet(
		string $uid,
		array  $uids,
		array  $groupIds,
	): array|false {

		$isSudoer = $this->isSudoer( $uid );
		$leader   = $this->userManager->get( $uid );

		if ( ! $isSudoer && $leader === null )
		{
			return false;
		}

		$allowed = [];

		foreach ( $groupIds as $groupId )
		{
			$group = $this->groupManager->get( (string) $groupId );

			if ( $group === null )
			{
				return false;
			}

			// A sub-admin may name only a group they administer; every member
			// of such a group is accessible to them by definition, so the
			// per-member check below is not repeated here.
			if ( ! $isSudoer && ( $leader === null || ! $this->subAdmin->isSubAdminOfGroup( $leader, $group ) ) )
			{
				return false;
			}

			foreach ( $group->getUsers() as $member )
			{
				$allowed[] = $member->getUID();
			}
		}

		foreach ( $uids as $target )
		{
			$target = (string) $target;
			$member = $this->userManager->get( $target );

			if ( $member === null )
			{
				return false;
			}

			if ( ! $isSudoer && ( $leader === null || ! $this->subAdmin->isUserAccessible( $leader, $member ) ) )
			{
				return false;
			}

			$allowed[] = $target;
		}

		return array_values( array_unique( $allowed ) );
	}


	/**
	 * Whether $uid may act on one file that need not be their own.
	 *
	 * The per-file twin of {@see resolve()}, and it exists because the
	 * per-file routes cannot ask that one: `resolve()` wants an account, and
	 * a file id is what they hold. Asking it anyway — with null, meaning
	 * "everyone" — is why they refuse a sub-admin outright today, even for a
	 * file the listing beside them is happy to show.
	 *
	 * A sudoer reaches anything. Anyone else reaches a file when some account
	 * holding it is accessible to them, which core answers for us and answers
	 * generously in the two places that matter: one's own file is always
	 * accessible, and an administrator's never is.
	 *
	 * Mounts, not ownership. A file reachable by an account a sub-admin
	 * administers is a file already in the listing they are looking at, so
	 * ownership would refuse rows the page shows. It also costs nothing
	 * extra: a share and a group folder are mounts like any other.
	 */
	public function mayReachFile(
		string $uid,
		int    $fileId,
	): bool {

		if ( $this->isSudoer( $uid ) )
		{
			return true;
		}

		$leader = $this->userManager->get( $uid );

		if ( $leader === null )
		{
			return false;
		}

		try
		{
			$mounts = $this->mountCache->getMountsForFileId( $fileId );
		}
		catch ( Throwable )
		{
			// A file id nothing knows about reaches nobody. Refusing is the
			// safe answer and the honest one — the caller cannot act on a
			// file the mount cache cannot place.
			return false;
		}

		foreach ( $mounts as $mount )
		{
			if ( $this->subAdmin->isUserAccessible( $leader, $mount->getUser() ) )
			{
				return true;
			}
		}

		return false;
	}


	/**
	 * The groups and accounts $uid may name, for the picker.
	 *
	 * A sudoer may name anyone, so the instance is searched; a sub-admin may
	 * name only the groups they administer and those groups' members. Anyone
	 * else may name nothing and is refused — the picker is not offered to
	 * them at all.
	 *
	 * `prefill` says whether the lists are complete. One more than the
	 * threshold is fetched: if that extra row exists there are more than the
	 * client should hold at once, so it must ask as the user types instead.
	 * This costs no count query.
	 *
	 * @param  string|null  $search     What the user has typed, or null for
	 *                                  the opening list.
	 * @param  int          $threshold  How many of each kind may be prefilled.
	 *
	 * @return array{prefill: bool, groups: list<array{id: string, label: string}>, users: list<array{id: string, label: string}>}|false
	 */
	public function selectableFor(
		string  $uid,
		?string $search,
		int     $threshold,
	): array|false {

		$needle   = trim( (string) $search );
		$probe    = $threshold + 1;
		$isSudoer = $this->isSudoer( $uid );
		$leader   = $this->userManager->get( $uid );

		if ( $isSudoer )
		{
			$groups = $this->groupManager->search( $needle, $probe );
			$users  = $this->userManager->searchDisplayName( $needle, $probe );
		}
		else
		{
			if ( $leader === null )
			{
				return false;
			}

			$groups = $this->subAdmin->getSubAdminsGroups( $leader );

			if ( $groups === [] )
			{
				// Not a sudoer and not a sub-admin: nothing to name.
				return false;
			}

			$groups = array_values( array_filter(
				$groups,
				static fn ( $group ): bool => $needle === ''
				                              || stripos( $group->getGID(), $needle ) !== false
				                              || stripos( $group->getDisplayName(), $needle ) !== false,
			) );

			$users = [];

			foreach ( $groups as $group )
			{
				foreach ( $group->getUsers() as $member )
				{
					if ( $needle === ''
					     || stripos( $member->getUID(), $needle ) !== false
					     || stripos( $member->getDisplayName(), $needle ) !== false )
					{
						$users[ $member->getUID() ] = $member;
					}
				}
			}

			$users = array_values( $users );
		}

		$prefill = count( $groups ) <= $threshold && count( $users ) <= $threshold;

		return [
			'prefill' => $prefill,
			'groups'  => array_map(
				static fn ( $group ): array => [
					'id'    => $group->getGID(),
					'label' => $group->getDisplayName(),
				],
				array_slice( array_values( $groups ), 0, $threshold ),
			),
			'users'   => array_map(
				static fn ( $user ): array => [
					'id'    => $user->getUID(),
					'label' => $user->getDisplayName(),
				],
				array_slice( array_values( $users ), 0, $threshold ),
			),
		];
	}

}
