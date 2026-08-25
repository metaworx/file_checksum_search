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
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type'],
		emits: ['update:modelValue'],
		template: '<span class="nc-switch"><input type="checkbox" :checked="modelValue"'
			+ ' @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></span>',
	},
}))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: { name: 'NcDialog', template: '<div><slot /></div>' },
}))
vi.mock('@nextcloud/vue/components/NcRichText', () => ({
	default: { name: 'NcRichText', render: () => null },
}))

const confirmMock = vi.fn((_text: string, _title: string, onConfirm: (confirmed: boolean) => void) => onConfirm(true))

;(globalThis as unknown as { OC: unknown }).OC = {
	requestToken: 'token',
	Notification: { showTemporary: vi.fn() },
	dialogs: { confirm: confirmMock },
}

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200 })
}

function mockFetch(canEdit = true): void {
	vi.spyOn(globalThis, 'fetch').mockImplementation((input) => {
		const url = String(input)
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

		await wrapper.find('#fcias-personal-rules').find('button[data-action="delete"]').trigger('click')
		await flushPromises()

		expect(confirmMock).toHaveBeenCalled()
		const deleteCall = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
			([, init]) => (init as RequestInit | undefined)?.method === 'DELETE',
		)
		expect(deleteCall).toBeDefined()
		expect(String(deleteCall![0])).toBe('/apps/file_checksum_search/api/v1/rules/1')
	})
})
