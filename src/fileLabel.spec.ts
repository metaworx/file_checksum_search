/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it } from 'vitest'
import { currentUid, fileLabel, labelKind } from './fileLabel'

describe('fileLabel', () => {
	afterEach(() => {
		delete document.head.dataset.user
	})

	const mine = { path: '/Templates/Certificate.odt', name: 'Certificate.odt', owner: 'alice', location: '/alice/files/Templates/Certificate.odt' }

	it('keeps the path the viewer knows for their own file', () => {
		expect(fileLabel(mine, 'alice')).toBe('/Templates/Certificate.odt')
	})

	// The case the field exists for: three accounts' copies of one template
	// all read `/Templates/Certificate.odt`, and only the location says whose.
	// The API's path is the filecache's, `files/Documents/a.txt`; the Files
	// app never shows that first segment, and this list did.
	it('drops the filecache\'s files/ segment from the viewer\'s own path', () => {
		const row = { path: 'files/Documents/a.txt', name: 'a.txt', owner: 'alice', location: '/alice/files/Documents/a.txt' }
		expect(fileLabel(row, 'alice')).toBe('Documents/a.txt')
		expect(fileLabel({ ...row, path: 'files/a.txt' }, 'alice')).toBe('a.txt')
		// Only that segment, and only at the start: a folder called files is a folder.
		expect(fileLabel({ ...row, path: 'Documents/files/a.txt' }, 'alice')).toBe('Documents/files/a.txt')
		// Somebody else's row keeps its location, whatever its path says.
		expect(fileLabel(row, 'bob')).toBe('/alice/files/Documents/a.txt')
	})

	it('shows where the file lives when it is somebody else\'s', () => {
		expect(fileLabel(mine, 'bob')).toBe('/alice/files/Templates/Certificate.odt')
	})

	// A group folder or an external storage has no owner, so it is nobody's
	// own — not even the viewer's — and says where it is.
	it('shows the location of a file that has no owner', () => {
		const shared = { path: '/Team Docs/plan.md', owner: null, location: 'groupfolder:3/plan.md' }

		expect(fileLabel(shared, 'alice')).toBe('groupfolder:3/plan.md')
	})

	// An older server, or a row from a route that does not say: the path is
	// all there is, and it is what was shown before.
	it('falls back to the path, then the name, when no location came', () => {
		expect(fileLabel({ path: '/a.txt', name: 'a.txt' }, 'alice')).toBe('/a.txt')
		expect(fileLabel({ name: 'a.txt' }, 'alice')).toBe('a.txt')
		expect(fileLabel({}, 'alice')).toBe('')
	})

	it('reads the viewer from the page when not told', () => {
		document.head.dataset.user = 'alice'

		expect(currentUid()).toBe('alice')
		expect(fileLabel(mine)).toBe('/Templates/Certificate.odt')

		document.head.dataset.user = 'bob'

		expect(fileLabel(mine)).toBe('/alice/files/Templates/Certificate.odt')
	})

	it('knows no viewer on a page that names none', () => {
		expect(currentUid()).toBeNull()
		expect(fileLabel(mine)).toBe('/alice/files/Templates/Certificate.odt')
	})
})

// The glyph before a label: a house for the viewer's own file, and for
// anyone else's the kind of place its location names, by the prefix.
describe('labelKind', () => {
	const mine = { path: '/Templates/Certificate.odt', owner: 'alice', location: '/alice/files/Templates/Certificate.odt' }

	it('is a house for the viewer\'s own file, whose label is its path', () => {
		expect(labelKind(mine, 'alice')).toBe('own')
	})

	it('reads a home, a group folder and a storage off the location', () => {
		expect(labelKind(mine, 'bob')).toBe('home')
		expect(labelKind({ path: '/plan.md', owner: null, location: 'groupfolder:3/plan.md' }, 'bob')).toBe('groupfolder')
		expect(labelKind({ path: '/x.bin', owner: null, location: 'storage:7/x.bin' }, 'bob')).toBe('storage')
	})

	it('is nothing for a row that says neither owner nor location, or a location of a shape it does not know', () => {
		expect(labelKind({ path: '/a.txt' }, 'bob')).toBeNull()
		expect(labelKind({ path: '/a.txt', owner: null, location: 'elsewhere' }, 'bob')).toBeNull()
	})
})
