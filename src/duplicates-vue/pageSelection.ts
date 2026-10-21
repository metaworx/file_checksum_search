/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The rows chosen across the Duplicates page, by file id: one choice for
 * the page, so that a file listed in two groups — the same content under
 * two algorithms, in groups that differ by a row — is chosen, or not, in
 * both.
 *
 * Choosing across the page — a pattern, the versions, every duplicate, the
 * choice inverted — keeps one copy of each group unless asked not to: a
 * group whose best copy it would take keeps its choice as it was, and is
 * named, for a person to choose in by hand. Asked not to, it takes whatever
 * it matches, a content's every copy included, as one that holds a
 * password must go everywhere. Choosing in a group by hand is not limited.
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
 * The copies a group keeps the content in, best first: its files outside
 * the trash, or, where it has none, its versions outside the trash. A
 * version is no copy beside a file — it goes with its file, and expires —
 * but in a group of versions alone it is the best there is.
 */
export function bestCopies(group: DuplicateGroup): DuplicateFileItem[] {
	const outside = group.files.filter((file) => file.trash === undefined)
	const files = outside.filter((file) => file.version === undefined)
	return files.length > 0 ? files : outside
}

/** Whether every one of the group's {@link bestCopies} is chosen. */
function emptiedBy(group: DuplicateGroup, selected: ReadonlySet<number>): boolean {
	const best = bestCopies(group)
	return best.length > 0 && best.every((file) => selected.has(file.fileid))
}

/**
 * $proposed, less what it changes in a group it would empty of its
 * {@link bestCopies}: that group's rows keep the choice they had in
 * $before, wherever else they are listed, and is named. Keeping them can
 * empty another group sharing a file, so the groups are weighed again
 * until none is emptied. A group the change leaves as it was is not
 * weighed: what was chosen in it by hand stands.
 */
function guarded(groups: DuplicateGroup[], before: ReadonlySet<number>, proposed: ReadonlySet<number>): Chosen {
	const result = new Set(proposed)
	const leftAlone = new Set<string>()
	const changed = (group: DuplicateGroup) => group.files.some((file) => before.has(file.fileid) !== proposed.has(file.fileid))
	for (;;) {
		const emptied = groups.filter((group) => !leftAlone.has(groupKey(group)) && changed(group) && emptiedBy(group, result))
		if (emptied.length === 0) {
			return { selected: result, leftAlone: [...leftAlone] }
		}
		for (const group of emptied) {
			leftAlone.add(groupKey(group))
			group.files.forEach((file) => before.has(file.fileid) ? result.add(file.fileid) : result.delete(file.fileid))
		}
	}
}

/** $proposed as it stands, or, with $keepOne, {@link guarded}. */
function ruled(groups: DuplicateGroup[], before: ReadonlySet<number>, proposed: Set<number>, keepOne: boolean): Chosen {
	return keepOne ? guarded(groups, before, proposed) : { selected: proposed, leftAlone: [] }
}

/** Every row of the groups shown that may be chosen, by file id. */
function choosableIds(groups: DuplicateGroup[]): number[] {
	return groups.flatMap((group) => group.files.filter(choosable).map((file) => file.fileid))
}

/**
 * The rows $matches picks added to $selected. With $keepOne, as the page
 * starts, not in a group where that would take every one of its
 * {@link bestCopies}.
 */
export function chooseMatching(groups: DuplicateGroup[], selected: ReadonlySet<number>, matches: (file: DuplicateFileItem) => boolean, keepOne = true): Chosen {
	const proposed = new Set(selected)
	groups.forEach((group) => group.files.filter((file) => choosable(file) && matches(file)).forEach((file) => proposed.add(file.fileid)))
	return ruled(groups, selected, proposed, keepOne)
}

/** The rows $matches picks let go of; letting go never empties a group. */
export function dropMatching(groups: DuplicateGroup[], selected: ReadonlySet<number>, matches: (file: DuplicateFileItem) => boolean): Set<number> {
	const next = new Set(selected)
	groups.forEach((group) => group.files.filter(matches).forEach((file) => next.delete(file.fileid)))
	return next
}

/**
 * Every row that may be chosen turned over: chosen let go, the rest
 * chosen. With $keepOne, a group it would empty keeps its choice (D10-A).
 */
export function invertChoice(groups: DuplicateGroup[], selected: ReadonlySet<number>, keepOne = true): Chosen {
	const proposed = new Set(selected)
	new Set(choosableIds(groups)).forEach((id) => selected.has(id) ? proposed.delete(id) : proposed.add(id))
	return ruled(groups, selected, proposed, keepOne)
}

/** When a copy last held its content: a version's own time, else the file's last change, in ms. */
function heldSince(file: DuplicateFileItem): number {
	return file.version ? new Date(file.version.timestamp).getTime() : (file.mtime ?? 0) * 1000
}

/**
 * The copy *Select duplicates* leaves in a group (D12-A): the oldest of its
 * {@link bestCopies} by when it last changed, the first path where two are
 * as old. None where one of them may not be deleted: it stays anyway.
 */
export function keeperOf(group: DuplicateGroup): number | null {
	const best = bestCopies(group)
	if (best.length === 0 || best.some((file) => !choosable(file))) {
		return null
	}
	return [...best].sort((a, b) => heldSince(a) - heldSince(b) || a.path.localeCompare(b.path))[0].fileid
}

/**
 * D11-C: with $keepOne, *Select duplicates*, every row that may be chosen
 * but each group's {@link keeperOf}; without, *Select all*, every row.
 * Groups sharing files may pick different keepers, so the guard weighs
 * the result as it weighs a rule's.
 */
export function chooseAll(groups: DuplicateGroup[], selected: ReadonlySet<number>, keepOne = true): Chosen {
	const proposed = new Set([...selected, ...choosableIds(groups)])
	if (!keepOne) {
		return { selected: proposed, leftAlone: [] }
	}
	groups.forEach((group) => {
		const keeper = keeperOf(group)
		if (keeper !== null && !selected.has(keeper)) {
			proposed.delete(keeper)
		}
	})
	return guarded(groups, selected, proposed)
}

/**
 * D9-A: Keep one copy turned on lets go of every group chosen whole, and
 * names it; the files are let go of wherever else they are listed.
 */
export function withoutWholeGroups(groups: DuplicateGroup[], selected: ReadonlySet<number>): Chosen {
	const next = new Set(selected)
	const whole = groups.filter((group) => emptiedBy(group, selected))
	whole.forEach((group) => group.files.forEach((file) => next.delete(file.fileid)))
	return { selected: next, leftAlone: whole.map(groupKey) }
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
