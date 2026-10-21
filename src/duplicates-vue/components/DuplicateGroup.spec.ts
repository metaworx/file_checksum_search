/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import DuplicateGroup from './DuplicateGroup.vue'
import type { DuplicateGroup as GroupType } from '../composables/useDuplicates'
import { clickAction, hasAction, openActions } from '../../test-utils/ncActions'
import { formatDateTime } from '../../l10n'

function group(extra: Partial<GroupType> = {}): GroupType {
	return {
		algo: 'sha1',
		hash_value: 'aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d',
		file_count: 2,
		files: [
			{ fileid: 1, path: '/a.pdf', name: 'a.pdf' },
			{ fileid: 2, path: '/b.jpg', name: 'b.jpg' },
		],
		...extra,
	}
}

// Attached: an NcActions menu opens in the document's body.
function mounted(extra: Partial<GroupType> = {}, props: Record<string, unknown> = {}) {
	return mount(DuplicateGroup, {
		props: { group: group(extra), fileUrl: () => '#', ...props },
		attachTo: document.body,
	})
}

/** The group's header, where its own menu is. */
function header(wrapper: VueWrapper) {
	return wrapper.find('.db-group-header')
}

/** Whether the entry of the open menu is there and waits. */
async function disabled(wrapper: Parameters<typeof openActions>[0], action: string): Promise<boolean | undefined> {
	const menu = await openActions(wrapper)
	return menu.querySelector<HTMLButtonElement>(`[data-action="${action}"] button`)?.disabled
}

afterEach(() => {
	document.body.innerHTML = ''
})

describe('DuplicateGroup, a version', () => {
	const files = [
		{ fileid: 1, path: '/a.pdf', name: 'a.pdf' },
		{ fileid: 3, path: 'versions:///b.pdf', name: 'b.pdf', version: { fileid: 2, timestamp: '2023-11-14T22:13:20+00:00' } },
	]

	// Its label is its file's; a badge says it is a version, its title which.
	it('is labelled as its file is, with a Version badge saying which version it is', () => {
		const items = mounted({ files }).findAll('.db-file-item')
		const badge = items[1].find('[data-testid="fcias-version"]')

		expect(items[1].find('a').text()).toBe('/b.pdf')
		expect(badge.text()).toBe('Version')
		expect(badge.attributes('title')).toMatch(/^Version of /)
		expect(items[0].find('[data-testid="fcias-version"]').exists()).toBe(false)
	})

	it('is offered no verification of its own', async () => {
		const items = mounted({ files }).findAll('.db-file-item')

		expect(await hasAction(items[0], 'verify')).toBe(true)
		expect(await hasAction(items[1], 'verify')).toBe(false)
	})

	// The group's verification reads no version, so a group of versions
	// only has nothing to verify.
	it('leaves a group of versions only without Verify all', async () => {
		const versions = [
			files[1],
			{ fileid: 5, path: 'versions:///x/b.pdf', name: 'b.pdf', version: { fileid: 4, timestamp: '2023-11-14T22:13:20+00:00' } },
		]

		expect(await hasAction(header(mounted({ files: versions })), 'verifyAll')).toBe(false)
		expect(await hasAction(header(mounted({ files })), 'verifyAll')).toBe(true)
	})
})

describe('DuplicateGroup, a file in the trash', () => {
	const files = [
		{ fileid: 1, path: '/a.pdf', name: 'a.pdf' },
		{ fileid: 2, path: '/b.pdf', name: 'b.pdf' },
		{ fileid: 3, path: 'trash:///c.pdf', name: 'c.pdf', trash: { deletedAt: '2023-11-14T22:13:20+00:00' } },
	]

	it('is labelled as it was, and says when it was deleted', () => {
		const items = mounted({ files }).findAll('.db-file-item')

		expect(items[2].find('a').text()).toBe('/c.pdf')
		expect(items[2].find('[data-testid="fcias-trash"]').text()).toMatch(/^Deleted /)
		expect(items[0].find('[data-testid="fcias-trash"]').exists()).toBe(false)
	})

	it('is offered no verification', async () => {
		const items = mounted({ files }).findAll('.db-file-item')

		expect(await hasAction(items[0], 'verify')).toBe(true)
		expect(await hasAction(items[2], 'verify')).toBe(false)
	})
})

