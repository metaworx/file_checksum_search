/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import TargetPicker from './TargetPicker.vue'

// A stand-in that exposes the one prop this spec is about and lets a test
// type into the control the way NcSelect reports typing: a `search` event.
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'multiple', 'loading', 'filterable', 'noOptions'],
		emits: ['update:modelValue', 'search'],
		template: '<div class="nc-select" :data-filterable="String(filterable)">{{ options.length }}</div>',
	},
}))

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

/** An account list past the prefill threshold: the server says "search instead". */
const TOO_MANY = { prefill: false, all: true, groups: [], users: [] }

/** One account found by name — and, being one, `prefill: true`. */
const ONE = (id: string) => ({ prefill: true, all: true, groups: [], users: [{ id, label: id }] })

describe('TargetPicker', () => {
	afterEach(() => {
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

		const wrapper = mount(TargetPicker)
		await flushPromises()

		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.attributes('data-filterable')).toBe('false')

		await select.vm.$emit('search', 'admin')
		await flushPromises()
		expect(fetchMock).toHaveBeenCalledTimes(2)
		expect(String(fetchMock.mock.calls[1][0])).toContain('search=admin')

		// Still the server's job, not NcSelect's — the second name is fetched.
		expect(select.attributes('data-filterable')).toBe('false')

		await select.vm.$emit('search', 'fcias_e2e_owner')
		await flushPromises()
		expect(fetchMock).toHaveBeenCalledTimes(3)
		expect(String(fetchMock.mock.calls[2][0])).toContain('search=fcias_e2e_owner')
	})

	// The other mode is unchanged: a list short enough to hold is held, and
	// typing filters it in the browser without a round trip.
	it('filters in the browser when the opening list was complete', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({ prefill: true, all: true, groups: [], users: [{ id: 'alice', label: 'alice' }] }))

		const wrapper = mount(TargetPicker)
		await flushPromises()

		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.attributes('data-filterable')).toBe('true')

		await select.vm.$emit('search', 'ali')
		await flushPromises()
		expect(fetchMock).toHaveBeenCalledTimes(1)
	})
})
