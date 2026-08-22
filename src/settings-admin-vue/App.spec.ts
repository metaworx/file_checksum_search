import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import App from './App.vue'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => url,
}))

vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: { name: 'NcSelect', render: () => null },
}))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: { name: 'NcCheckboxRadioSwitch', render: () => null },
}))
vi.mock('@nextcloud/vue/components/NcSettingsSelectGroup', () => ({
	default: { name: 'NcSettingsSelectGroup', render: () => null },
}))
vi.mock('@nextcloud/vue/components/NcRichText', () => ({
	default: { name: 'NcRichText', render: () => null },
}))

const confirmMock = vi.fn((_text: string, _title: string, callback: (confirmed: boolean) => void) => callback(true))

;(globalThis as unknown as { OC: unknown }).OC = {
	requestToken: 'token',
	Notification: { showTemporary: vi.fn() },
	dialogs: { confirm: confirmMock },
}

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200 })
}

function mockFetch(): void {
	vi.spyOn(globalThis, 'fetch').mockImplementation((input) => {
		const url = String(input)
		if (url.includes('/settings/status')) {
			return Promise.resolve(jsonResponse({ version: '1.0', dbVersion: '1', rowCount: 3, pendingStats: {} }))
		}
		if (url.includes('/settings/cron/definitions')) {
			return Promise.resolve(jsonResponse({
				definitions: [
					{ id: 1, path: '**', userScope: 'all', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: false },
					{ id: 2, path: '/docs', userScope: 'all', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: false },
				],
				supportedAlgos: ['sha1', 'sha256'],
				users: ['alice'],
			}))
		}
		if (url.includes('/settings/admin-options')) {
			return Promise.resolve(jsonResponse({ allowAllUsers: false, groups: [], users: [], availableUsers: [] }))
		}
		return Promise.resolve(jsonResponse({ success: true }))
	})
}

describe('settings-admin App', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		window.location.hash = ''
	})

	it('loads status and splits the global rule from the additional rules', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-status-rowcount').text()).toBe('3')
		expect(wrapper.find('#fcias-cron-list').text()).toContain('/docs')
		expect(wrapper.find('#fcias-cron-list').text()).not.toContain('**')
	})

	it('switches to the Documentation tab', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-tab-panel-settings').exists()).toBe(true)
		expect(wrapper.find('#fcias-tab-panel-docs').exists()).toBe(false)

		await wrapper.findAll('.fcias-tab').at(1)!.trigger('click')

		expect(wrapper.find('#fcias-tab-panel-settings').exists()).toBe(false)
		expect(wrapper.find('#fcias-tab-panel-docs').exists()).toBe(true)
	})

	it('opens and cancels the add-rule form', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-cron-form').exists()).toBe(false)

		await wrapper.find('#fcias-btn-add-definition').trigger('click')
		expect(wrapper.find('#fcias-cron-form').exists()).toBe(true)

		await wrapper.find('#fcias-btn-cancel-definition').trigger('click')
		expect(wrapper.find('#fcias-cron-form').exists()).toBe(false)
	})

	it('saves the global rule with the fixed path/userScope and the form fields', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		await wrapper.find('#fcias-global-mode').setValue('force')
		await wrapper.find('#fcias-btn-save-global').trigger('click')
		await flushPromises()

		const saveCall = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
			([input]) => String(input).includes('/settings/cron/save'),
		)
		expect(saveCall).toBeDefined()
		const body = JSON.parse((saveCall![1] as RequestInit).body as string)
		expect(body).toMatchObject({ id: 1, path: '**', userScope: 'all', mode: 'force' })
	})

	it('deletes an additional rule after confirmation', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		await wrapper.find('#fcias-cron-list').find('button[data-action="delete"]').trigger('click')
		await flushPromises()

		expect(confirmMock).toHaveBeenCalled()
		const deleteCall = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
			([input]) => String(input).includes('/settings/cron/delete'),
		)
		expect(deleteCall).toBeDefined()
		expect(JSON.parse((deleteCall![1] as RequestInit).body as string)).toEqual({ id: 2 })
	})
})