describe('DuplicateGroup, Verify', () => {
	it('verifies the group and a row through their menus, and waits while a verification runs', async () => {
		const wrapper = mounted()

		await clickAction(header(wrapper), 'verifyAll')
		await clickAction(wrapper.findAll('.db-file-item')[1], 'verify')

		expect(wrapper.emitted('verifyGroup')).toHaveLength(1)
		expect(wrapper.emitted('verifyFile')?.[0]?.[1]).toMatchObject({ fileid: 2 })

		await wrapper.setProps({ verifying: true })
		expect(await disabled(header(wrapper), 'verifyAll')).toBe(true)
	})

	// A menu that would only hold refusals is no menu.
	it('offers no Verify to an account that may not recalculate', async () => {
		const wrapper = mounted({}, { canVerify: false })

		expect(header(wrapper).find('.action-item__menutoggle').exists()).toBe(false)
		expect(wrapper.find('.db-file-actions').exists()).toBe(false)
	})
})

describe('DuplicateGroup, the header', () => {
	// One per group: its files share their content, and so its type.
	it('shows the type of the group\'s content after the hash', () => {
		const badge = mounted({ mimetype: 'application/pdf' }).find('.db-group-header [data-testid="fcias-group-mimetype"]')

		expect(badge.text()).toBe('application/pdf')
		expect(badge.attributes('title')).toBe('MIME type, detected from the content')
	})

	it('shows no type for a group without one, or while types are not asked for', () => {
		expect(mounted({ mimetype: null }).find('[data-testid="fcias-group-mimetype"]').exists()).toBe(false)
		expect(mounted().find('[data-testid="fcias-group-mimetype"]').exists()).toBe(false)
	})

	// Groups of the same rows merged on the page: a line for each pair,
	// the type once, on the first.
	it('shows each algorithm and hash of a merged group on a line of its own', () => {
		const hashes = [{ algo: 'sha1', hash_value: 'aaf4c61d' }, { algo: 'md5', hash_value: '5d41402a' }]
		const wrapper = mounted({ algo: 'sha1', hash_value: 'aaf4c61d', hashes, mimetype: 'text/plain' })

		expect(wrapper.findAll('[data-testid="fcias-group-algo"]').map((cell) => cell.text())).toEqual(['SHA1', 'MD5'])
		expect(wrapper.findAll('[data-testid="fcias-group-hash"]').map((cell) => cell.text())).toEqual(['aaf4c61d', '5d41402a'])
		expect(wrapper.findAll('[data-testid="fcias-group-mimetype"]')).toHaveLength(1)
		expect(mounted().findAll('[data-testid="fcias-group-hash"]')).toHaveLength(1)
	})

	// The files share their content, and so their size: once, under the count.
	it('shows the count, and each file\'s size under it', () => {
		const files = [
			{ fileid: 1, path: '/a.pdf', name: 'a.pdf', mtime: 1700000000, size: 2048 },
			{ fileid: 2, path: '/b.pdf', name: 'b.pdf', mtime: 1700000500, size: 2048 },
		]
		const wrapper = mounted({ files })

		expect(wrapper.find('[data-testid="fcias-group-count"]').text()).toBe('2 files')
		expect(wrapper.find('[data-testid="fcias-group-size"]').text()).toBe('à 2 KB')
		expect(mounted().find('[data-testid="fcias-group-size"]').exists()).toBe(false)
	})

	// A version's date is when its file had that content, not when the copy was written.
	it('dates a file by its last change and a version by its own time', () => {
		const files = [
			{ fileid: 1, path: '/a.pdf', name: 'a.pdf', mtime: 1700000000, size: 2048 },
			{ fileid: 3, path: 'versions:///b.pdf', name: 'b.pdf', mtime: 1700000500, size: 2048, version: { fileid: 2, timestamp: '2023-11-14T22:13:20+00:00' } },
			{ fileid: 4, path: '/c.pdf', name: 'c.pdf', size: 2048 },
		]
		const items = mounted({ files }).findAll('.db-file-item')
		const dates = items.map((item) => item.find('[data-testid="fcias-row-date"]'))

		expect(dates[0].text()).toBe(formatDateTime(new Date(1700000000 * 1000)))
		expect(dates[0].attributes('title')).toMatch(/^Last changed /)
		expect(dates[1].text()).toBe(formatDateTime(new Date('2023-11-14T22:13:20+00:00')))
		expect(dates[1].attributes('title')).toMatch(/^Version of /)
		expect(dates[2].exists()).toBe(false)
	})
})

