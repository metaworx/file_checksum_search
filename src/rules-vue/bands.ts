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

import type { GroupFolderOption, Rule } from './types'
import { t } from '../l10n'

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

/**
 * Shown beside the band number so the colour is never the only cue; "Band 9"
 * for a band this list does not know. Translated when read, not on import.
 */
export function bandLabel(band: number): string {
	switch (band) {
	case BAND.EXACT_ENFORCED: return t('file_checksum_search', 'Enforced — specific')
	case BAND.GROUP_ENFORCED: return t('file_checksum_search', 'Enforced — groups & group folders')
	case BAND.NAMESPACE_ENFORCED: return t('file_checksum_search', 'Enforced — all home folders')
	case BAND.UNIVERSAL_ENFORCED: return t('file_checksum_search', 'Enforced — everything')
	case BAND.EXACT: return t('file_checksum_search', 'Specific rules')
	case BAND.GROUP: return t('file_checksum_search', 'Groups & group folders')
	case BAND.NAMESPACE: return t('file_checksum_search', 'All home folders')
	case BAND.UNIVERSAL: return t('file_checksum_search', 'Everything')
	default: return t('file_checksum_search', 'Band {band}', { band })
	}
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
	return bandOfKind(selectorKind(rule.selector || '*'), rule.admin_enforced === true)
}

/**
 * The band a selector *kind* lands in — the target never matters, which is
 * what lets a form preview the band before a target is picked.
 */
export function bandOfKind(kind: SelectorKind, enforced: boolean): number {
	const rank = RANK[kind]
	return enforced ? rank : rank + 4
}

/** "<band>.<position>" — lower is higher priority. */
export function priorityLabel(rule: Pick<Rule, 'band' | 'position' | 'selector' | 'admin_enforced'>): string {
	return `${rule.band ?? bandOf(rule as Rule)}.${rule.position ?? 1}`
}

/** "Team Docs (#1)" when the folder is known, the bare id when it is not. */
function groupFolderName(id: string | null, folders?: GroupFolderOption[]): string {
	const folder = (folders ?? []).find((candidate) => String(candidate.id) === String(id))

	return folder ? `${folder.name} (#${folder.id})` : String(id)
}

/**
 * What a selector should be called in the Scope column.
 *
 * `groupFolders` resolves a folder id to the name people know it by; without
 * it — or for a folder that no longer exists — the bare id remains, which is
 * exactly what pairs with the "provider missing" badge.
 */
export function selectorLabel(
	selector: string,
	options?: { groupFolderTerm?: string | null, groupFolders?: GroupFolderOption[] },
): string {
	const groupFolderTerm = options?.groupFolderTerm
	switch (selectorKind(selector || '*')) {
	case 'universal':
		return t('file_checksum_search', 'Everything')
	case 'homeAll':
		return t('file_checksum_search', 'All home folders')
	case 'group':
		return t('file_checksum_search', 'Group: {group}', { group: selectorTarget(selector) ?? '' })
	case 'groupfolder':
		// The groupfolders app calls itself "Team Folders" these days; when
		// it is there we use its own name, which that app translates, and
		// when it is gone we name the missing provider by its slug rather
		// than pretending. The name is not this app's to translate; how it
		// joins the folder's is.
		// TRANSLATORS: a rule's scope: {folders} is what the team folders app calls itself, {folder} one team folder's name
		return t('file_checksum_search', '{folders}: {folder}', { folders: groupFolderTerm ?? 'app:groupfolders', folder: groupFolderName(selectorTarget(selector), options?.groupFolders) })
	case 'storage':
		return t('file_checksum_search', 'Storage: {storage}', { storage: selectorTarget(selector) ?? '' })
	default:
		return selectorTarget(selector) ?? selector
	}
}

/**
 * What each band means, for the help popover on its header row; empty for a
 * band this list does not know. Translated when read, not on import.
 */
export function bandHelp(band: number): string {
	switch (band) {
	case BAND.EXACT_ENFORCED: return t('file_checksum_search', 'Administrator-enforced rules aimed at one specific slice — a single user\'s home or one storage. Nothing can outrun them there, and their subjects cannot edit or disable them.')
	case BAND.GROUP_ENFORCED: return t('file_checksum_search', 'Administrator-enforced rules for the members of a group, or for one group folder. Only an enforced rule aimed at something more specific comes before them.')
	case BAND.NAMESPACE_ENFORCED: return t('file_checksum_search', 'Administrator-enforced rules covering every home folder.')
	case BAND.UNIVERSAL_ENFORCED: return t('file_checksum_search', 'Administrator-enforced rules covering every storage there is.')
	case BAND.EXACT: return t('file_checksum_search', 'Rules for one specific slice — users\' own rules for their homes, or a rule for one storage. They decide a file only where no enforced rule matched it first.')
	case BAND.GROUP: return t('file_checksum_search', 'Rules for a group\'s members, or for one group folder. Not enforced: a user\'s own rule overrides them for their files.')
	case BAND.NAMESPACE: return t('file_checksum_search', 'Defaults for all home folders. Within this segment a catch-all default — path **, / or empty — always evaluates last, after any more specific rules here.')
	case BAND.UNIVERSAL: return t('file_checksum_search', 'The last resort, covering every storage — external mounts and group folders included. Enable deliberately: it can reach storage that is slow or costs money to read.')
	default: return ''
	}
}
