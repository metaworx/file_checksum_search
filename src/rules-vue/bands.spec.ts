/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { register, unregister } from '@nextcloud/l10n'
import { afterEach, describe, expect, it } from 'vitest'
import { selectorLabel } from './bands'

const APP = 'file_checksum_search'

describe('selectorLabel', () => {
	afterEach(() => {
		unregister(APP)
	})

	const options = { groupFolderTerm: 'Team-Ordner', groupFolders: [{ id: 2, name: 'Archive' }] }

	// The team folders app's name and the folder's are names; how they join
	// is a text, and a language may order it differently.
	it('joins a team folder\'s scope the way the translation says', () => {
		register(APP, { '{folders}: {folder}': '{folder} – {folders}' })
		expect(selectorLabel('groupfolder:2', options)).toBe('Archive (#2) – Team-Ordner')
	})

	it('names the missing provider by its slug', () => {
		expect(selectorLabel('groupfolder:2', { groupFolders: options.groupFolders })).toBe('app:groupfolders: Archive (#2)')
	})
})
