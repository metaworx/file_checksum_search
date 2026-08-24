/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Priority bands, mirrored from RuleService.
 *
 * The server is authoritative — every rule it returns already carries its
 * `band` and `position`. This exists for the one case that has no rule yet:
 * showing an administrator, while they are still filling in the dialog, which
 * band the rule they are describing would land in.
 *
 * Keep bandOf() in step with RuleService::bandOf().
 */

import type { Rule } from './types'

export const BAND = {
	USER_ENFORCED: 1,
	GROUP_ENFORCED: 2,
	GLOBAL_ENFORCED: 3,
	USER: 4,
	GROUP: 5,
	GLOBAL: 6,
	DEFAULT: 7,
} as const

/** Shown beside the band number so the colour is never the only cue. */
export const BAND_LABELS: Record<number, string> = {
	[BAND.USER_ENFORCED]: 'Enforced — per user',
	[BAND.GROUP_ENFORCED]: 'Enforced — per group',
	[BAND.GLOBAL_ENFORCED]: 'Enforced — everyone',
	[BAND.USER]: 'User rules',
	[BAND.GROUP]: 'Defaults — per group',
	[BAND.GLOBAL]: 'Defaults — everyone',
	[BAND.DEFAULT]: 'Catch-all default',
}

/**
 * What each band means, for the help popover on its header row.
 *
 * The label says which band a rule is in; this says why that matters — which
 * rules it outranks and who is allowed to change it.
 */
export const BAND_HELP: Record<number, string> = {
	[BAND.USER_ENFORCED]: 'An administrator enforced this rule for one named user. Nothing can outrun it '
		+ 'for that user, and they cannot edit, reorder or disable it themselves.',
	[BAND.GROUP_ENFORCED]: 'An administrator enforced this rule for the members of one group. Only an '
		+ 'enforced rule aimed at a single user comes before it.',
	[BAND.GLOBAL_ENFORCED]: 'An administrator enforced this rule for everyone. Only an enforced rule aimed '
		+ 'at a group or a single user comes before it.',
	[BAND.USER]: 'Rules users created for their own files. They decide a file only where no enforced rule '
		+ 'matched it first, and each user reorders their own.',
	[BAND.GROUP]: 'Administrator defaults for the members of one group, which a user\'s own rule overrides. '
		+ 'Not enforced: they apply where nothing more specific matched.',
	[BAND.GLOBAL]: 'Administrator defaults for everyone, which a group default or a user\'s own rule '
		+ 'overrides. Not enforced: they apply where nothing more specific matched.',
	[BAND.DEFAULT]: 'The last resort — it decides every file no other rule matched. It cannot be deleted or '
		+ 'moved out of this band; disable it to let unmatched files go unhashed.',
}

export const SCOPE_ALL = 'all'
export const SCOPE_GROUP_PREFIX = 'group:'

export type ScopeKind = 'global' | 'group' | 'user'

export function scopeKind(userScope: string): ScopeKind {
	if (userScope === SCOPE_ALL) return 'global'
	if (userScope.startsWith(SCOPE_GROUP_PREFIX)) return 'group'
	return 'user'
}

export function scopeGroupId(userScope: string): string | null {
	return scopeKind(userScope) === 'group'
		? userScope.slice(SCOPE_GROUP_PREFIX.length)
		: null
}

/** Mirrors RuleService::bandOf(). */
export function bandOf(rule: Pick<Rule, 'userScope' | 'admin_enforced' | 'pinned'>): number {
	if (rule.pinned === true) return BAND.DEFAULT

	const enforced = rule.admin_enforced === true

	switch (scopeKind(rule.userScope || SCOPE_ALL)) {
	case 'user':
		return enforced ? BAND.USER_ENFORCED : BAND.USER
	case 'group':
		return enforced ? BAND.GROUP_ENFORCED : BAND.GROUP
	default:
		return enforced ? BAND.GLOBAL_ENFORCED : BAND.GLOBAL
	}
}

/** "<band>.<position>" — lower is higher priority. */
export function priorityLabel(rule: Pick<Rule, 'band' | 'position'>): string {
	return `${rule.band ?? bandOf(rule as Rule)}.${rule.position ?? 1}`
}

/** How a scope reads in the table's Scope column. */
export function scopeLabel(userScope: string): string {
	switch (scopeKind(userScope || SCOPE_ALL)) {
	case 'global':
		return 'All users'
	case 'group':
		return `Group: ${scopeGroupId(userScope)}`
	default:
		return userScope
	}
}
