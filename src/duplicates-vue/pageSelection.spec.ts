/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import type { DuplicateFileItem, DuplicateGroup } from './composables/useDuplicates'
import { bestCopies, chooseMatching, chosenIn, groupKey, stillListed, withGroupChoice } from './pageSelection'
import { compilePattern } from './pathPattern'

const ALL: DuplicateFileItem['actions'] = ['trash', 'delete']

function file(fileid: number, path: string, more: Partial<DuplicateFileItem> = {}): DuplicateFileItem {
	return { fileid, path, name: path.split('/').pop() ?? path, actions: ALL, ...more }
}

function group(hash: string, files: DuplicateFileItem[], algo = 'sha1'): DuplicateGroup {
	return { algo, hash_value: hash, file_count: files.length, files }
}

const backup = compilePattern('/Backup')!
const inBackup = (row: DuplicateFileItem) => backup(row.path)

describe('choosing by a rule', () => {
	it('takes what matches, in every group', () => {
		const groups = [
			group('a', [file(1, '/Backup/a.txt'), file(2, '/Docs/a.txt')]),
			group('b', [file(3, '/Docs/b.txt'), file(4, '/Backup/b.txt')]),
		]

		const chosen = chooseMatching(groups, new Set(), inBackup)

		expect([...chosen.selected].sort()).toEqual([1, 4])
		expect(chosen.leftAlone).toEqual([])
	})

	// D1-A: a rule never takes a group's last copy outside the trash; the
	// group keeps its choice, and is named.
	it('leaves a group alone where it would take every copy outside the trash', () => {
		const groups = [
			group('a', [file(1, '/Backup/a.txt'), file(2, '/Backup/old/a.txt'), file(9, 'trash:///a.txt', { trash: { deletedAt: '2026-10-21T00:00:00+00:00' } })]),
			group('b', [file(3, '/Docs/b.txt'), file(4, '/Backup/b.txt')]),
		]

		const chosen = chooseMatching(groups, new Set([2]), inBackup)

		expect([...chosen.selected].sort()).toEqual([2, 4])
		expect(chosen.leftAlone).toEqual(['sha1:a'])
	})

	// A row the viewer may not delete stays whatever is chosen: it is the copy kept.
	it('counts a row that may not be deleted as a copy that stays', () => {
		const groups = [group('a', [file(1, '/Backup/a.txt'), file(2, '/Backup/shared.txt', { actions: [] })])]

		const chosen = chooseMatching(groups, new Set(), inBackup)

		expect([...chosen.selected]).toEqual([1])
		expect(chosen.leftAlone).toEqual([])
	})

	// One file under two algorithms is one choice: a group left alone spares
	// its copies in the other group too, and that group is weighed again.
	it('weighs a file listed in two groups once', () => {
		const groups = [
			group('a', [file(1, '/Backup/a.txt'), file(2, '/Backup/b.txt')], 'sha1'),
			group('m', [file(1, '/Backup/a.txt'), file(2, '/Backup/b.txt')], 'md5'),
		]

		const chosen = chooseMatching(groups, new Set(), inBackup)

		expect([...chosen.selected]).toEqual([])
		expect(chosen.leftAlone.sort()).toEqual(['md5:m', 'sha1:a'])
	})

	it('picks the versions', () => {
		const groups = [group('a', [file(1, '/a.txt'), file(5, 'versions:///a.txt', { version: { fileid: 1, timestamp: '2026-10-21T00:00:00+00:00' } })])]

		expect([...chooseMatching(groups, new Set(), (row) => row.version !== undefined).selected]).toEqual([5])
	})

	// D7-A: beside a file, a version is no copy: it goes with its file, and expires.
	it('counts a version beside a file as no copy', () => {
		const version = { fileid: 9, timestamp: '2026-10-21T00:00:00+00:00' }
		const groups = [group('a', [file(1, '/Backup/a.txt'), file(5, 'versions:///Docs/a.txt', { version })])]

		expect(chooseMatching(groups, new Set(), inBackup).leftAlone).toEqual(['sha1:a'])
	})

	// D7-A: a group of versions alone keeps the content in them; one of
	// them may go, the last may not.
	it('keeps the last version of a group of versions alone', () => {
		const version = (fileid: number) => ({ version: { fileid, timestamp: '2026-10-21T00:00:00+00:00' } })
		const groups = [group('a', [file(5, 'versions:///Readme.md', version(1)), file(6, 'versions:///x/Readme.md', version(2))])]
		const underX = compilePattern('/x')!

		const one = chooseMatching(groups, new Set(), (row) => underX(row.path))
		expect([...one.selected]).toEqual([6])
		expect(one.leftAlone).toEqual([])

		const both = chooseMatching(groups, new Set(), (row) => row.version !== undefined)
		expect([...both.selected]).toEqual([])
		expect(both.leftAlone).toEqual(['sha1:a'])
	})

	// D8-A: Keep one copy off, a rule takes what it matches, a group's every
	// copy included — the content holds a password, say.
	it('takes every copy where asked not to keep one', () => {
		const groups = [
			group('a', [file(1, '/Backup/a.txt'), file(2, '/Backup/old/a.txt')]),
			group('m', [file(1, '/Backup/a.txt'), file(2, '/Backup/old/a.txt')], 'md5'),
		]

		const chosen = chooseMatching(groups, new Set([7]), inBackup, false)

		expect([...chosen.selected].sort()).toEqual([1, 2, 7])
		expect(chosen.leftAlone).toEqual([])
	})
})

describe('a group\'s best copies', () => {
	const version = { version: { fileid: 1, timestamp: '2026-10-21T00:00:00+00:00' } }
	const trash = { trash: { deletedAt: '2026-10-21T00:00:00+00:00' } }

	it('are its files outside the trash, else its versions outside the trash', () => {
		expect(bestCopies(group('a', [file(1, '/a'), file(2, 'versions:///a', version), file(3, 'trash:///a', trash)])).map((row) => row.fileid)).toEqual([1])
		expect(bestCopies(group('a', [file(2, 'versions:///a', version), file(3, 'trash:///a', trash)])).map((row) => row.fileid)).toEqual([2])
	})
})

describe('a group\'s own choice', () => {
	const g = group('a', [file(1, '/a.txt'), file(2, '/b.txt'), file(3, '/c.txt')])

	it('replaces its rows and keeps the others\'', () => {
		const next = withGroupChoice(new Set([2, 99]), g, [1, 3])

		expect([...next].sort((x, y) => x - y)).toEqual([1, 3, 99])
		expect(chosenIn(g, next)).toEqual([1, 3])
	})

	it('forgets rows no longer listed', () => {
		expect([...stillListed(new Set([1, 99]), [g])]).toEqual([1])
	})

	it('is keyed by algorithm and hash', () => {
		expect(groupKey(g)).toBe('sha1:a')
	})
})
