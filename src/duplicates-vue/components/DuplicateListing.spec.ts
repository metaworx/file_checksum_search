/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What the listing does with a row's menu: a deletion is dry-run, shown,
 * confirmed, reported, and can be undone; a restore is done and reported.
 * The server's side is the actions module's, mocked here.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import DuplicateListing from './DuplicateListing.vue'
import { clickAction } from '../../test-utils/ncActions'
import type { RowResult } from '../duplicateActions'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...await importOriginal<typeof import('@nextcloud/router')>(),
	generateOcsUrl: (url: string) => url,
	generateUrl: (url: string) => url,
}))

vi.mock('../../features', () => ({
	fetchFeatures: () => Promise.resolve({ mimetype: false }),
	mimeTypeSwitchedOff: () => Promise.resolve(false),
}))

const deleteRows = vi.fn()
const restoreRow = vi.fn()
vi.mock('../duplicateActions', async (importOriginal) => ({
	...await importOriginal<typeof import('../duplicateActions')>(),
	deleteRows: (...args: unknown[]) => deleteRows(...args),
	restoreRow: (...args: unknown[]) => restoreRow(...args),
}))

const toastSuccess = vi.fn()
const toastError = vi.fn()
const toastUndo = vi.fn()
vi.mock('../../toast', () => ({
	toastSuccess: (text: string) => toastSuccess(text),
	toastError: (text: string) => toastError(text),
	toastUndo: (text: string, onUndo: () => void) => toastUndo(text, onUndo),
}))

const GROUP = {
	algo: 'sha1',
	hash_value: 'aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d',
	file_count: 2,
	empty: false,
	files: [
		{ fileid: 1, path: '/a.pdf', name: 'a.pdf', actions: ['trash', 'delete', 'mergeInto'] },
		{ fileid: 2, path: 'trash:///b.pdf', name: 'b.pdf', trash: { deletedAt: '2023-11-14T22:13:20+00:00' }, actions: ['delete', 'restore'] },
	],
}

let fetchMock: ReturnType<typeof vi.fn>

async function mounted() {
	const wrapper = mount(DuplicateListing, { props: { algorithmIds: ['sha1'] }, attachTo: document.body })
	await flushPromises()
	// The group's rows show once it is opened.
	await wrapper.find('.db-group-header').trigger('click')
	return wrapper
}

/** The open dialog's button labelled so, as a person finds it. */
function dialogButton(label: string): HTMLButtonElement | undefined {
	return [...document.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')]
		.find((button) => button.textContent?.trim() === label)
}

describe('DuplicateListing, acting on a row', () => {
	beforeEach(() => {
		fetchMock = vi.fn(() => Promise.resolve(new Response(JSON.stringify({ duplicates: [GROUP], canRecalc: true }), { status: 200 })))
		vi.stubGlobal('fetch', fetchMock)
		deleteRows.mockReset()
		restoreRow.mockReset()
		toastSuccess.mockReset()
		toastError.mockReset()
		toastUndo.mockReset()
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		document.body.innerHTML = ''
	})

	// Nothing is deleted on the menu's click: the server is asked what
	// would happen, and the person confirms what it says.
	it('dry-runs a move to the trash, and deletes only once confirmed', async () => {
		const ready: RowResult = { fileid: 1, kind: 'file', status: 'ready', action: 'trash', versions: [] }
		deleteRows.mockResolvedValueOnce([ready]).mockResolvedValueOnce([{ ...ready, status: 'trashed' }])
		const wrapper = await mounted()

		await clickAction(wrapper.findAll('.db-file-item')[0], 'trash')
		await flushPromises()

		expect(deleteRows).toHaveBeenCalledTimes(1)
		expect(deleteRows.mock.calls[0].slice(1)).toEqual([[1], { forGood: false, dryRun: true }])

		dialogButton('Move to trash')?.click()
		await flushPromises()

		expect(deleteRows).toHaveBeenCalledTimes(2)
		expect(deleteRows.mock.calls[1].slice(1)).toEqual([[1], { forGood: false, dryRun: false }])
		expect(toastUndo).toHaveBeenCalledWith('Moved 1 file to the trash.', expect.any(Function))
	})

	// Undo is a restore of what went, to its place.
	it('takes a move to the trash back with Undo', async () => {
		deleteRows
			.mockResolvedValueOnce([{ fileid: 1, kind: 'file', status: 'ready', action: 'trash' }])
			.mockResolvedValueOnce([{ fileid: 1, kind: 'file', status: 'trashed', action: 'trash' }])
		restoreRow.mockResolvedValue({ fileid: 1, kind: 'trash', status: 'restored', restored: { fileid: 1, path: '/a.pdf' } })
		const wrapper = await mounted()

		await clickAction(wrapper.findAll('.db-file-item')[0], 'trash')
		await flushPromises()
		dialogButton('Move to trash')?.click()
		await flushPromises()
		toastUndo.mock.calls[0][1]()
		await flushPromises()

		expect(restoreRow.mock.calls[0].slice(1)).toEqual([1, 'original'])
	})

	it('deletes permanently through its own confirmation', async () => {
		deleteRows
			.mockResolvedValueOnce([{ fileid: 1, kind: 'file', status: 'ready', action: 'delete' }])
			.mockResolvedValueOnce([{ fileid: 1, kind: 'file', status: 'deleted', action: 'delete' }])
		const wrapper = await mounted()

		await clickAction(wrapper.findAll('.db-file-item')[0], 'delete')
		await flushPromises()
		dialogButton('Delete permanently')?.click()
		await flushPromises()

		expect(deleteRows.mock.calls[1].slice(1)).toEqual([[1], { forGood: true, dryRun: false }])
		expect(toastSuccess).toHaveBeenCalledWith('Deleted 1 item permanently.')
		expect(toastUndo).not.toHaveBeenCalled()
	})

	it('restores at once, and says where to', async () => {
		restoreRow.mockResolvedValue({ fileid: 2, kind: 'trash', status: 'restored', restored: { fileid: 2, path: '/b.pdf' } })
		const wrapper = await mounted()

		await clickAction(wrapper.findAll('.db-file-item')[1], 'restore')
		await flushPromises()

		expect(restoreRow.mock.calls[0].slice(1)).toEqual([2, 'original'])
		expect(toastSuccess).toHaveBeenCalledWith('Restored to /b.pdf.')
		expect(deleteRows).not.toHaveBeenCalled()
	})

	it('shows a refusal as the server words it', async () => {
		restoreRow.mockResolvedValue({ fileid: 2, kind: 'trash', status: 'refused', reason: 'It cannot be restored that way.' })
		const wrapper = await mounted()

		await clickAction(wrapper.findAll('.db-file-item')[1], 'restore')
		await flushPromises()

		expect(toastError).toHaveBeenCalledWith('It cannot be restored that way.')
	})
})
