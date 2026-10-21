/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The rows chosen across the Duplicates page, by file id: one choice for
 * the page, so that a file listed in two groups — the same content under
 * two algorithms — is chosen, or not, in both.
 *
 * Choosing by a rule — a pattern, the versions — never takes every copy of
 * a group outside the trash: such a group keeps its choice as it was, and
 * is named, for a person to choose in by hand.
 */
import type { DuplicateFileItem, DuplicateGroup } from './composables/useDuplicates'

/** A group's key on the page: its algorithm and its hash. */
export function groupKey(group: Pick<DuplicateGroup, 'algo' | 'hash_value'>): string {
	return `${group.algo}:${group.hash_value}`
}

/** A row that may be chosen: one the server allows to be deleted, one way or the other. */
export function choosable(file: DuplicateFileItem): boolean {
	return file.actions?.includes('trash') === true || file.actions?.includes('delete') === true
}

/** What choosing by a rule did: the choice after it, and the groups it left alone. */
export interface Chosen {
	selected: Set<number>
	/** The keys of the groups in which the rule would have taken every copy outside the trash. */
	leftAlone: string[]
}

/**
 * The rows $matches picks added to $selected, in every group but those
 * where that would leave no copy outside the trash. Leaving a group alone
 * can spare a copy another group's rows would have taken, so the groups
 * are weighed again until none changes.
 */
export function chooseMatching(groups: DuplicateGroup[], selected: ReadonlySet<number>, matches: (file: DuplicateFileItem) => boolean): Chosen {
	const candidates = new Map<string, number[]>()
	for (const group of groups) {
		const ids = group.files.filter((file) => choosable(file) && !selected.has(file.fileid) && matches(file)).map((file) => file.fileid)
		if (ids.length > 0) {
			candidates.set(groupKey(group), ids)
		}
	}

	const leftAlone = new Set<string>()
	for (;;) {
		const taken = new Set(selected)
		for (const [key, ids] of candidates) {
			if (!leftAlone.has(key)) {
				ids.forEach((id) => taken.add(id))
			}
		}
		// A copy is a file: a version or a trashed one keeps the content
		// only until the versions expire or the trash is emptied.
		const emptied = groups.filter((group) => {
			const key = groupKey(group)
			return candidates.has(key)
				&& !leftAlone.has(key)
				&& group.files.every((file) => file.trash !== undefined || file.version !== undefined || taken.has(file.fileid))
		})
		if (emptied.length === 0) {
			return { selected: taken, leftAlone: [...leftAlone] }
		}
		emptied.forEach((group) => leftAlone.add(groupKey(group)))
	}
}

/** The group's rows chosen, in its order. */
export function chosenIn(group: DuplicateGroup, selected: ReadonlySet<number>): number[] {
	return group.files.filter((file) => selected.has(file.fileid)).map((file) => file.fileid)
}

/** The choice with $group's rows replaced by $fileIds. */
export function withGroupChoice(selected: ReadonlySet<number>, group: DuplicateGroup, fileIds: readonly number[]): Set<number> {
	const next = new Set(selected)
	group.files.forEach((file) => next.delete(file.fileid))
	fileIds.forEach((id) => next.add(id))
	return next
}

/** The choice less rows no longer listed: a group read again may have lost some. */
export function stillListed(selected: ReadonlySet<number>, groups: DuplicateGroup[]): Set<number> {
	const listed = new Set(groups.flatMap((group) => group.files.map((file) => file.fileid)))
	return new Set([...selected].filter((id) => listed.has(id)))
}
