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

const confirmMock = vi.fn((_text: string, _title: string, callback: (confirmed: boolean) => void) => callback(true))

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
		if (url.includes('/personal/rules') && !url.includes('/save') && !url.includes('/delete') && !url.includes('/toggle')) {
			return Promise.resolve(jsonResponse({
				rules: [
					{ id: 1, path: '/docs', userScope: 'all', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: false, canEdit },
				],
				canEdit,
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

	it('hides the Add Rule button and shows the banner when the user cannot edit any rule', async () => {
		mockFetch(false)
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-personal-add').exists()).toBe(false)
		expect(wrapper.text()).toContain('not allowed to edit')
	})

	it('switches to the FAQ tab', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-tab-panel-rules').exists()).toBe(true)
		expect(wrapper.find('#fcias-tab-panel-faq').exists()).toBe(false)

		await wrapper.findAll('.fcias-tab').at(1)!.trigger('click')

		expect(wrapper.find('#fcias-tab-panel-rules').exists()).toBe(false)
		expect(wrapper.find('#fcias-tab-panel-faq').exists()).toBe(true)
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
			([input]) => String(input).includes('/personal/rules/delete'),
		)
		expect(deleteCall).toBeDefined()
		expect(JSON.parse((deleteCall![1] as RequestInit).body as string)).toEqual({ id: 1 })
	})

	it('posts the new order after dragging a rule row, omitting a locked one', async () => {
		vi.spyOn(globalThis, 'fetch').mockImplementation((input) => {
			const url = String(input)
			if (url.includes('/personal/rules') && !url.includes('/save') && !url.includes('/delete') && !url.includes('/toggle') && !url.includes('/reorder')) {
				return Promise.resolve(jsonResponse({
					rules: [
						{ id: 1, path: '/a', userScope: 'alice', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: false, canEdit: true },
						{ id: 2, path: '/b', userScope: 'alice', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: false, canEdit: true },
						// Not alice's to move — admin_enforced, so canEdit is false.
						{ id: 3, path: '/c', userScope: 'all', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: true, canEdit: false },
					],
					canEdit: true,
					supportedAlgos: ['sha1', 'sha256'],
				}))
			}
			return Promise.resolve(jsonResponse({ success: true }))
		})

		const wrapper = mount(App)
		await flushPromises()

		const rows = wrapper.find('#fcias-personal-rules').findAll('tbody tr')
		expect(rows).toHaveLength(3)

		// Drag rule 2 onto the locked rule 3's position: the locked row is a
		// valid drop target but never moves, so it drops out of the payload.
		await rows[1].find('.fcias-drag-handle').trigger('dragstart')
		await rows[2].trigger('dragover')
		await rows[2].trigger('drop')
		await flushPromises()

		const reorderCall = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
			([input]) => String(input).includes('/personal/rules/reorder'),
		)
		expect(reorderCall).toBeDefined()
		expect(JSON.parse((reorderCall![1] as RequestInit).body as string)).toEqual({ orderedIds: [1, 2] })
	})
})
