import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import PermissionSection from './PermissionSection.vue'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => url,
}))

vi.mock('@nextcloud/vue/components/NcPopover', () => ({
	default: { name: 'NcPopover', template: '<div><slot name="trigger" /><slot /></div>' },
}))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label><slot /></label>',
	},
}))
vi.mock('@nextcloud/vue/components/NcSettingsSelectGroup', () => ({
	default: { name: 'NcSettingsSelectGroup', props: ['modelValue'], emits: ['update:modelValue'], template: '<div />' },
}))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: { name: 'NcSelect', props: ['modelValue'], emits: ['update:modelValue'], template: '<div />' },
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		template: '<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
	},
}))

const toastSaved = vi.fn()
const toastError = vi.fn()
vi.mock('../toast', () => ({
	toastSaved: (...args: unknown[]) => toastSaved(...args),
	toastError: (...args: unknown[]) => toastError(...args),
}))

const fetchMock = vi.fn()

function jsonResponse(body: unknown): Response {
	return { ok: true, json: () => Promise.resolve(body) } as unknown as Response
}

beforeEach(() => {
	(window as unknown as { OC: unknown }).OC = { requestToken: 'token' }
	toastSaved.mockReset()
	toastError.mockReset()
	fetchMock.mockReset()
	fetchMock.mockImplementation((url: string, init?: RequestInit) => {
		if (init?.method === 'PUT') {
			return Promise.resolve(jsonResponse({ success: true }))
		}
		return Promise.resolve(jsonResponse({
			permissions: { rule_editing: { allowAll: false, groups: ['staff'], users: ['alice'] } },
			availableUsers: [{ id: 'alice', displayName: 'Alice' }, { id: 'bob', displayName: 'Bob' }],
		}))
	})
	vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
	vi.unstubAllGlobals()
})

async function mounted() {
	const wrapper = mount(PermissionSection, {
		props: {
			permission: 'rule_editing',
			switchLabel: 'Allow all users to edit rules',
			help: { allowAll: 'a', groups: 'g', users: 'u' },
		},
	})
	await flushPromises()
	const groups = wrapper.findComponent({ name: 'NcSettingsSelectGroup' })
	const button = () => wrapper.findComponent({ name: 'NcButton' })
	return { wrapper, groups, button }
}

describe('PermissionSection', () => {
	it('offers Save only once something differs from what the server gave it', async () => {
		const { groups, button } = await mounted()

		expect(button().props('disabled')).toBe(true)
		expect(button().props('variant')).toBe('secondary')

		groups.vm.$emit('update:modelValue', ['staff', 'admins'])
		await nextTick()

		expect(button().props('disabled')).toBe(false)
		expect(button().props('variant')).toBe('warning')
	})

	it('sends only its own three fields, and goes quiet once saved', async () => {
		const { wrapper, groups, button } = await mounted()

		groups.vm.$emit('update:modelValue', ['staff', 'admins'])
		await nextTick()
		await wrapper.find('#fcias-btn-save-rule_editing').trigger('click')
		await flushPromises()

		const put = fetchMock.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')
		expect(JSON.parse(String((put![1] as RequestInit).body))).toEqual({
			permissions: { rule_editing: { allowAll: false, groups: ['staff', 'admins'], users: ['alice'] } },
		})
		expect(toastSaved).toHaveBeenCalledWith('Permissions saved.')
		expect(button().props('disabled')).toBe(true)
	})

	// A refused save leaves the page holding something the server does not
	// have, so the button must keep offering to send it.
	it('keeps offering Save when the server refused it', async () => {
		const { wrapper, groups, button } = await mounted()
		fetchMock.mockImplementation((url: string, init?: RequestInit) => {
			if (init?.method === 'PUT') {
				return Promise.resolve(jsonResponse({ success: false, error: 'Nope.' }))
			}
			return Promise.resolve(jsonResponse({}))
		})

		groups.vm.$emit('update:modelValue', ['staff', 'admins'])
		await nextTick()
		await wrapper.find('#fcias-btn-save-rule_editing').trigger('click')
		await flushPromises()

		expect(toastError).toHaveBeenCalledWith('Nope.')
		expect(button().props('disabled')).toBe(false)
	})
})
