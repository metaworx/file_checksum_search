import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import App from './App.vue'
import { clickAction } from '../test-utils/ncActions'

// The rest of the router as it is: the real Nextcloud components read
// `imagePath` and friends, and a mock that names one export hides the rest.
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...await importOriginal<typeof import('@nextcloud/router')>(),
	// Mirrors the real router: {tokens} are substituted from params.
	generateOcsUrl: (url: string, params?: Record<string, unknown>) =>
		url.replace(/\{(\w+)\}/g, (whole, token) => (params && token in params ? String(params[token]) : whole)),
}))

// Toasts are asserted in the sections' own specs; here they only must not throw.
vi.mock('../toast', () => ({
	toastSaved: () => undefined,
	toastSuccess: () => undefined,
	toastError: () => undefined,
}))

// The token section has its own spec and pulls in core's password-confirmation
// dialog, which has no business in a test about the rules page.
vi.mock('./SudoTokensSection.vue', () => ({
	default: { name: 'SudoTokensSection', template: '<div class="sudo-tokens-stub" />' },
}))

const confirmMock = vi.fn((_text: string, _title: string, onConfirm: (confirmed: boolean) => void) => onConfirm(true))

;(globalThis as unknown as { OC: unknown }).OC = {
	requestToken: 'token',
	dialogs: { confirm: confirmMock },
}

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200 })
}

function mockFetch(canEdit = true): void {
	vi.spyOn(globalThis, 'fetch').mockImplementation((input) => {
		const url = String(input)
		if (url.includes('/api/v1/preferences/')) {
			return Promise.resolve(jsonResponse({ key: 'preferred_algorithm', value: '', default: 'sha1', active: 'sha1' }))
		}
		if (url.includes('/api/v1/rules')) {
			return Promise.resolve(jsonResponse({
				success: true,
				rules: [
					{ id: 1, path: '/docs', userScope: 'all', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: false, band: 4, position: 1, canEdit },
				],
				canCreate: canEdit,
				supportedAlgos: ['sha1', 'sha256'],
			}))
		}
		return Promise.resolve(jsonResponse({ success: true }))
	})
}

describe('settings-personal App', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		window.location.hash = ''
	})

	it('loads and renders the current user\'s rules', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-personal-rules').text()).toContain('/docs')
	})

	it('shows the enforced and default bands around the user\'s own, read-only', async () => {
		// The whole point of the personal page is that a user sees what will
		// actually decide their files, not only the part they may change.
		vi.spyOn(globalThis, 'fetch').mockImplementation((input) => {
			if (!String(input).includes('/api/v1/rules')) {
				return Promise.resolve(jsonResponse({ success: true }))
			}
			return Promise.resolve(jsonResponse({
				success: true,
				rules: [
					{ id: 'e1', path: '/legal/**', userScope: 'alice', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: true, band: 1, position: 1, canEdit: false },
					{ id: 'm1', path: '/docs', userScope: 'alice', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: false, band: 4, position: 1, canEdit: true },
					{ id: 'd1', path: '**', userScope: 'all', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: false, pinned: true, band: 7, position: 1, canEdit: false },
				],
				canCreate: true,
				supportedAlgos: ['sha1'],
			}))
		})
		const wrapper = mount(App)
		await flushPromises()

		const rows = wrapper.find('#fcias-personal-rules').findAll('tbody tr[data-id]')
		expect(rows.map((r) => r.attributes('data-band'))).toEqual(['1', '4', '7'])

		// Only the middle one is theirs to touch, and only it can be dragged.
		expect(rows[0].text()).toContain('Read-only')
		expect(rows[2].text()).toContain('Read-only')
		expect(rows[1].find('button[data-action="edit"]').exists()).toBe(true)
		expect(wrapper.findAll('#fcias-personal-rules .fcias-drag-handle')).toHaveLength(1)
	})

	it('hides the Add Rule button and shows the banner when the user cannot edit any rule', async () => {
		mockFetch(false)
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-personal-add').exists()).toBe(false)
		expect(wrapper.text()).toContain('not allowed to edit')
	})

	it('switches to the Help tab', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-tab-panel-rules').exists()).toBe(true)
		expect(wrapper.find('#fcias-tab-panel-help').exists()).toBe(false)

		await wrapper.findAll('.fcias-tab').at(1)!.trigger('click')

		expect(wrapper.find('#fcias-tab-panel-rules').exists()).toBe(false)
		expect(wrapper.find('#fcias-tab-panel-help').exists()).toBe(true)
	})

	it('still honours a #faq link, which this tab used to be called', async () => {
		window.location.hash = 'faq'
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-tab-panel-help').exists()).toBe(true)
	})

	it('opens and cancels the add-rule form', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-personal-form').exists()).toBe(false)

		await wrapper.find('#fcias-personal-add').trigger('click')
		expect(wrapper.find('#fcias-personal-form').exists()).toBe(true)

		await wrapper.find('#fcias-personal-cancel').trigger('click')
		expect(wrapper.find('#fcias-personal-form').exists()).toBe(false)
	})

	it('deletes a rule after confirmation', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		await clickAction(wrapper.find('#fcias-personal-rules').find('tbody tr[data-id]'), 'delete')
		await flushPromises()

		expect(confirmMock).toHaveBeenCalled()
		const deleteCall = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
			([, init]) => (init as RequestInit | undefined)?.method === 'DELETE',
		)
		expect(deleteCall).toBeDefined()
		expect(String(deleteCall![0])).toBe('/apps/file_checksum_search/api/v1/rules/1')
	})
})
