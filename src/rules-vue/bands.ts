/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The selector model, mirrored from RuleService/Selector for display.
 *
 * The server is authoritative — every rule it returns already carries its
 * `selector`, `band`, `position` and `isDefault`. This exists for the one
 * case that has no stored rule yet: showing, while the dialog is still
 * being filled in, which band the rule being described would land in.
 *
 * Keep bandOf() in step with Selector::band().
 */

import type { Rule } from './types'

/** Display bands: the selector's specificity rank, enforced 1–4, unenforced 5–8. */
export const BAND = {
	EXACT_ENFORCED: 1,
	GROUP_ENFORCED: 2,
	NAMESPACE_ENFORCED: 3,
	UNIVERSAL_ENFORCED: 4,
	EXACT: 5,
	GROUP: 6,
	NAMESPACE: 7,
	UNIVERSAL: 8,
} as const

/** Shown beside the band number so the colour is never the only cue. */
export const BAND_LABELS: Record<number, string> = {
	[BAND.EXACT_ENFORCED]: 'Enforced — specific',
	[BAND.GROUP_ENFORCED]: 'Enforced — groups & group folders',
	[BAND.NAMESPACE_ENFORCED]: 'Enforced — all home folders',
	[BAND.UNIVERSAL_ENFORCED]: 'Enforced — everything',
	[BAND.EXACT]: 'Specific rules',
	[BAND.GROUP]: 'Groups & group folders',
	[BAND.NAMESPACE]: 'All home folders',
	[BAND.UNIVERSAL]: 'Everything',
}

export type SelectorKind = 'user' | 'group' | 'homeAll' | 'groupfolder' | 'storage' | 'universal'

/** Split on the FIRST colon — targets may contain ':' and '//'. */
export function selectorKind(selector: string): SelectorKind {
	if (selector === '*') return 'universal'
	const colon = selector.indexOf(':')
	if (colon < 1) return 'user' // legacy bare uid
	const kind = selector.slice(0, colon)
	const target = selector.slice(colon + 1)
	if (kind === 'home') return target === '*' ? 'homeAll' : 'user'
	if (kind === 'group') return 'group'
	if (kind === 'groupfolder') return 'groupfolder'
	if (kind === 'storage') return 'storage'
	return 'user'
}

export function selectorTarget(selector: string): string | null {
	if (selector === '*') return null
	const colon = selector.indexOf(':')
	if (colon < 1) return selector
	const target = selector.slice(colon + 1)
	return target === '*' ? null : target
}

const RANK: Record<SelectorKind, number> = {
	user: 1,
	storage: 1,
	group: 2,
	groupfolder: 2,
	homeAll: 3,
	universal: 4,
}

/** Mirrors Selector::band(). */
export function bandOf(rule: Pick<Rule, 'selector' | 'admin_enforced'>): number {
	const rank = RANK[selectorKind(rule.selector || '*')]
	return rule.admin_enforced === true ? rank : rank + 4
}

/** "<band>.<position>" — lower is higher priority. */
export function priorityLabel(rule: Pick<Rule, 'band' | 'position' | 'selector' | 'admin_enforced'>): string {
	return `${rule.band ?? bandOf(rule as Rule)}.${rule.position ?? 1}`
}

/** How a selector reads in the table's Selector column. */
export function selectorLabel(selector: string): string {
	switch (selectorKind(selector || '*')) {
	case 'universal':
		return 'Everything'
	case 'homeAll':
		return 'All home folders'
	case 'group':
		return `Group: ${selectorTarget(selector)}`
	case 'groupfolder':
		return `Group folder: ${selectorTarget(selector)}`
	case 'storage':
		return `Storage: ${selectorTarget(selector)}`
	default:
		return selectorTarget(selector) ?? selector
	}
}

/**
 * What each band means, for the help popover on its header row.
 */
export const BAND_HELP: Record<number, string> = {
	[BAND.EXACT_ENFORCED]: 'Administrator-enforced rules aimed at one specific slice — a single user\'s home or '
		+ 'one storage. Nothing can outrun them there, and their subjects cannot edit or disable them.',
	[BAND.GROUP_ENFORCED]: 'Administrator-enforced rules for the members of a group, or for one group folder. '
		+ 'Only an enforced rule aimed at something more specific comes before them.',
	[BAND.NAMESPACE_ENFORCED]: 'Administrator-enforced rules covering every home folder.',
	[BAND.UNIVERSAL_ENFORCED]: 'Administrator-enforced rules covering every storage there is.',
	[BAND.EXACT]: 'Rules for one specific slice — users\' own rules for their homes, or a rule for one '
		+ 'storage. They decide a file only where no enforced rule matched it first.',
	[BAND.GROUP]: 'Rules for a group\'s members, or for one group folder. Not enforced: a user\'s own rule '
		+ 'overrides them for their files.',
	[BAND.NAMESPACE]: 'Defaults for all home folders. Within this segment the bare ** default always '
		+ 'evaluates last, after any more specific rules here.',
	[BAND.UNIVERSAL]: 'The last resort, covering every storage — external mounts and group folders '
		+ 'included. Enable deliberately: it can reach storage that is slow or costs money to read.',
}
