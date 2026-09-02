import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import AlgorithmSection from './AlgorithmSection.vue'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => url,
}))

vi.mock('@nextcloud/vue/components/NcPopover', () => ({
	default: { name: 'NcPopover', template: '<div><slot name="trigger" /><slot /></div>' },
}))

// The picker's own behaviour has its own spec; here it is a plain control that
// reports what it was offered and what is bound.
vi.mock('../components/AlgorithmSelect.vue', () => ({
	default: {
		name: 'AlgorithmSelect',
		props: ['modelValue', 'algorithms', 'multiple', 'inputId', 'label'],
		emits: ['update:modelValue'],
		template: '<select :id="inputId"><option v-for="id in algorithms" :key="id" :value="id">{{ id }}</option></select>',
	},
}))

const fetchMock = vi.fn()

function jsonResponse(body: unknown): Response {
	return { ok: true, json: () => Promise.resolve(body) } as unknown as Response
}

beforeEach(() => {
	(window as unknown as { OC: unknown }).OC = {
		requestToken: 'token',
		Notification: { showTemporary: vi.fn() },
	}
	fetchMock.mockReset()
	fetchMock.mockImplementation((url: string, init?: RequestInit) => {
		if (init?.method === 'PUT') {
			const body = JSON.parse(String(init.body)) as { allowedAlgorithms: string[], defaultAlgorithm: string }
			return Promise.resolve(jsonResponse({ success: true, ...body }))
		}
		return Promise.resolve(jsonResponse({
			allowedAlgorithms: ['sha1', 'sha256', 'md5'],
			availableAlgorithms: ['sha1', 'md5', 'sha256', 'sha512'],
			defaultAlgorithm: 'sha256',
		}))
	})
	vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
	vi.unstubAllGlobals()
})

async function mounted() {
	const wrapper = mount(AlgorithmSection)
	await flushPromises()
	const [allowed, def] = wrapper.findAllComponents({ name: 'AlgorithmSelect' })
	return { wrapper, allowed, def }
}

describe('AlgorithmSection', () => {
	it('offers only the allowed algorithms as default and binds the stored one', async () => {
		const { def } = await mounted()

		expect(def.props('algorithms')).toEqual(['sha1', 'sha256', 'md5'])
		expect(def.props('modelValue')).toBe('sha256')
	})

	it('moves the default to the first remaining when it leaves the allowlist', async () => {
		const { allowed, def } = await mounted()

		allowed.vm.$emit('update:modelValue', ['sha1', 'md5'])
		await nextTick()

		expect(def.props('algorithms')).toEqual(['sha1', 'md5'])
		expect(def.props('modelValue')).toBe('sha1')
	})

	it('keeps a default that stays in the allowlist', async () => {
		const { allowed, def } = await mounted()

		allowed.vm.$emit('update:modelValue', ['md5', 'sha256'])
		await nextTick()

		expect(def.props('modelValue')).toBe('sha256')
	})

	it('saves the allowlist and the default together', async () => {
		const { wrapper, def } = await mounted()

		def.vm.$emit('update:modelValue', 'md5')
		await nextTick()
		await wrapper.find('#fcias-btn-save-algorithms').trigger('click')
		await flushPromises()

		const put = fetchMock.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')
		expect(put).toBeDefined()
		expect(JSON.parse(String((put![1] as RequestInit).body))).toEqual({
			allowedAlgorithms: ['sha1', 'sha256', 'md5'],
			defaultAlgorithm: 'md5',
		})
	})
})
