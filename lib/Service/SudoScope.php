<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\Group\ISubAdmin;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
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
 * Asked what a caller may reach at most, that is {@see resolve()}; asked
 * about named accounts and groups, {@see resolveSet()}.
 * Asked about one file, it is {@see mayReachFile()} — a separate question,
 * because a per-file route holds a file id and not an account. It is
 * answered with the very predicate the listing filters by, from the one
 * {@see ReachResolver}, so what a caller is shown and what they may act on
 * cannot disagree.
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
		private readonly ReachResolver     $reach,
	) {
	}


	/**
	 * Whether $uid may look at *everyone*: a member of `admin`, or an account
	 * the instance_view permission names. This is the ceiling question, not
	 * the entry question — for that, {@see mayCross()}.
	 */
	public function isSudoer( string $uid ): bool
	{

		return $this->groupManager->isAdmin( $uid )
		       || $this->permissions->isAllowed( PermissionService::PERMISSION_INSTANCE_VIEW, $uid );
	}


	/**
	 * Whether $uid may cross into other accounts' files at all — a sudoer,
	 * or a sub-admin of any group.
	 *
	 * The one predicate for "offer the cross-account view". The tab used to
	 * be offered on {@see isSudoer()}, which asks a stricter question — may
	 * they see *everyone* — and so hid the view from the very group leaders
	 * the picker was built for. How far they may see once inside is
	 * {@see resolve()}'s and {@see resolveSet()}'s business.
	 */
	public function mayCross( string $uid ): bool
	{

		if ( $this->isSudoer( $uid ) )
		{
			return true;
		}

		$user = $this->userManager->get( $uid );

		return $user !== null && $this->subAdmin->isSubAdmin( $user );
	}


	/**
	 * The most $uid may reach with nothing named — their *ceiling*.
	 *
	 * Null for a sudoer: everyone. For a sub-admin, every member of every
	 * group they lead — not "everyone", which no sub-admin may have, and
	 * reading it as that is what once refused group leaders from the lookup
	 * and the bare listing while the picker admitted them. False for anyone
	 * else. Naming particular accounts is {@see resolveSet()}; a single
	 * account is a set of one, and the separate one-account answer this
	 * method used to give is gone with the `user=` parameter that asked it.
	 *
	 * @return list<string>|null|false
	 */
	public function resolve( string $uid ): array|null|false
	{

		if ( $this->isSudoer( $uid ) )
		{
			return null;
		}

		$leader = $this->userManager->get( $uid );

		if ( $leader === null )
		{
			return false;
		}

		return $this->membersOfLedGroups( $leader );
	}


	/**
	 * Every member of every group $leader administers, or false if they
	 * administer none — a plain account has no ceiling to speak of.
	 *
	 * @return list<string>|false
	 */
	private function membersOfLedGroups( IUser $leader ): array|false
	{

		$groups = $this->subAdmin->getSubAdminsGroups( $leader );

		if ( $groups === [] )
		{
			return false;
		}

		$members = [];

		foreach ( $groups as $group )
		{
			foreach ( $this->accessibleMembers( $leader, $group ) as $member )
			{
				$members[] = $member->getUID();
			}
		}

		return array_values( array_unique( $members ) );
	}


	/**
	 * The members of $group that $leader may reach.
	 *
	 * Every member but the administrators and delegated administrators
	 * among them: core's own rule for a sub-admin
	 * ({@see ISubAdmin::isUserAccessible()}), and the one this class already
	 * applies to an account named directly. A group is not a shortcut
	 * around it — a leader whose group holds an administrator reached that
	 * administrator's whole home through `all` and `groups[]` for as long
	 * as this filter was missing, while naming them was refused.
	 *
	 * One core query per member; a led group is small, and the callers
	 * here run once per request.
	 *
	 * @return list<IUser>
	 */
	private function accessibleMembers( IUser $leader, IGroup $group ): array
	{

		return array_values( array_filter(
			$group->getUsers(),
			fn ( IUser $member ): bool => $this->subAdmin->isUserAccessible( $leader, $member ),
		) );
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

		// Whether they may cross at all is asked first, as on every other
		// path: a set that names nobody used to reach this point for a plain
		// account and answer an empty listing, where the same account gets
		// "Not yours to look at." for anything else — and was asked for a
		// password on the way.
		if ( ! $isSudoer && ( $leader === null || $this->subAdmin->getSubAdminsGroups( $leader ) === [] ) )
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

			// A sub-admin may name only a group they administer, and of it
			// only the members core lets them reach — the administrators in
			// it are not theirs, named directly or through the group.
			if ( ! $isSudoer && ( $leader === null || ! $this->subAdmin->isSubAdminOfGroup( $leader, $group ) ) )
			{
				return false;
			}

			$members = $isSudoer
				? $group->getUsers()
				: $this->accessibleMembers( $leader, $group );

			foreach ( $members as $member )
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
	 * a file id is what they hold.
	 *
	 * A sudoer reaches anything. Anyone else reaches a file that lies within
	 * one of the mounts of their ceiling — the accounts {@see resolve()}
	 * gives them for no target — and nothing beside it.
	 *
	 * **The same predicate the listing uses**, from the same resolver: the
	 * listing filters `oc_filecache` to those mounts, and this asks whether
	 * one file would pass that filter. So what a group leader is shown and
	 * what they may act on agree by construction. An earlier version of this
	 * method accepted any file whose *storage* an accessible account had
	 * mounted, which admitted a sharer's whole storage on the strength of one
	 * shared folder; that was withdrawn, and a mount is a storage *and a
	 * root* now ({@see ReachResolver}).
	 */
	public function mayReachFile(
		string $uid,
		int    $fileId,
	): bool {

		if ( $this->isSudoer( $uid ) )
		{
			return true;
		}

		$ceiling = $this->resolve( $uid );

		if ( $ceiling === false )
		{
			return false;
		}

		return $this->reach->contains( $this->reach->mountsFor( $ceiling ), $fileId );
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

			// Members come from every group they lead, and the term is then
			// applied to groups and to members separately. Filtering the
			// groups first and collecting members from what survived meant a
			// leader typing a member's name found nobody unless the group's
			// name happened to match as well.
			$users = [];

			foreach ( $groups as $group )
			{
				foreach ( $this->accessibleMembers( $leader, $group ) as $member )
				{
					if ( $needle === ''
					     || stripos( $member->getUID(), $needle ) !== false
					     || stripos( $member->getDisplayName(), $needle ) !== false )
					{
						$users[ $member->getUID() ] = $member;
					}
				}
			}

			$users  = array_values( $users );
			$groups = array_values( array_filter(
				$groups,
				static fn ( $group ): bool => $needle === ''
				                              || stripos( $group->getGID(), $needle ) !== false
				                              || stripos( $group->getDisplayName(), $needle ) !== false,
			) );
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