describe('DuplicateGroup, the preview', () => {
	const files = [
		{ fileid: 1, path: '/a.pdf', name: 'a.pdf' },
		{ fileid: 3, path: 'versions:///b.pdf', name: 'b.pdf', version: { fileid: 2, timestamp: '2023-11-14T22:13:20+00:00' } },
	]

	// One thumbnail: a shared hash is shared content.
	it('shows one preview, and a file\'s glyph once it fails to load', async () => {
		const wrapper = mounted({ files })
		const previews = wrapper.findAll('[data-testid="fcias-group-preview"]')

		expect(previews).toHaveLength(1)
		expect(previews[0].attributes('src')).toMatch(/\/core\/preview\?fileId=1&/)

		await previews[0].trigger('error')

		expect(wrapper.find('[data-testid="fcias-group-preview"]').exists()).toBe(false)
		expect(wrapper.find('.db-preview-none').exists()).toBe(true)
	})

	it('shows a file\'s glyph for a group of versions only', () => {
		const wrapper = mounted({ files: [files[1]] })

		expect(wrapper.find('[data-testid="fcias-group-preview"]').exists()).toBe(false)
		expect(wrapper.find('.db-preview-none').exists()).toBe(true)
	})
})

describe('DuplicateGroup, a row\'s menu', () => {
	const files: GroupType['files'] = [
		{ fileid: 1, path: '/a.pdf', name: 'a.pdf', actions: ['trash', 'delete', 'mergeInto'] },
		{ fileid: 3, path: 'trash:///b.pdf', name: 'b.pdf', trash: { deletedAt: '2023-11-14T22:13:20+00:00' }, actions: ['delete', 'restore'] },
		{ fileid: 4, path: 'versions:///c.pdf', name: 'c.pdf', version: { fileid: 9, timestamp: '2023-11-14T22:13:20+00:00' }, actions: [] },
	]

	function acting(canAct: boolean) {
		return mounted({ files }, { canAct })
	}

	// What the server said the row allows, and nothing that would only be
	// refused; taking another's versions is no entry of its own.
	it('offers what each row allows, Verify first where the row is read', async () => {
		const items = acting(true).findAll('.db-file-item')

		expect(await hasAction(items[0], 'verify')).toBe(true)
		expect(await hasAction(items[0], 'trash')).toBe(true)
		expect(await hasAction(items[0], 'delete')).toBe(true)
		expect(await hasAction(items[0], 'mergeInto')).toBe(false)
		expect(await hasAction(items[0], 'restore')).toBe(false)
		expect(await hasAction(items[1], 'restore')).toBe(true)
		expect(await hasAction(items[1], 'trash')).toBe(false)
		expect(items[2].find('.db-file-actions').exists()).toBe(false)
	})

	it('offers no action on a listing the page does not act on, Verify alone', async () => {
		const items = acting(false).findAll('.db-file-item')

		expect(await hasAction(items[0], 'verify')).toBe(true)
		expect(await hasAction(items[0], 'trash')).toBe(false)
		expect(items[1].find('.db-file-actions').exists()).toBe(false)
	})

	it('says which row and which action was chosen', async () => {
		const wrapper = acting(true)

		await clickAction(wrapper.findAll('.db-file-item')[1], 'restore')

		expect(wrapper.emitted('act')?.[0]?.slice(1)).toEqual([files[1], 'restore'])
	})
})

