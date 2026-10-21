/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What the listing does with a row's menu: a deletion is dry-run, shown,
 * confirmed, reported, and can be undone; a restore is done and reported.
 * The server's side is the actions module's, mocked here.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
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
const deleteGroups = vi.fn()
const restoreRow = vi.fn()
const restoreRows = vi.fn()
vi.mock('../duplicateActions', async (importOriginal) => ({
	...await importOriginal<typeof import('../duplicateActions')>(),
	deleteRows: (...args: unknown[]) => deleteRows(...args),
	deleteGroups: (...args: unknown[]) => deleteGroups(...args),
	restoreRow: (...args: unknown[]) => restoreRow(...args),
	restoreRows: (...args: unknown[]) => restoreRows(...args),
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
		restoreRows.mockReset()
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
		expect(deleteRows.mock.calls[1].slice(1)).toEqual([[1], { forGood: false, dryRun: false, mergeInto: null }])
		expect(toastUndo).toHaveBeenCalledWith('Moved 1 file to the trash.', expect.any(Function))
	})

	// Undo is a restore of what went, to its place, all of it in one go:
	// a row at a time would meet the rate limit past twenty.
	it('takes a move to the trash back with Undo', async () => {
		deleteRows
			.mockResolvedValueOnce([{ fileid: 1, kind: 'file', status: 'ready', action: 'trash' }])
			.mockResolvedValueOnce([{ fileid: 1, kind: 'file', status: 'trashed', action: 'trash' }])
		restoreRows.mockResolvedValue([{ fileid: 1, kind: 'trash', status: 'restored', restored: { fileid: 1, path: '/a.pdf' } }])
		const wrapper = await mounted()

		await clickAction(wrapper.findAll('.db-file-item')[0], 'trash')
		await flushPromises()
		dialogButton('Move to trash')?.click()
		await flushPromises()
		toastUndo.mock.calls[0][1]()
		await flushPromises()

		expect(restoreRows.mock.calls[0]).toEqual([[{ group: expect.objectContaining({ hash_value: GROUP.hash_value }), fileId: 1 }], 'original'])
		expect(restoreRow).not.toHaveBeenCalled()
		expect(toastError).not.toHaveBeenCalled()
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

		expect(deleteRows.mock.calls[1].slice(1)).toEqual([[1], { forGood: true, dryRun: false, mergeInto: null }])
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

	// Rows chosen in a group: their versions go to the one file that stays,
	// as dry-run before the confirmation, and the deletion carries it.
	it('deletes the rows chosen in a group, their versions into the one file that stays', async () => {
		const live = (fileid: number) => ({ fileid, path: `/${fileid}.pdf`, name: `${fileid}.pdf`, actions: ['trash', 'delete', 'mergeInto'] })
		const group = { ...GROUP, file_count: 3, files: [live(1), live(2), live(3)] }
		fetchMock.mockImplementation(() => Promise.resolve(new Response(JSON.stringify({ duplicates: [group], canRecalc: true }), { status: 200 })))
		const going: RowResult[] = [
			{ fileid: 2, kind: 'file', status: 'ready', action: 'trash', versions: [{ timestamp: 1700000000, size: 3, label: null }] },
			{ fileid: 3, kind: 'file', status: 'ready', action: 'trash', versions: [] },
		]
		deleteRows.mockResolvedValue(going)
		const wrapper = await mounted()

		await clickAction(wrapper.find('.db-group-header'), 'select')
		for (const box of wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).filter((box) => box.classes('db-select-row')).slice(1)) {
			box.vm.$emit('update:modelValue', true)
		}
		await flushPromises()
		await clickAction(wrapper.find('.db-group-header'), 'deleteSelected')
		await flushPromises()

		expect(deleteRows.mock.calls.map((call) => call[2])).toEqual([
			{ forGood: false, dryRun: true },
			{ forGood: false, dryRun: true, mergeInto: 1 },
		])

		dialogButton('Move to trash')?.click()
		await flushPromises()

		expect(deleteRows.mock.calls[2].slice(1)).toEqual([[2, 3], { forGood: false, dryRun: false, mergeInto: 1 }])
	})

	it('dry-runs again when the way changes in the dialog', async () => {
		deleteRows.mockResolvedValue([{ fileid: 1, kind: 'file', status: 'ready', action: 'trash', versions: [] }])
		const wrapper = await mounted()

		await clickAction(wrapper.find('.db-group-header'), 'select')
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).filter((box) => box.classes('db-select-row'))[0].vm.$emit('update:modelValue', true)
		await flushPromises()
		await clickAction(wrapper.find('.db-group-header'), 'deleteSelected')
		await flushPromises()
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((box) => box.props('value') === 'true')?.vm.$emit('update:modelValue', 'true')
		await flushPromises()

		expect(deleteRows.mock.calls.at(-1)?.[2]).toEqual({ forGood: true, dryRun: true, mergeInto: null })
	})

	it('shows a refusal as the server words it', async () => {
		restoreRow.mockResolvedValue({ fileid: 2, kind: 'trash', status: 'refused', reason: 'It cannot be restored that way.' })
		const wrapper = await mounted()

		await clickAction(wrapper.findAll('.db-file-item')[1], 'restore')
		await flushPromises()

		expect(toastError).toHaveBeenCalledWith('It cannot be restored that way.')
	})
})

