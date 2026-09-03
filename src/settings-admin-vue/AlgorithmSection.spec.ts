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

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		template: '<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
	},
}))

const toastSaved = vi.fn()
const toastError = vi.fn()
vi.mock('../toast', () => ({
	toastSaved: (...args: unknown[]) => toastSaved(...args),
	toastError: (...args: unknown[]) => toastError(...args),
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
	(window as unknown as { OC: unknown }).OC = { requestToken: 'token' }
	toastSaved.mockReset()
	toastError.mockReset()
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
	const button = () => wrapper.findComponent({ name: 'NcButton' })
	return { wrapper, allowed, def, button }
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
		// Said once the server has confirmed, not when the button was pressed.
		expect(toastSaved).toHaveBeenCalledWith('Algorithms saved.')
		expect(toastError).not.toHaveBeenCalled()
	})

	it('reports a refused save as an error and says nothing was saved', async () => {
		fetchMock.mockImplementation((url: string, init?: RequestInit) => {
			if (init?.method === 'PUT') {
				return Promise.resolve(jsonResponse({ success: false, error: 'None of those is available.' }))
			}
			return Promise.resolve(jsonResponse({
				allowedAlgorithms: ['sha1', 'md5'],
				availableAlgorithms: ['sha1', 'md5'],
				defaultAlgorithm: 'sha1',
			}))
		})
		const { wrapper, def, button } = await mounted()

		def.vm.$emit('update:modelValue', 'md5')
		await nextTick()
		await wrapper.find('#fcias-btn-save-algorithms').trigger('click')
		await flushPromises()

		expect(toastError).toHaveBeenCalledWith('None of those is available.')
		expect(toastSaved).not.toHaveBeenCalled()
		// Still unsaved, so the button still offers to try again.
		expect(button().props('disabled')).toBe(false)
	})

	// Nothing to save is nothing to press, and something to save is worth
	// seeing from across the page.
	it('offers Save only once something differs from what the server gave it', async () => {
		const { def, button } = await mounted()

		expect(button().props('disabled')).toBe(true)
		expect(button().props('variant')).toBe('secondary')

		def.vm.$emit('update:modelValue', 'md5')
		await nextTick()

		expect(button().props('disabled')).toBe(false)
		expect(button().props('variant')).toBe('warning')
	})

	it('goes quiet again once the save has gone through', async () => {
		const { wrapper, def, button } = await mounted()

		def.vm.$emit('update:modelValue', 'md5')
		await nextTick()
		await wrapper.find('#fcias-btn-save-algorithms').trigger('click')
		await flushPromises()

		expect(button().props('disabled')).toBe(true)
		expect(button().props('variant')).toBe('secondary')
	})
})
