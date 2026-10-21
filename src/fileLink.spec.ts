/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { fileLink, groupPreviewUrl, rowLink } from './fileLink'

// Files sharing a full hash share their content, and so their preview: one
// per group, from the first row that can show it.
describe('groupPreviewUrl', () => {
	const DELETED = { deletedAt: '2023-11-14T22:13:20+00:00' }
	const VERSION = { fileid: 7, timestamp: '2023-11-14T22:13:20+00:00' }

	it('asks core for the first file the viewer can open, with its type\'s icon to fall back on', () => {
		const url = groupPreviewUrl([{ fileid: 3, version: VERSION }, { fileid: 4, openable: false }, { fileid: 5 }, { fileid: 6 }])

		expect(url).toMatch(/\/core\/preview\?fileId=5&/)
		expect(url).toContain('mimeFallback=1')
	})

	it('asks the trash where only a file in it can show the content', () => {
		expect(groupPreviewUrl([{ fileid: 3, version: VERSION }, { fileid: 8, trash: DELETED }])).toMatch(/\/apps\/files_trashbin\/preview\?fileId=8&/)
	})

	it('has none for versions only, nor for files the viewer cannot open', () => {
		expect(groupPreviewUrl([{ fileid: 3, version: VERSION }, { fileid: 9, version: VERSION, trash: DELETED }])).toBeNull()
		expect(groupPreviewUrl([{ fileid: 4, openable: false }, { fileid: 8, trash: DELETED, openable: false }])).toBeNull()
	})
})

describe('rowLink', () => {
	// Files keeps a file's versions in its details and opens none itself.
	it('opens a version\'s file, and a file itself', () => {
		expect(rowLink({ fileid: 9, version: { fileid: 7 } })).toBe(fileLink(7))
		expect(rowLink({ fileid: 9 })).toBe(fileLink(9))
	})

	// A file in the trash is restored from the trash, which is where it opens.
	it('opens the trash for a file in it', () => {
		expect(rowLink({ fileid: 9, trash: { deletedAt: '2023-11-14T22:13:20+00:00' } })).toMatch(/\/apps\/files\/trashbin\/9$/)
	})
})

// Core's /f/{fileid} finds the file's folder itself. Its /apps/files/files/{fileid}
// lists the root folder when no `dir` comes along, where a file in any other
// folder cannot be found.
describe('fileLink', () => {
	it('goes through core\'s link by id', () => {
		expect(fileLink(42)).toMatch(/\/f\/42\?/)
		expect(fileLink(42)).not.toContain('/apps/files/files/')
	})

	it('opens the details pane, not the file', () => {
		const url = fileLink(42)
		expect(url).toContain('opendetails=true')
		expect(url).toContain('openfile=false')
	})

	it('leaves the folder to the server', () => {
		expect(fileLink(42)).not.toContain('dir=')
	})
})
