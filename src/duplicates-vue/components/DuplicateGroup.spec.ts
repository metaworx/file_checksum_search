/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import DuplicateGroup from './DuplicateGroup.vue'
import type { DuplicateGroup as GroupType } from '../composables/useDuplicates'
import { clickAction, hasAction } from '../../test-utils/ncActions'

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

function mounted(extra: Partial<GroupType> = {}) {
	return mount(DuplicateGroup, {
		props: { group: group(extra), fileUrl: () => '#' },
	})
}

describe('DuplicateGroup, a version', () => {
	const files = [
		{ fileid: 1, path: '/a.pdf', name: 'a.pdf' },
		{ fileid: 3, path: 'versions:///b.pdf', name: 'b.pdf', version: { fileid: 2, timestamp: '2023-11-14T22:13:20+00:00' } },
	]

	it('is labelled as its file is, and says which version it is', () => {
		const items = mounted({ files }).findAll('.db-file-item')

		expect(items[1].find('a').text()).toBe('/b.pdf')
		expect(items[1].find('[data-testid="fcias-version"]').text()).toMatch(/^Version of /)
		expect(items[0].find('[data-testid="fcias-version"]').exists()).toBe(false)
	})

	it('is offered no verification of its own', () => {
		const items = mounted({ files }).findAll('.db-file-item')

		expect(items[0].find('.db-verify-file').exists()).toBe(true)
		expect(items[1].find('.db-verify-file').exists()).toBe(false)
	})

	// The group's verification reads no version, so a group of versions
	// only has nothing to verify.
	it('leaves a group of versions only without Verify all', () => {
		const versions = [
			files[1],
			{ fileid: 5, path: 'versions:///x/b.pdf', name: 'b.pdf', version: { fileid: 4, timestamp: '2023-11-14T22:13:20+00:00' } },
		]

		expect(mounted({ files: versions }).find('.db-verify-all').exists()).toBe(false)
		expect(mounted({ files }).find('.db-verify-all').exists()).toBe(true)
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

	it('is offered no verification', () => {
		const items = mounted({ files }).findAll('.db-file-item')

		expect(items[0].find('.db-verify-file').exists()).toBe(true)
		expect(items[2].find('.db-verify-file').exists()).toBe(false)
	})
})

describe('DuplicateGroup, the MIME type', () => {
	// One per group: its files share their content, and so its type.
	it('shows the type of the group\'s content on its header, after the hash', () => {
		const badge = mounted({ mimetype: 'application/pdf' }).find('.db-group-header [data-testid="fcias-group-mimetype"]')

		expect(badge.text()).toBe('application/pdf')
		expect(badge.attributes('title')).toBe('MIME type, detected from the content')
	})

	it('shows nothing for a group without one, or while types are not asked for', () => {
		expect(mounted({ mimetype: null }).find('[data-testid="fcias-group-mimetype"]').exists()).toBe(false)
		expect(mounted().find('[data-testid="fcias-group-mimetype"]').exists()).toBe(false)
	})
})

describe('DuplicateGroup, choosing between copies', () => {
	const files = [
		{ fileid: 1, path: '/a.pdf', name: 'a.pdf', mtime: 1700000000, size: 2048 },
		{ fileid: 3, path: 'versions:///b.pdf', name: 'b.pdf', mtime: 1700000500, size: 2048, version: { fileid: 2, timestamp: '2023-11-14T22:13:20+00:00' } },
	]

	it('shows each row\'s size, and the date a version\'s label does not already carry', () => {
		const metas = mounted({ files }).findAll('.db-file-item').map((item) => item.find('[data-testid="fcias-file-meta"]').text())

		expect(metas[0]).toMatch(/^2 KB · \S/)
		expect(metas[1]).toBe('2 KB')
	})

	it('shows nothing for a row the server gave neither', () => {
		expect(mounted().find('[data-testid="fcias-file-meta"]').exists()).toBe(false)
	})

	// One thumbnail: a shared hash is shared content.
	it('shows one preview on the header, and hides it when it fails to load', async () => {
		const wrapper = mounted({ files })
		const previews = wrapper.findAll('[data-testid="fcias-group-preview"]')

		expect(previews).toHaveLength(1)
		expect(previews[0].attributes('src')).toMatch(/\/core\/preview\?fileId=1&/)

		await previews[0].trigger('error')

		expect(wrapper.find('[data-testid="fcias-group-preview"]').exists()).toBe(false)
	})

	it('shows none for a group of versions only', () => {
		expect(mounted({ files: [files[1]] }).find('[data-testid="fcias-group-preview"]').exists()).toBe(false)
	})
})

describe('DuplicateGroup, a row\'s menu', () => {
	const files: GroupType['files'] = [
		{ fileid: 1, path: '/a.pdf', name: 'a.pdf', actions: ['trash', 'delete', 'mergeInto'] },
		{ fileid: 3, path: 'trash:///b.pdf', name: 'b.pdf', trash: { deletedAt: '2023-11-14T22:13:20+00:00' }, actions: ['delete', 'restore'] },
		{ fileid: 4, path: '/c.pdf', name: 'c.pdf', actions: [] },
	]

	function acting(canAct: boolean) {
		return mount(DuplicateGroup, {
			props: { group: group({ files }), fileUrl: () => '#', canAct },
			attachTo: document.body,
		})
	}

	// What the server said the row allows, and nothing that would only be
	// refused; taking another's versions is no entry of its own.
	it('offers what each row allows, in its own menu', async () => {
		const items = acting(true).findAll('.db-file-item')

		expect(await hasAction(items[0], 'trash')).toBe(true)
		expect(await hasAction(items[0], 'delete')).toBe(true)
		expect(await hasAction(items[0], 'mergeInto')).toBe(false)
		expect(await hasAction(items[0], 'restore')).toBe(false)
		expect(await hasAction(items[1], 'restore')).toBe(true)
		expect(await hasAction(items[1], 'trash')).toBe(false)
		expect(items[2].find('.db-file-actions').exists()).toBe(false)
	})

	it('offers nothing on a listing the page does not act on', () => {
		expect(acting(false).find('.db-file-actions').exists()).toBe(false)
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

	function choosing(canAct = true) {
		return mount(DuplicateGroup, { props: { group: group({ files }), fileUrl: () => '#', canAct } })
	}

	/** The rows' checkboxes, by the row's file id: Nextcloud's own, driven as the app's other specs drive it. */
	function boxes(wrapper: ReturnType<typeof choosing>) {
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

	it('offers a checkbox on each row that may be deleted, once asked', async () => {
		const wrapper = choosing()

		expect(wrapper.find('[data-testid="fcias-select-row"]').exists()).toBe(false)
		await wrapper.find('[data-testid="fcias-select"]').trigger('click')

		expect([...boxes(wrapper).keys()]).toEqual([1, 2, 3])
	})

	it('offers no choosing on a listing the page does not act on', () => {
		expect(choosing(false).find('[data-testid="fcias-select"]').exists()).toBe(false)
	})

	// All, then the other lot: Invert of all is none, and the button waits.
	it('chooses all, inverts the choice, and deletes what is chosen', async () => {
		const wrapper = choosing()
		await wrapper.find('[data-testid="fcias-select"]').trigger('click')

		await boxes(wrapper).get(2)?.check(true)
		await wrapper.find('[data-testid="fcias-select-invert"]').trigger('click')
		await wrapper.find('[data-testid="fcias-delete-selected"]').trigger('click')
		expect(wrapper.emitted('deleteSelected')?.[0]?.[1]).toEqual([1, 3])

		await wrapper.find('[data-testid="fcias-select-all"]').trigger('click')
		await wrapper.find('[data-testid="fcias-delete-selected"]').trigger('click')
		expect(wrapper.emitted('deleteSelected')?.[1]?.[1]).toEqual([1, 2, 3])

		await wrapper.find('[data-testid="fcias-select-invert"]').trigger('click')
		expect(wrapper.find('[data-testid="fcias-delete-selected"]').attributes('disabled')).toBeDefined()
	})

	it('leaves choosing, and forgets the choice, on Cancel', async () => {
		const wrapper = choosing()
		await wrapper.find('[data-testid="fcias-select"]').trigger('click')
		await wrapper.find('[data-testid="fcias-select-all"]').trigger('click')

		await wrapper.find('[data-testid="fcias-select-cancel"]').trigger('click')
		expect(wrapper.find('[data-testid="fcias-select-row"]').exists()).toBe(false)

		await wrapper.find('[data-testid="fcias-select"]').trigger('click')
		expect(wrapper.find('[data-testid="fcias-delete-selected"]').attributes('disabled')).toBeDefined()
	})
})
