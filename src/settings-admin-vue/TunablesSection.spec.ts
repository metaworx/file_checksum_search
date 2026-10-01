import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import TunablesSection from './TunablesSection.vue'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => url,
}))

const toastSaved = vi.fn()
const toastError = vi.fn()
vi.mock('../toast', () => ({
	toastSaved: (...args: unknown[]) => toastSaved(...args),
	toastError: (...args: unknown[]) => toastError(...args),
}))

const fetchMock = vi.fn()
let putOk = true

function jsonResponse(body: unknown, ok = true): Response {
	return { ok, status: ok ? 200 : 500, json: () => Promise.resolve(body) } as unknown as Response
}

beforeEach(() => {
	(window as unknown as { OC: unknown }).OC = { requestToken: 'token' }
	toastSaved.mockReset()
	toastError.mockReset()
	putOk = true
	fetchMock.mockReset()
	fetchMock.mockImplementation((url: string, init?: RequestInit) => {
		if (init?.method === 'PUT') {
			return Promise.resolve(jsonResponse({ success: putOk }, putOk))
		}
		return Promise.resolve(jsonResponse({
			crossAccountPrefillLimit: 21,
			checksumCountBackground: false,
			checksumCountInterval: 3600,
		}))
	})
	vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
	vi.unstubAllGlobals()
})

/** The bodies of every PUT so far, parsed. */
function puts(): Record<string, unknown>[] {
	return fetchMock.mock.calls
		.filter(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')
		.map(([, init]) => JSON.parse((init as RequestInit).body as string))
}

async function mounted() {
	const wrapper = mount(TunablesSection)
	await flushPromises()
	const toggle = () => wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
	const interval = () => wrapper.findAllComponents({ name: 'NcTextField' })[1]
	const saveInterval = () => wrapper.findAllComponents({ name: 'NcButton' })[1]
	return { wrapper, toggle, interval, saveInterval }
}

describe('TunablesSection', () => {
	it('shows the checksum count settings as the server keeps them, the interval in minutes', async () => {
		const { toggle, interval, saveInterval } = await mounted()

		expect(toggle().props('modelValue')).toBe(false)
		expect(interval().props('modelValue')).toBe(60)
		expect(saveInterval().props('disabled')).toBe(true)
	})

	it('saves the background count on click, and that alone', async () => {
		const { toggle } = await mounted()

		toggle().vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(puts()).toEqual([{ checksumCountBackground: true }])
		expect(toggle().props('modelValue')).toBe(true)
		expect(toastSaved).toHaveBeenCalledOnce()
	})

	it('puts the switch back where the server refused it, and says so', async () => {
		const { toggle } = await mounted()
		putOk = false

		toggle().vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(toggle().props('modelValue')).toBe(false)
		expect(toastError).toHaveBeenCalledOnce()
	})

	it('saves the interval only on its own button, in seconds', async () => {
		const { interval, saveInterval } = await mounted()

		interval().vm.$emit('update:modelValue', '120')
		await flushPromises()

		expect(puts()).toEqual([])
		expect(saveInterval().props('disabled')).toBe(false)

		saveInterval().vm.$emit('click')
		await flushPromises()

		expect(puts()).toEqual([{ checksumCountInterval: 7200 }])
		expect(saveInterval().props('disabled')).toBe(true)
	})

	// Bounded on saving, not while typing: the 1 of 120 must not become a 5.
	it('keeps the interval within five minutes and a week when it is saved', async () => {
		const { interval, saveInterval } = await mounted()

		interval().vm.$emit('update:modelValue', '1')
		await flushPromises()
		expect(interval().props('modelValue')).toBe(1)

		saveInterval().vm.$emit('click')
		await flushPromises()

		expect(puts()).toEqual([{ checksumCountInterval: 300 }])
		expect(interval().props('modelValue')).toBe(5)
	})
})
