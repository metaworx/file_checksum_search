/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { fileLink, rowLink } from './fileLink'

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
