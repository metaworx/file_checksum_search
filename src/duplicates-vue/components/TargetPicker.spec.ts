/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import TargetPicker from './TargetPicker.vue'
import { openSelect, optionLabels, selectedLabels, typeToSearch } from '../../test-utils/ncSelect'

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

/** An account list past the prefill threshold: the server says "search instead". */
const TOO_MANY = { prefill: false, all: true, reach: 'everyone', groups: [], users: [] }

/** One account found by name — and, being one, `prefill: true`. */
const ONE = (id: string) => ({ prefill: true, all: true, reach: 'everyone', groups: [], users: [{ id, label: id }] })

describe('TargetPicker', () => {
	let wrapper: VueWrapper | null = null

	afterEach(() => {
		wrapper?.unmount()
		wrapper = null
		vi.restoreAllMocks()
	})

	// The defect: the picker took a search answer's `prefill: true` as
	// "I now hold the whole list", stopped asking the server, and let
	// NcSelect filter the one account it had. On an instance past the
	// threshold the second name typed then found nothing.
	it('keeps searching as you type once the opening list said to', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse(TOO_MANY))
			.mockResolvedValueOnce(jsonResponse(ONE('admin')))
			.mockResolvedValueOnce(jsonResponse(ONE('fcias_e2e_owner')))

		wrapper = mount(TargetPicker)
		await flushPromises()

		await typeToSearch(wrapper, 'admin')
		expect(fetchMock).toHaveBeenCalledTimes(2)
		expect(String(fetchMock.mock.calls[1][0])).toContain('search=admin')

		// Still the server's job, not NcSelect's — the second name is fetched,
		// and offered, though the list held only the first.
		await typeToSearch(wrapper, 'fcias_e2e_owner')
		expect(fetchMock).toHaveBeenCalledTimes(3)
		expect(String(fetchMock.mock.calls[2][0])).toContain('search=fcias_e2e_owner')
		expect(await optionLabels(wrapper)).toContain('fcias_e2e_owner')
	})

	// The other mode is unchanged: a list short enough to hold is held, and
	// typing filters it in the browser without a round trip.
	it('filters in the browser when the opening list was complete', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({
				prefill: true,
				all: true,
				reach: 'everyone',
				groups: [],
				users: [{ id: 'alice', label: 'alice' }, { id: 'bob', label: 'bob' }],
			}))

		wrapper = mount(TargetPicker)
		await flushPromises()

		await typeToSearch(wrapper, 'ali')
		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(await optionLabels(wrapper)).toEqual(['alice'])
	})

	// "All" is the caller's whole reach, and the server says whose: every
	// account for a sudoer, their groups' members for a leader. The label
	// says the same, so a leader is not promised everyone.
	it('calls the whole reach by what it is', async () => {
		vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({ prefill: true, all: true, reach: 'groups', groups: [], users: [] }))

		wrapper = mount(TargetPicker)
		await flushPromises()

		expect(await optionLabels(wrapper)).toEqual(['All my groups'])
	})

	it('calls it All accounts for a sudoer', async () => {
		vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({ prefill: true, all: true, reach: 'everyone', groups: [], users: [] }))

		wrapper = mount(TargetPicker)
		await flushPromises()

		expect(await optionLabels(wrapper)).toEqual(['All accounts'])
	})

	// The scope is the page's, and may come from the URL before the list
	// has answered: the control shows it at once, by id, and takes the
	// list's own label for it once the list is there.
	it('shows a scope it is given, and relabels it once the list arrives', async () => {
		vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({
				prefill: true,
				all: true,
				reach: 'everyone',
				groups: [{ id: 'team', label: 'Team' }],
				users: [{ id: 'alice', label: 'Alice A.' }],
			}))

		wrapper = mount(TargetPicker, {
			props: { scope: { all: false, users: ['alice'], groups: ['team'] } },
		})
		expect(selectedLabels(wrapper)).toEqual(['team (Group)', 'alice'])

		await flushPromises()
		expect(selectedLabels(wrapper)).toEqual(['Team (Group)', 'Alice A.'])

		await wrapper.setProps({ scope: { all: true, users: [], groups: [] } })
		expect(selectedLabels(wrapper)).toEqual(['All accounts'])
	})

	// Picking "All" through the menu names the whole reach and nothing else.
	it('emits the whole reach when All is picked', async () => {
		vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({
				prefill: true,
				all: true,
				reach: 'everyone',
				groups: [],
				users: [{ id: 'alice', label: 'alice' }],
			}))

		wrapper = mount(TargetPicker)
		await flushPromises()

		const all = (await openSelect(wrapper)).find((li) => li.textContent?.trim() === 'All accounts')
		all!.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
		await flushPromises()

		expect(wrapper.emitted('update:scope')?.at(-1)?.[0]).toEqual({ all: true, users: [], groups: [] })
	})
})
