/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import DeleteDialog from './DeleteDialog.vue'
import type { DuplicateGroup as GroupType } from '../composables/useDuplicates'
import type { RowResult } from '../duplicateActions'

const GROUP: GroupType = {
	algo: 'sha1',
	hash_value: 'aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d',
	file_count: 3,
	files: [
		{ fileid: 1, path: '/a.pdf', name: 'a.pdf' },
		{ fileid: 2, path: '/b.pdf', name: 'b.pdf' },
		{ fileid: 3, path: 'versions:///c.pdf', name: 'c.pdf', version: { fileid: 9, timestamp: '2023-11-14T22:13:20+00:00' } },
	],
}

const TO_TRASH: RowResult = {
	fileid: 1,
	kind: 'file',
	status: 'ready',
	action: 'trash',
	versions: [
		{ timestamp: 1700000000, size: 2048, label: 'Final' },
		{ timestamp: 1690000000, size: 1024, label: null },
	],
}

const REFUSED: RowResult = { fileid: 2, kind: 'file', status: 'refused', reason: 'You may not delete it.' }

const VERSION: RowResult = { fileid: 3, kind: 'version', status: 'ready', action: 'delete' }

async function opened(results: RowResult[], forGood = false) {
	const wrapper = mount(DeleteDialog, { props: { group: GROUP, results, forGood }, attachTo: document.body })
	await flushPromises()
	return wrapper
}

/** The open dialog's button labelled so, as a person finds it. */
function dialogButton(label: string): HTMLButtonElement | undefined {
	return [...document.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')]
		.find((button) => button.textContent?.trim() === label)
}

describe('DeleteDialog', () => {
	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('lists each row with what would happen to it, and why one would not', async () => {
		await opened([TO_TRASH, REFUSED])

		const rows = [...document.querySelectorAll('[data-testid="fcias-delete-rows"] > li')]

		expect(rows.map((row) => row.getAttribute('data-status'))).toEqual(['ready', 'refused'])
		expect(rows[0].textContent).toContain('/a.pdf')
		expect(rows[0].textContent).toContain('Move to trash')
		expect(rows[1].textContent).toContain('You may not delete it.')
	})

	// The versions that go along with a file are said before it goes, a
	// named one by its name.
	it('lists the earlier versions that go with a file, named ones by name', async () => {
		await opened([TO_TRASH])

		const versions = [...document.querySelectorAll('[data-testid="fcias-going-version"]')]

		expect(document.body.textContent).toContain('It takes 2 earlier versions with it.')
		expect(versions).toHaveLength(2)
		expect(versions[0].querySelector('strong')?.textContent).toBe('Final')
		expect(versions[1].querySelector('strong')).toBeNull()
	})

	it('warns where something cannot be undone, and only there', async () => {
		await opened([TO_TRASH])
		expect(document.querySelector('[data-testid="fcias-delete-permanent"]')).toBeNull()
		document.body.innerHTML = ''

		// A version has no trash to go to.
		await opened([TO_TRASH, VERSION])
		expect(document.querySelector('[data-testid="fcias-delete-permanent"]')?.textContent).toContain('This cannot be undone.')
	})

	it('confirms with the button its title names, and only for a row it would act on', async () => {
		const wrapper = await opened([TO_TRASH], true)

		expect(document.querySelector('[role="dialog"] h2')?.textContent?.trim()).toBe('Delete permanently')
		dialogButton('Delete permanently')?.click()
		await flushPromises()
		expect(wrapper.emitted('confirm')).toHaveLength(1)
		document.body.innerHTML = ''

		await opened([REFUSED])
		expect(dialogButton('Move to trash')?.disabled).toBe(true)
	})
})