describe('DuplicateListing, choosing across the page', () => {
	const row = (fileid: number, path: string, more: Record<string, unknown> = {}) => ({ fileid, path, name: path.split('/').pop(), actions: ['trash', 'delete'], ...more })
	const groups = [
		{ ...GROUP, hash_value: 'aa', files: [row(1, '/Backup/a.txt'), row(2, '/Docs/a.txt')] },
		{ ...GROUP, hash_value: 'bb', files: [row(3, '/Docs/b.txt'), row(4, 'versions:///Docs/b.txt', { version: { fileid: 3, timestamp: '2026-10-21T00:00:00+00:00' } })] },
		{ ...GROUP, hash_value: 'cc', files: [row(5, '/Backup/c.txt'), row(6, '/Backup/old/c.txt')] },
	]

	beforeEach(() => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response(JSON.stringify({ duplicates: groups, canRecalc: true }), { status: 200 }))))
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		document.body.innerHTML = ''
	})

	async function selectingPage() {
		const wrapper = mount(DuplicateListing, { props: { algorithmIds: ['sha1'] }, attachTo: document.body })
		await flushPromises()
		await wrapper.find('[data-testid="fcias-start-selecting"]').trigger('click')
		return wrapper
	}

	const chosen = (wrapper: ReturnType<typeof mount>) => wrapper.findAll('[data-testid="fcias-group-selected"]').map((badge) => badge.text())

	// A pattern chooses in every group; one where it matches every copy
	// outside the trash is left alone, and the bar says so (D1-A).
	it('chooses by a path pattern in every group, and leaves alone one it would empty', async () => {
		const wrapper = await selectingPage()

		await wrapper.find('#fcias-duplicates-select-pattern').setValue('/Backup')
		await wrapper.find('[data-testid="fcias-select-matching"]').trigger('click')

		expect(chosen(wrapper)).toEqual(['1 selected'])
		expect(wrapper.find('[data-testid="fcias-selection-count"]').text()).toBe('1 row selected')
		expect(wrapper.findAll('.db-group')[2].find('[data-testid="fcias-group-left-alone"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="fcias-selection-left-alone"]').text()).toBe('1 group left alone, as every copy in it matched')
	})

	// D8-A: Keep one copy off, the pattern takes the group it would empty
	// too; the bar no longer says it left one alone. Cancel puts it back on.
	it('takes a group\'s every copy once Keep one copy is off, and points to it while on', async () => {
		const wrapper = await selectingPage()
		const keepOne = () => wrapper.find('[data-testid="fcias-keep-one"] input')

		await wrapper.find('#fcias-duplicates-select-pattern').setValue('/Backup')
		await wrapper.find('[data-testid="fcias-select-matching"]').trigger('click')
		expect(wrapper.find('[data-testid="fcias-selection-bar"]').text()).toContain('Turn off Keep one copy to select them too.')

		await keepOne().setValue(false)
		await wrapper.find('[data-testid="fcias-select-matching"]').trigger('click')

		expect(chosen(wrapper)).toEqual(['1 selected', '2 selected'])
		expect(wrapper.find('[data-testid="fcias-selection-left-alone"]').exists()).toBe(false)

		await wrapper.find('[data-testid="fcias-stop-selecting"]').trigger('click')
		await wrapper.find('[data-testid="fcias-start-selecting"]').trigger('click')
		expect((keepOne().element as HTMLInputElement).checked).toBe(true)
	})

	it('chooses the versions and lets them go, deselects all, and leaves choosing on Cancel', async () => {
		const wrapper = await selectingPage()

		await wrapper.find('[data-testid="fcias-select-versions"]').trigger('click')
		expect(wrapper.findAll('.db-group')[1].find('[data-testid="fcias-group-selected"]').text()).toBe('1 selected')

		await wrapper.find('[data-testid="fcias-deselect-versions"]').trigger('click')
		expect(chosen(wrapper)).toEqual([])

		await wrapper.find('[data-testid="fcias-select-versions"]').trigger('click')
		await wrapper.find('[data-testid="fcias-deselect-all"]').trigger('click')
		expect(chosen(wrapper)).toEqual([])

		await wrapper.find('[data-testid="fcias-select-versions"]').trigger('click')
		await wrapper.find('[data-testid="fcias-stop-selecting"]').trigger('click')
		expect(wrapper.find('[data-testid="fcias-selection-bar"]').exists()).toBe(false)
		expect(chosen(wrapper)).toEqual([])
	})

	// A pattern chooses and lets go of what it matches.
	it('lets go of what the path matches', async () => {
		const wrapper = await selectingPage()

		await wrapper.find('[data-testid="fcias-select-versions"]').trigger('click')
		await wrapper.find('#fcias-duplicates-select-pattern').setValue('/Backup')
		await wrapper.find('[data-testid="fcias-select-matching"]').trigger('click')
		expect(wrapper.find('[data-testid="fcias-selection-count"]').text()).toBe('2 rows selected')

		await wrapper.find('[data-testid="fcias-deselect-matching"]').trigger('click')
		expect(wrapper.find('[data-testid="fcias-selection-count"]').text()).toBe('1 row selected')
	})

	// D11-C: every duplicate while Keep one copy is on, every row while off;
	// D10-A: the choice turned over, a group it would empty left alone.
	it('selects the duplicates or all, and inverts the choice', async () => {
		const wrapper = await selectingPage()
		const selectAll = () => wrapper.find('[data-testid="fcias-select-all"]')

		expect(selectAll().text()).toBe('Select duplicates')
		await selectAll().trigger('click')
		expect(chosen(wrapper)).toEqual(['1 selected', '1 selected', '1 selected'])

		await wrapper.find('[data-testid="fcias-invert-selection"]').trigger('click')
		expect(chosen(wrapper)).toEqual(['1 selected', '1 selected', '1 selected'])

		await wrapper.find('[data-testid="fcias-keep-one"] input').setValue(false)
		expect(selectAll().text()).toBe('Select all')
		await selectAll().trigger('click')
		expect(wrapper.find('[data-testid="fcias-selection-count"]').text()).toBe('6 rows selected')
	})

	// D9-A: turned back on, the switch lets go of every group chosen whole.
	it('lets go of the groups chosen whole when Keep one copy is turned on again', async () => {
		const wrapper = await selectingPage()
		const keepOne = () => wrapper.find('[data-testid="fcias-keep-one"] input')

		await keepOne().setValue(false)
		await wrapper.find('#fcias-duplicates-select-pattern').setValue('/Backup')
		await wrapper.find('[data-testid="fcias-select-matching"]').trigger('click')
		expect(chosen(wrapper)).toEqual(['1 selected', '2 selected'])

		await keepOne().setValue(true)

		expect(chosen(wrapper)).toEqual(['1 selected'])
		expect(wrapper.findAll('.db-group')[2].find('[data-testid="fcias-group-left-alone"]').exists()).toBe(true)
	})

	// The page's choice goes in one dry run: each group's rows, its versions
	// to the copy that stays (D2-A), the sum shown; confirmed, the same rows
	// go, and Undo brings back what went to the trash, every group's at once.
	it('deletes the page\'s choice through one confirmation, and takes it back with Undo', async () => {
		const answer = (rows: Array<{ algo: string, hash_value: string, fileIds: number[] }>, status: string) => rows.map((row) => ({
			algo: row.algo,
			hash_value: row.hash_value,
			results: row.fileIds.map((fileid) => ({ fileid, kind: 'file', status, action: 'trash', versions: [] })),
		}))
		deleteGroups.mockReset()
		deleteGroups.mockImplementation((rows, options: { dryRun: boolean }) => Promise.resolve(answer(rows, options.dryRun ? 'ready' : 'trashed')))
		restoreRows.mockReset()
		restoreRows.mockResolvedValue([])
		toastUndo.mockReset()
		const wrapper = await selectingPage()

		// The versions, then /Docs, which leaves bb alone: it would keep a
		// version only. Its file is then chosen by hand, which is allowed.
		await wrapper.find('[data-testid="fcias-select-versions"]').trigger('click')
		await wrapper.find('#fcias-duplicates-select-pattern').setValue('/Docs')
		await wrapper.find('[data-testid="fcias-select-matching"]').trigger('click')
		wrapper.findAll('.db-group')[1].findAllComponents({ name: 'NcCheckboxRadioSwitch' }).filter((box: VueWrapper) => box.classes('db-select-row'))[0].vm.$emit('update:modelValue', true)
		await flushPromises()
		await wrapper.find('[data-testid="fcias-delete-page"]').trigger('click')
		await flushPromises()

		const [rows, options] = deleteGroups.mock.calls[0]
		expect(options).toEqual({ forGood: false, dryRun: true })
		// The version goes before its file; group aa keeps 1, which takes 2's versions.
		expect(rows).toEqual([
			{ algo: 'sha1', hash_value: 'aa', fileIds: [2], mergeInto: null },
			{ algo: 'sha1', hash_value: 'bb', fileIds: [4, 3], mergeInto: null },
		])
		expect(document.querySelector('[data-testid="fcias-page-delete-summary"]')?.textContent).toContain('3 rows go to the trash.')
		expect(document.querySelector('[data-testid="fcias-page-delete-emptied"]')?.textContent).toContain('In 1 group no copy stays outside the trash.')

		dialogButton('Move to trash')?.click()
		await flushPromises()

		expect(deleteGroups.mock.calls[1][1]).toEqual({ forGood: false, dryRun: false })
		toastUndo.mock.calls[0][1]()
		await flushPromises()
		expect(restoreRows.mock.calls[0][0].map((row: { fileId: number }) => row.fileId)).toEqual([2, 4, 3])
	})

	// D2-A: the copy that stays takes the versions — the most recently
	// changed where several do — unless the confirmation says None.
	it('puts the versions into the most recently changed copy that stays, or none', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response(JSON.stringify({
			duplicates: [{ ...GROUP, hash_value: 'dd', files: [row(7, '/Backup/d.txt'), row(8, '/Docs/d.txt', { mtime: 100, actions: ['trash', 'delete', 'mergeInto'] }), row(9, '/Docs/old/d.txt', { mtime: 200, actions: ['trash', 'delete', 'mergeInto'] })] }],
			canRecalc: true,
		}), { status: 200 }))))
		deleteGroups.mockReset()
		deleteGroups.mockResolvedValue([])
		const wrapper = await selectingPage()

		await wrapper.find('#fcias-duplicates-select-pattern').setValue('/Backup')
		await wrapper.find('[data-testid="fcias-select-matching"]').trigger('click')
		await wrapper.find('[data-testid="fcias-delete-page"]').trigger('click')
		await flushPromises()

		expect(deleteGroups.mock.calls[0][0]).toEqual([{ algo: 'sha1', hash_value: 'dd', fileIds: [7], mergeInto: 9 }])

		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((box) => box.props('name') === 'pd-keep' && box.props('value') === 'false')?.vm.$emit('update:modelValue', 'false')
		await flushPromises()

		expect(deleteGroups.mock.calls[1][0]).toEqual([{ algo: 'sha1', hash_value: 'dd', fileIds: [7], mergeInto: null }])
	})

	// A file listed under two algorithms is one row to delete, sent once.
	it('sends a file listed in two groups once', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response(JSON.stringify({
			duplicates: [
				{ ...GROUP, algo: 'sha1', hash_value: 'ee', files: [row(1, '/Backup/e.txt'), row(2, '/Docs/e.txt')] },
				{ ...GROUP, algo: 'md5', hash_value: 'ff', files: [row(1, '/Backup/e.txt'), row(2, '/Docs/e.txt')] },
			],
			canRecalc: true,
		}), { status: 200 }))))
		deleteGroups.mockReset()
		deleteGroups.mockResolvedValue([])
		const wrapper = await selectingPage()

		await wrapper.find('#fcias-duplicates-select-pattern').setValue('/Backup')
		await wrapper.find('[data-testid="fcias-select-matching"]').trigger('click')
		await wrapper.find('[data-testid="fcias-delete-page"]').trigger('click')
		await flushPromises()

		expect(deleteGroups.mock.calls[0][0]).toEqual([{ algo: 'sha1', hash_value: 'ee', fileIds: [1], mergeInto: null }])
	})

	// The Others tab shows files that are not the viewer's: nothing is chosen there.
	it('offers no choosing on another account\'s listing', async () => {
		const wrapper = mount(DuplicateListing, { props: { algorithmIds: ['sha1'], scope: { all: true, users: [], groups: [] } }, attachTo: document.body })
		await flushPromises()

		expect(wrapper.find('[data-testid="fcias-start-selecting"]').exists()).toBe(false)
	})
})

describe('DuplicateListing, loading more', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
		document.body.innerHTML = ''
	})

	// The next groups are added under those shown, from where the server
	// said; the button goes once there are no more.
	it('adds the next groups under those shown, and offers no more at the end', async () => {
		const second = { ...GROUP, hash_value: 'bbf4c61ddcc5e8a2dabede0f3b482cd9aea9434d' }
		const fetchMock = vi.fn((url: string) => Promise.resolve(new Response(JSON.stringify(url.includes('offset=0')
			? { duplicates: [GROUP], pagination: { next_offset: 4 }, canRecalc: true }
			: { duplicates: [second], pagination: { next_offset: null }, canRecalc: true }), { status: 200 })))
		vi.stubGlobal('fetch', fetchMock)

		const wrapper = mount(DuplicateListing, { props: { algorithmIds: ['sha1'] }, attachTo: document.body })
		await flushPromises()

		await wrapper.find('[data-testid="fcias-load-more"]').trigger('click')
		await flushPromises()

		expect(String(fetchMock.mock.calls.at(-1)?.[0])).toContain('offset=4')
		expect(wrapper.findAll('.db-group')).toHaveLength(2)
		expect(wrapper.find('[data-testid="fcias-load-more"]').exists()).toBe(false)
	})
})
