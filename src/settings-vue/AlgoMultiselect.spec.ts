import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AlgoMultiselect from './AlgoMultiselect.vue'
import type { AlgoOption } from '../algorithms'

// Minimal NcSelect stand-in: exposes the bound options as text and lets a test
// push a new selection back through v-model.
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		template: '<div class="nc-select">{{ modelValue.map(o => o.id).join(",") }}</div>',
	},
}))

const OPTIONS: AlgoOption[] = [
	{ id: 'sha1', label: 'SHA-1' },
	{ id: 'sha256', label: 'SHA-256' },
	{ id: 'md5', label: 'MD5' },
]

describe('AlgoMultiselect', () => {
	it('maps the bound ids onto the matching options', () => {
		const wrapper = mount(AlgoMultiselect, {
			props: { modelValue: ['sha1', 'md5'], options: OPTIONS },
		})
		expect(wrapper.find('.nc-select').text()).toBe('sha1,md5')
	})

	it('picks up options that arrive after mount', async () => {
		// Regression guard: the settings pages fetch supportedAlgos and the
		// rules asynchronously, so this component mounts with an empty
		// option list. It used to snapshot the selection at setup time, which
		// filtered [] and left the widget permanently blank even though the
		// server had algorithms stored.
		const wrapper = mount(AlgoMultiselect, {
			props: { modelValue: ['sha256'], options: [] as AlgoOption[] },
		})
		expect(wrapper.find('.nc-select').text()).toBe('')

		await wrapper.setProps({ options: OPTIONS })
		expect(wrapper.find('.nc-select').text()).toBe('sha256')
	})

	it('follows a later change of the bound ids', async () => {
		const wrapper = mount(AlgoMultiselect, {
			props: { modelValue: ['sha1'], options: OPTIONS },
		})
		await wrapper.setProps({ modelValue: ['md5', 'sha256'] })
		expect(wrapper.find('.nc-select').text()).toBe('sha256,md5')
	})

	it('emits the selected ids, not the option objects', async () => {
		const wrapper = mount(AlgoMultiselect, {
			props: { modelValue: ['sha1'], options: OPTIONS },
		})
		await wrapper.findComponent({ name: 'NcSelect' })
			.vm.$emit('update:modelValue', [OPTIONS[1], OPTIONS[2]])

		expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toEqual(['sha256', 'md5'])
	})
})
