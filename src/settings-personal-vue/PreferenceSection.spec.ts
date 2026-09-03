import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PreferenceSection from './PreferenceSection.vue'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string, params?: Record<string, unknown>) =>
		url.replace(/\{(\w+)\}/g, (whole, token) => (params && token in params ? String(params[token]) : whole)),
}))

vi.mock('@nextcloud/vue/components/NcPopover', () => ({
	default: { name: 'NcPopover', template: '<div><slot name="trigger" /><slot /></div>' },
}))

vi.mock('../components/AlgorithmSelect.vue', () => ({
	default: {
		name: 'AlgorithmSelect',
		props: ['modelValue', 'algorithms', 'leading', 'inputId', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template: '<select :id="inputId" />',
	},
}))

const toastSaved = vi.fn()
const toastError = vi.fn()
vi.mock('../toast', () => ({
	toastSaved: (...args: unknown[]) => toastSaved(...args),
	toastError: (...args: unknown[]) => toastError(...args),
}))

const fetchMock = vi.fn()

function jsonResponse(body: unknown, ok = true): Response {
	return { ok, json: () => Promise.resolve(body) } as unknown as Response
}

beforeEach(() => {
	(window as unknown as { OC: unknown }).OC = { requestToken: 'token' }
	toastSaved.mockReset()
	toastError.mockReset()
	fetchMock.mockReset()
	fetchMock.mockImplementation((url: string, init?: RequestInit) => {
		if (init?.method === 'PUT') {
			const { value } = JSON.parse(String(init.body)) as { value: string }
			return Promise.resolve(jsonResponse({ key: 'preferred_algorithm', value, default: 'sha1', active: value || 'sha1' }))
		}
		return Promise.resolve(jsonResponse({ key: 'preferred_algorithm', value: '', default: 'sha1', active: 'sha1' }))
	})
	vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
	vi.unstubAllGlobals()
})

async function mounted() {
	const wrapper = mount(PreferenceSection, { props: { algorithms: ['sha1', 'sha256'] } })
	await flushPromises()
	return { wrapper, picker: wrapper.findComponent({ name: 'AlgorithmSelect' }) }
}

describe('PreferenceSection', () => {
	it('names the instance default in its leading entry and starts on it', async () => {
		const { picker } = await mounted()

		expect(picker.props('modelValue')).toBe('')
		expect(picker.props('leading')).toEqual({ id: '', label: 'Default (SHA1)' })
	})

	it('saves on select and says so once the server has answered', async () => {
		const { picker } = await mounted()

		picker.vm.$emit('update:modelValue', 'sha256')
		await flushPromises()

		const put = fetchMock.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')
		expect(JSON.parse(String((put![1] as RequestInit).body))).toEqual({ value: 'sha256' })
		expect(picker.props('modelValue')).toBe('sha256')
		expect(toastSaved).toHaveBeenCalledWith('Preferred algorithm saved.')
		expect(toastError).not.toHaveBeenCalled()
	})

	it('reports a refused value as an error and keeps what was stored', async () => {
		const { picker } = await mounted()
		fetchMock.mockImplementation((url: string, init?: RequestInit) => {
			if (init?.method === 'PUT') {
				return Promise.resolve(jsonResponse({ error: 'Unknown algorithm.' }, false))
			}
			return Promise.resolve(jsonResponse({}))
		})

		picker.vm.$emit('update:modelValue', 'bogus')
		await flushPromises()

		expect(toastError).toHaveBeenCalledWith('Unknown algorithm.')
		expect(toastSaved).not.toHaveBeenCalled()
		expect(picker.props('modelValue')).toBe('')
	})
})
