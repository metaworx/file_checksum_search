/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import MimeTypeSection from './MimeTypeSection.vue'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...await importOriginal<typeof import('@nextcloud/router')>(),
	generateOcsUrl: (url: string) => url,
}))

const toastSaved = vi.fn()
const toastError = vi.fn()
vi.mock('../toast', () => ({
	toastSaved: (...args: unknown[]) => toastSaved(...args),
	toastError: (...args: unknown[]) => toastError(...args),
}))

interface Server {
	state: string
	available: boolean
	/** What the next action answers: its status and body; ok by default. */
	refusal: { status: number, error: string } | null
}

const server: Server = { state: 'disabled', available: true, refusal: null }
const fetchMock = vi.fn()

function jsonResponse(body: unknown, status = 200): Response {
	return new Response(JSON.stringify(body), { status })
}

beforeEach(() => {
	(window as unknown as { OC: unknown }).OC = { requestToken: 'token' }
	server.state = 'disabled'
	server.available = true
	server.refusal = null
	toastSaved.mockReset()
	toastError.mockReset()
	fetchMock.mockReset()
	fetchMock.mockImplementation((url: string, init?: RequestInit) => {
		const answer = () => jsonResponse({ state: server.state, available: server.available, deleteRequestedAt: null })
		if (!init?.method) {
			return Promise.resolve(answer())
		}
		if (server.refusal) {
			return Promise.resolve(jsonResponse({ success: false, error: server.refusal.error }, server.refusal.status))
		}
		if (url.endsWith('/settings/mimetypes')) {
			server.state = JSON.parse(init.body as string).enabled ? 'enabled' : 'disabled'
		} else if (url.endsWith('/delete')) {
			server.state = 'deleting'
		}
		return Promise.resolve(answer())
	})
	vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
	vi.unstubAllGlobals()
	document.body.innerHTML = ''
})

/** Every action sent, as method, path's last part and body. */
function actions(): Array<[string, string, unknown]> {
	return fetchMock.mock.calls
		.filter(([, init]) => (init as RequestInit | undefined)?.method)
		.map(([url, init]) => [
			(init as RequestInit).method as string,
			String(url).split('/').pop() as string,
			(init as RequestInit).body ? JSON.parse((init as RequestInit).body as string) : null,
		])
}

async function mounted() {
	const wrapper = mount(MimeTypeSection, { attachTo: document.body })
	await flushPromises()
	const toggle = () => wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
	const refresh = () => wrapper.find('#fcias-btn-mimetype-refresh')
	const remove = () => wrapper.find('#fcias-btn-mimetype-delete')
	return { wrapper, toggle, refresh, remove }
}

/** The open dialog's button whose label is $label, as a person finds it. */
function dialogButton(label: string): HTMLButtonElement {
	const button = [...document.querySelectorAll<HTMLButtonElement>('.dialog button, [role="dialog"] button')]
		.find((b) => b.textContent?.trim() === label)
	if (!button) {
		throw new Error(`No dialog button "${label}"`)
	}
	return button
}

describe('MimeTypeSection', () => {
	it('switches detection on, and tells the status to count again', async () => {
		const { wrapper, toggle } = await mounted()

		expect(toggle().props('modelValue')).toBe(false)
		toggle().vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(actions()).toEqual([['PUT', 'mimetypes', { enabled: true }]])
		expect(toggle().props('modelValue')).toBe(true)
		expect(wrapper.emitted('changed')).toHaveLength(1)
		expect(toastSaved).toHaveBeenCalledOnce()
	})

	// The user's choice: delete while off, with a hint to switch off for
	// that or to refresh for types up to date.
	it('offers Refresh while on and Delete while off, saying why Delete waits', async () => {
		server.state = 'enabled'
		const { wrapper, refresh, remove } = await mounted()

		expect(refresh().attributes('disabled')).toBeUndefined()
		expect(remove().attributes('disabled')).toBeDefined()
		expect(wrapper.find('#fcias-mimetype-delete-hint').text()).toBe('Switch detection off to delete the MIME types, or refresh them to bring them up to date.')
	})

	it('keeps the switch off, and says why, while a deletion runs', async () => {
		server.state = 'deleting'
		const { wrapper, toggle, refresh, remove } = await mounted()

		expect(toggle().props('disabled')).toBe(true)
		expect(wrapper.find('#fcias-mimetype-reason').text()).toBe('The MIME types are being deleted. Detection can be switched on again once that is done.')
		expect(refresh().attributes('disabled')).toBeDefined()
		expect(remove().attributes('disabled')).toBeDefined()
	})

	it('keeps the switch off, and says why, where PHP cannot detect', async () => {
		server.available = false
		const { wrapper, toggle } = await mounted()

		expect(toggle().props('disabled')).toBe(true)
		expect(wrapper.find('#fcias-mimetype-reason').text()).toContain('Fileinfo extension is not loaded')
	})

	it('asks which files to refresh, and queues what was chosen', async () => {
		server.state = 'enabled'
		const { refresh } = await mounted()

		await refresh().trigger('click')
		await flushPromises()
		dialogButton('All of them').click()
		await flushPromises()

		expect(actions()).toEqual([['POST', 'refresh', { all: true }]])
		expect(toastSaved).toHaveBeenCalledWith('The files are queued to have their MIME type detected.')
	})

	it('deletes once confirmed, and not before', async () => {
		const { refresh, remove } = await mounted()

		expect(refresh().attributes('disabled')).toBeDefined()
		await remove().trigger('click')
		await flushPromises()
		expect(actions()).toEqual([])

		dialogButton('Delete').click()
		await flushPromises()

		expect(actions()).toEqual([['POST', 'delete', null]])
		expect(remove().attributes('disabled')).toBeDefined()
	})

	it('shows a refusal as the server words it, and reads the state again', async () => {
		const { toggle } = await mounted()
		server.refusal = { status: 409, error: 'The MIME types are being deleted. Detection can be switched on again once that is done.' }
		server.state = 'deleting'

		toggle().vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(toastError).toHaveBeenCalledWith('The MIME types are being deleted. Detection can be switched on again once that is done.')
		expect(toggle().props('modelValue')).toBe(false)
		expect(toggle().props('disabled')).toBe(true)
	})
})