describe('DuplicateGroup, choosing rows to delete', () => {
	const files: GroupType['files'] = [
		{ fileid: 1, path: '/a.pdf', name: 'a.pdf', actions: ['trash', 'delete', 'mergeInto'] },
		{ fileid: 2, path: '/b.pdf', name: 'b.pdf', actions: ['trash', 'delete', 'mergeInto'] },
		{ fileid: 3, path: 'trash:///c.pdf', name: 'c.pdf', trash: { deletedAt: '2023-11-14T22:13:20+00:00' }, actions: ['delete', 'restore'] },
		{ fileid: 4, path: '/d.pdf', name: 'd.pdf', actions: [] },
	]

	/**
	 * The group as the listing holds it: the page keeps the choice, and
	 * what the group asks for comes back to it as props.
	 */
	function choosing(canAct = true) {
		const wrapper: VueWrapper = mounted({ files }, {
			canAct,
			onStartSelecting: () => wrapper.setProps({ selecting: true }),
			onStopSelecting: () => wrapper.setProps({ selecting: false, selected: [] }),
			'onUpdate:selected': (_group: GroupType, fileIds: number[]) => wrapper.setProps({ selected: fileIds }),
			onChoose: (_group: GroupType, fileId: number, chosen: boolean) => {
				const now = (wrapper.props() as { selected: number[] }).selected
				return wrapper.setProps({ selected: chosen ? [...now, fileId] : now.filter((id) => id !== fileId) })
			},
		})
		return wrapper
	}

	/** The rows' checkboxes, by the row's file id: Nextcloud's own, driven as the app's other specs drive it. */
	function boxes(wrapper: VueWrapper) {
		const map = new Map<number, { check: (on: boolean) => Promise<void> }>()
		wrapper.findAll('.db-file-item').forEach((item, index) => {
			const box = item.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[0]
			if (box) {
				map.set(files[index].fileid, {
					check: async (on) => {
						box.vm.$emit('update:modelValue', on)
						await flushPromises()
					},
				})
			}
		})
		return map
	}

	// Select opens a closed group: the choosing is done in its rows.
	it('offers a checkbox on each row that may be deleted, once Select is chosen, the group opened', async () => {
		const wrapper = choosing()

		expect(wrapper.find('[data-testid="fcias-select-row"]').exists()).toBe(false)
		expect(wrapper.classes()).not.toContain('open')
		await clickAction(header(wrapper), 'select')

		expect(wrapper.classes()).toContain('open')
		expect([...boxes(wrapper).keys()]).toEqual([1, 2, 3])
		expect(await hasAction(header(wrapper), 'select')).toBe(false)
	})

	it('offers no choosing on a listing the page does not act on', async () => {
		expect(await hasAction(header(choosing(false)), 'select')).toBe(false)
	})

	// All, then the other lot: Invert of all is none, and Delete selected waits.
	it('chooses all, inverts the choice, and deletes what is chosen', async () => {
		const wrapper = choosing()
		await clickAction(header(wrapper), 'select')

		await boxes(wrapper).get(2)?.check(true)
		await clickAction(header(wrapper), 'invertSelection')
		await clickAction(header(wrapper), 'deleteSelected')
		expect(wrapper.emitted('deleteSelected')?.[0]?.[1]).toEqual([1, 3])

		await clickAction(header(wrapper), 'selectAll')
		await clickAction(header(wrapper), 'deleteSelected')
		expect(wrapper.emitted('deleteSelected')?.[1]?.[1]).toEqual([1, 2, 3])

		await clickAction(header(wrapper), 'invertSelection')
		expect(await disabled(header(wrapper), 'deleteSelected')).toBe(true)
	})

	it('leaves choosing, and forgets the choice, on Cancel', async () => {
		const wrapper = choosing()
		await clickAction(header(wrapper), 'select')
		await clickAction(header(wrapper), 'selectAll')

		await clickAction(header(wrapper), 'cancelSelection')
		expect(wrapper.find('[data-testid="fcias-select-row"]').exists()).toBe(false)

		await clickAction(header(wrapper), 'select')
		expect(await disabled(header(wrapper), 'deleteSelected')).toBe(true)
	})

	// The page holds the choice: a group shows what is chosen in it, closed or open.
	it('says on its header how many of its rows are chosen, and where a rule left it alone', async () => {
		const wrapper = mounted({ files }, { canAct: true, selecting: true, selected: [1, 2] })

		expect(wrapper.find('[data-testid="fcias-group-selected"]').text()).toBe('2 selected')
		expect(wrapper.find('[data-testid="fcias-group-left-alone"]').exists()).toBe(false)

		await wrapper.setProps({ selected: [], leftAlone: true })

		expect(wrapper.find('[data-testid="fcias-group-selected"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="fcias-group-left-alone"]').attributes('title')).toMatch(/every copy outside the trash/)
	})

	// A chosen row stands out in the tint Files gives a selected one.
	it('marks the chosen rows', async () => {
		const wrapper = mounted({ files }, { canAct: true, selecting: true, selected: [2] })
		const marked = () => wrapper.findAll('.db-file-item').map((item) => item.classes('db-file-selected'))

		expect(marked()).toEqual([false, true, false, false])

		await wrapper.setProps({ selecting: false })
		expect(marked()).toEqual([false, false, false, false])
	})
})
