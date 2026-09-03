import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SudoTokensTab from './SudoTokensTab.vue'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string, params?: Record<string, unknown>) =>
		url.replace(/\{(\w+)\}/g, (whole, token) => (params && token in params ? String(params[token]) : whole)),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		template: '<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
	},
}))

const toastSuccess = vi.fn()
const toastError = vi.fn()
vi.mock('../toast', () => ({
	toastSuccess: (...args: unknown[]) => toastSuccess(...args),
	toastError: (...args: unknown[]) => toastError(...args),
}))

const fetchMock = vi.fn()

function jsonResponse(body: unknown, ok = true): Response {
	return { ok, status: ok ? 200 : 500, json: () => Promise.resolve(body) } as unknown as Response
}

const grants = [
	{ uid: 'alice', id: 7, name: 'backup', last_activity: 1_700_000_000, exists: true, granted_by: 'alice', granted_at: 1_699_000_000 },
	{ uid: 'bob', id: 42, name: '', last_activity: 0, exists: false, granted_by: 'bob', granted_at: 1_698_000_000 },
]

beforeEach(() => {
	(window as unknown as { OC: unknown }).OC = { requestToken: 'token' }
	toastSuccess.mockReset()
	toastError.mockReset()
	fetchMock.mockReset()
	fetchMock.mockImplementation((url: string, init?: RequestInit) => {
		if (init?.method === 'DELETE') {
			return Promise.resolve(jsonResponse({ grants: grants.slice(0, 1) }))
		}
		return Promise.resolve(jsonResponse({ grants }))
	})
	vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('SudoTokensTab', () => {
	it('lists every grant, and says when its token is gone', async () => {
		const wrapper = mount(SudoTokensTab)
		await flushPromises()

		const rows = wrapper.findAll('[data-testid="fcias-sudo-grants"] tbody tr')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('backup')
		expect(rows[1].text()).toContain('token deleted')
	})

	it('revokes through the admin route and takes the new list', async () => {
		const wrapper = mount(SudoTokensTab)
		await flushPromises()

		await wrapper.find('[data-grant="bob/42"] button').trigger('click')
		await flushPromises()

		const del = fetchMock.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'DELETE')
		expect(String(del![0])).toContain('/settings/sudo-tokens/bob/42')
		expect(wrapper.findAll('[data-testid="fcias-sudo-grants"] tbody tr')).toHaveLength(1)
		expect(toastSuccess).toHaveBeenCalledWith('Grant revoked.')
	})

	it('says so when the token table cannot be read', async () => {
		fetchMock.mockResolvedValueOnce(jsonResponse({}, false))
		const wrapper = mount(SudoTokensTab)
		await flushPromises()

		expect(wrapper.text()).toContain('listing is unavailable')
	})
})
