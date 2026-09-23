import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SudoTokensSection from './SudoTokensSection.vue'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string, params?: Record<string, unknown>) =>
		url.replace(/\{(\w+)\}/g, (whole, token) => (params && token in params ? String(params[token]) : whole)),
}))

const confirmPassword = vi.fn()
vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: (...args: unknown[]) => confirmPassword(...args),
}))
vi.mock('@nextcloud/password-confirmation/style.css', () => ({}))

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

const backup = { id: 7, name: 'backup', last_activity: 1_700_000_000, filesystem: true, granted: false, granted_by: '', granted_at: 0 }

beforeEach(() => {
	(window as unknown as { OC: unknown }).OC = { requestToken: 'token' }
	confirmPassword.mockReset().mockResolvedValue(undefined)
	toastSaved.mockReset()
	toastError.mockReset()
	fetchMock.mockReset()
	fetchMock.mockImplementation((url: string, init?: RequestInit) => {
		if (init?.method === 'PUT') {
			const { granted } = JSON.parse(String(init.body)) as { granted: boolean }
			return Promise.resolve(jsonResponse({ success: true, tokens: [{ ...backup, granted }] }))
		}
		return Promise.resolve(jsonResponse({ canUseApi: true, tokens: [backup] }))
	})
	vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
	vi.unstubAllGlobals()
})

async function mounted() {
	const wrapper = mount(SudoTokensSection)
	await flushPromises()
	return { wrapper, toggle: () => wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }) }
}

describe('SudoTokensSection', () => {
	it('hides itself for an account the API permission does not name', async () => {
		fetchMock.mockResolvedValueOnce(jsonResponse({ canUseApi: false, tokens: [] }))
		const { wrapper } = await mounted()

		expect(wrapper.find('#fcias-personal-sudo-tokens').exists()).toBe(false)
	})

	it('lists the app passwords with their grant state', async () => {
		const { wrapper, toggle } = await mounted()

		expect(wrapper.find('[data-testid="fcias-sudo-tokens"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('backup')
		expect(toggle().props('modelValue')).toBe(false)
	})

	// A grant is a standing authorisation: it costs the password, through
	// core's own dialog, and only then is anything sent.
	it('asks for the password before granting, then sends the grant', async () => {
		const { toggle } = await mounted()

		await toggle().find('input').setValue(true)
		await flushPromises()

		expect(confirmPassword).toHaveBeenCalledTimes(1)
		const put = fetchMock.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')
		expect(String(put![0])).toContain('/settings/personal/sudo-tokens/7')
		expect(JSON.parse(String((put![1] as RequestInit).body))).toEqual({ granted: true })
		expect(toggle().props('modelValue')).toBe(true)
		expect(toastSaved).toHaveBeenCalledWith('App password granted.')
	})

	it('sends nothing when the dialog is dismissed', async () => {
		confirmPassword.mockRejectedValueOnce(new Error('dismissed'))
		const { toggle } = await mounted()

		await toggle().find('input').setValue(true)
		await flushPromises()

		expect(fetchMock.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(false)
		expect(toggle().props('modelValue')).toBe(false)
	})

	// Revoking never widens anything, so it asks for nothing.
	it('revokes without asking', async () => {
		fetchMock.mockResolvedValueOnce(jsonResponse({ canUseApi: true, tokens: [{ ...backup, granted: true }] }))
		const { toggle } = await mounted()

		await toggle().find('input').setValue(false)
		await flushPromises()

		expect(confirmPassword).not.toHaveBeenCalled()
		expect(toastSaved).toHaveBeenCalledWith('Grant revoked.')
	})

	it('does not offer the switch for a token kept out of the filesystem', async () => {
		fetchMock.mockResolvedValueOnce(jsonResponse({ canUseApi: true, tokens: [{ ...backup, filesystem: false }] }))
		const { toggle } = await mounted()

		expect(toggle().props('disabled')).toBe(true)
	})

	// A GET from a session still has to pass core's CSRF check, and the
	// request token is what passes it. Without the header the listing was
	// refused before it reached the app, and the section told a user with
	// app passwords that they had none.
	it('sends the request token with the listing request', async () => {
		await mounted()

		const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
		expect((init.headers as Record<string, string>).requesttoken).toBe('token')
	})

	it('reports a failed load rather than "no app passwords yet"', async () => {
		fetchMock.mockResolvedValueOnce({ ok: false, status: 412, json: () => Promise.resolve({}) } as unknown as Response)
		const { wrapper } = await mounted()

		const line = wrapper.find('[data-testid="fcias-sudo-tokens-error"]')
		expect(line.text()).toContain('could not be listed')
		expect(line.text()).toContain('HTTP 412')
		expect(wrapper.find('[data-testid="fcias-sudo-tokens-empty"]').exists()).toBe(false)
	})

	it('says so when the server could not read the token table', async () => {
		fetchMock.mockResolvedValueOnce(jsonResponse({ canUseApi: true, tokens: [], available: false }))
		const { wrapper } = await mounted()

		expect(wrapper.find('[data-testid="fcias-sudo-tokens-error"]').text()).toContain('token table could not be read')
		expect(wrapper.find('[data-testid="fcias-sudo-tokens-empty"]').exists()).toBe(false)
	})
})
