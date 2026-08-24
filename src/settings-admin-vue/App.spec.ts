import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import App from './App.vue'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => url,
}))

vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: { name: 'NcSelect', render: () => null },
}))
vi.mock('@nextcloud/vue/components/NcPopover', () => ({
	default: {
		name: 'NcPopover',
		template: '<div class="nc-popover"><slot name="trigger" /><slot /></div>',
	},
}))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: { name: 'NcDialog', template: '<div><slot /></div>' },
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
		if (url.includes('/api/v1/rules')) {
			// Server order is band order: the pinned catch-all evaluates last.
			return Promise.resolve(jsonResponse({
				success: true,
				rules: [
					{ id: 2, path: '/docs', userScope: 'all', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: false, band: 6, position: 1, canEdit: true },
					{ id: 1, path: '**', userScope: 'all', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: false, pinned: true, band: 7, position: 1, canEdit: true },
				],
				canCreate: true,
				supportedAlgos: ['sha1', 'sha256'],
				availableUsers: ['alice'],
				availableGroups: [],
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

	it('loads status and lists every rule in one banded table', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-status-rowcount').text()).toBe('3')

		// One table now, in evaluation order: the catch-all is a row in it
		// rather than a separate table above.
		const rows = wrapper.find('#fcias-cron-list').findAll('tbody tr[data-id]')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('/docs')
		expect(rows[1].text()).toContain('**')
		expect(rows[1].findAll('td')[1].text()).toBe('7.1')
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

	it('keeps Path and User Scope editable for an additional rule', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		await wrapper.find('#fcias-cron-list').findAll('tbody tr[data-id]')[0]
			.find('button[data-action="edit"]').trigger('click')

		expect(wrapper.find('#fcias-cron-path').element.tagName).toBe('INPUT')
		expect(wrapper.find('#fcias-cron-userscope').element.tagName).toBe('SELECT')
		expect((wrapper.find('#fcias-cron-path').element as HTMLInputElement).value).toBe('/docs')
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

	it('gives the catch-all no Delete button and no drag handle', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		const rows = wrapper.find('#fcias-cron-list').findAll('tbody tr[data-id]')
		const pinned = rows[1]

		// It is the last resort by definition: it can be disabled, never
		// deleted, and never moved out of the final band.
		expect(pinned.find('button[data-action="delete"]').exists()).toBe(false)
		expect(pinned.find('.fcias-drag-handle').exists()).toBe(false)
		expect(pinned.find('button[data-action="edit"]').exists()).toBe(true)

		// An ordinary rule keeps both.
		expect(rows[0].find('button[data-action="delete"]').exists()).toBe(true)
		expect(rows[0].find('.fcias-drag-handle').exists()).toBe(true)
	})

	it('edits the global rule through the shared dialog with Path/User Scope locked', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		const pinnedRow = wrapper.find('#fcias-cron-list').findAll('tbody tr[data-id]')[1]
		await pinnedRow.find('button[data-action="edit"]').trigger('click')

		// The global rule's fixed reach is rendered as plain text, not as a
		// disabled input, so it cannot be mistaken for an editable field.
		const path = wrapper.find('#fcias-cron-path')
		const scope = wrapper.find('#fcias-cron-userscope')
		expect(path.element.tagName).toBe('SPAN')
		expect(path.text()).toBe('**')
		expect(path.attributes('title')).toBe('**')
		expect(scope.element.tagName).toBe('SPAN')
		expect(scope.text()).toBe('All Users')

		await wrapper.find('#fcias-cron-mode').setValue('force')
		await wrapper.find('#fcias-btn-save-definition').trigger('click')
		await flushPromises()

		// The listing GET also hits /api/v1/rules, so match the mutation.
		const saveCall = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
			([, init]) => (init as RequestInit | undefined)?.method === 'PUT',
		)
		expect(saveCall).toBeDefined()
		expect(String(saveCall![0])).toBe('/apps/file_checksum_search/api/v1/rules/1')
		const body = JSON.parse((saveCall![1] as RequestInit).body as string)
		expect(body).toMatchObject({ path: '**', userScope: 'all', mode: 'force', pinned: true })
	})

	it('shows the stored algorithms of the global rule once the async load resolves', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		// Regression guard: AlgoMultiselect used to snapshot its selection at
		// setup time, when supportedAlgos was still empty, and stayed blank.
		expect(wrapper.find('#fcias-cron-list').text()).toContain('sha1')
	})

	it('deletes an additional rule after confirmation', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		await wrapper.find('#fcias-cron-list').findAll('tbody tr[data-id]')[0]
			.find('button[data-action="delete"]').trigger('click')
		await flushPromises()

		expect(confirmMock).toHaveBeenCalled()
		const deleteCall = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
			([, init]) => (init as RequestInit | undefined)?.method === 'DELETE',
		)
		expect(deleteCall).toBeDefined()
		expect(String(deleteCall![0])).toBe('/apps/file_checksum_search/api/v1/rules/2')
	})
})
