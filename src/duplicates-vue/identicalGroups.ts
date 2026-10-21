/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Groups of the same rows, merged on the Duplicates page. The listing pages
 * by algorithm and hash, so a file hashed under SHA-1 and MD5 is listed in
 * two groups, row for row the same: on the page they are one, its header a
 * line for each algorithm and hash. Groups that differ by a row stay apart.
 */
import type { DuplicateGroup, GroupHash } from './composables/useDuplicates'

/** Every algorithm and hash a group stands for: those merged into it, or its own. */
export function pairsOf(group: DuplicateGroup): GroupHash[] {
	return group.hashes ?? [{ algo: group.algo, hash_value: group.hash_value }]
}

/** What makes two groups the same: their rows' file ids, in any order. */
function rowsOf(group: DuplicateGroup): string {
	return group.files.map((file) => file.fileid).sort((a, b) => a - b).join(',')
}

/**
 * A merged group's pairs in the order of $algorithms, one it does not name
 * last; the first is the group's own, which its actions and Verify use.
 */
function lead(group: DuplicateGroup, algorithms: string[]): void {
	const rank = (algo: string) => {
		const at = algorithms.indexOf(algo)
		return at < 0 ? algorithms.length : at
	}
	const pairs = [...pairsOf(group)].sort((a, b) => rank(a.algo) - rank(b.algo))
	group.hashes = pairs
	group.algo = pairs[0].algo
	group.hash_value = pairs[0].hash_value
}

/**
 * $groups with those of the same rows as one, where the first of them is
 * listed, its pairs in the order of $algorithms. That first group takes the
 * others' pairs in place, so what the page holds of it — a verification's
 * counts, a choice — stays with it.
 */
export function mergeIdentical(groups: DuplicateGroup[], algorithms: string[]): DuplicateGroup[] {
	const first = new Map<string, DuplicateGroup>()
	const merged: DuplicateGroup[] = []
	const grown = new Set<DuplicateGroup>()
	for (const group of groups) {
		// A group without rows is the same as no other.
		if (group.files.length === 0) {
			merged.push(group)
			continue
		}
		const rows = rowsOf(group)
		const kept = first.get(rows)
		if (kept === undefined) {
			first.set(rows, group)
			merged.push(group)
			continue
		}
		kept.hashes = [...pairsOf(kept), ...pairsOf(group)]
		grown.add(kept)
	}
	for (const group of grown) {
		lead(group, algorithms)
	}
	return merged
}

/** The merged groups' pairs put in the order of $algorithms, which may arrive after the groups. */
export function orderPairs(groups: DuplicateGroup[], algorithms: string[]): void {
	for (const group of groups) {
		if (group.hashes) {
			lead(group, algorithms)
		}
	}
}
