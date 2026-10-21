/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import type { DuplicateGroup } from './composables/useDuplicates'
import { mergeIdentical, orderPairs, pairsOf } from './identicalGroups'

function group(algo: string, hash: string, ids: number[]): DuplicateGroup {
	return {
		algo,
		hash_value: hash,
		file_count: ids.length,
		files: ids.map((fileid) => ({ fileid, path: `/f${fileid}`, name: `f${fileid}` })),
	}
}

const ALGORITHMS = ['sha1', 'md5', 'sha256']

describe('mergeIdentical', () => {
	// The server lists md5 before sha1; the list names sha1 first.
	it('makes groups of the same rows one, where the first is listed, its pairs in the list\'s order', () => {
		const md5 = group('md5', 'm', [1, 2])
		const other = group('sha1', 'o', [3, 4])
		const sha1 = group('sha1', 's', [2, 1])

		const merged = mergeIdentical([md5, other, sha1], ALGORITHMS)

		expect(merged).toHaveLength(2)
		expect(merged[0]).toBe(md5)
		expect(merged[1]).toBe(other)
		expect(pairsOf(merged[0])).toEqual([{ algo: 'sha1', hash_value: 's' }, { algo: 'md5', hash_value: 'm' }])
		expect(merged[0].algo).toBe('sha1')
		expect(merged[0].hash_value).toBe('s')
		expect(other.hashes).toBeUndefined()
	})

	it('keeps groups that differ by a row apart', () => {
		const merged = mergeIdentical([group('md5', 'm', [1, 2]), group('sha1', 's', [1, 2, 3])], ALGORITHMS)

		expect(merged).toHaveLength(2)
		expect(merged.every((each) => each.hashes === undefined)).toBe(true)
	})

	// Load more: a group shown takes one loaded after it, and then a third.
	it('adds to a group already merged', () => {
		const shown = mergeIdentical([group('md5', 'm', [1, 2]), group('sha256', 'h', [1, 2])], ALGORITHMS)

		const merged = mergeIdentical([...shown, group('sha1', 's', [1, 2])], ALGORITHMS)

		expect(merged).toHaveLength(1)
		expect(pairsOf(merged[0]).map((pair) => pair.algo)).toEqual(['sha1', 'md5', 'sha256'])
	})

	it('puts an algorithm the list does not name last, and keeps the listed order without a list', () => {
		const merged = mergeIdentical([group('crc32', 'c', [1, 2]), group('sha256', 'h', [1, 2]), group('md5', 'm', [1, 2])], ALGORITHMS)
		expect(pairsOf(merged[0]).map((pair) => pair.algo)).toEqual(['md5', 'sha256', 'crc32'])

		const unordered = mergeIdentical([group('md5', 'm', [1, 2]), group('sha1', 's', [1, 2])], [])
		expect(pairsOf(unordered[0]).map((pair) => pair.algo)).toEqual(['md5', 'sha1'])
	})

	it('merges no groups without rows', () => {
		const empty = { algo: 'sha1', hash_value: 'a', file_count: 0, files: [] }

		expect(mergeIdentical([empty, { ...empty, hash_value: 'b' }], ALGORITHMS)).toHaveLength(2)
	})
})

describe('orderPairs', () => {
	// The list arrives after the first groups were merged.
	it('puts a merged group\'s pairs in the list\'s order, its first the group\'s own', () => {
		const merged = mergeIdentical([group('md5', 'm', [1, 2]), group('sha1', 's', [1, 2]), group('sha1', 'o', [3, 4])], [])

		orderPairs(merged, ALGORITHMS)

		expect(pairsOf(merged[0]).map((pair) => pair.algo)).toEqual(['sha1', 'md5'])
		expect(merged[0].hash_value).toBe('s')
		expect(merged[1].hashes).toBeUndefined()
	})
})
