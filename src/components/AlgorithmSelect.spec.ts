import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AlgorithmSelect from './AlgorithmSelect.vue'

// Minimal NcSelect stand-in: exposes the bound selection as text and lets a
// test push a new selection back through v-model.
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'multiple'],
		emits: ['update:modelValue'],
		template: '<div class="nc-select">{{ Array.isArray(modelValue) ? modelValue.map(o => o.id).join(",") : (modelValue ? modelValue.id : "") }}</div>',
	},
}))

const ALGORITHMS = ['sha1', 'sha256', 'md5']

describe('AlgorithmSelect', () => {
	describe('multiple', () => {
		it('maps the bound ids onto the matching options', () => {
			const wrapper = mount(AlgorithmSelect, {
				props: { modelValue: ['sha1', 'md5'], algorithms: ALGORITHMS, multiple: true },
			})
			expect(wrapper.find('.nc-select').text()).toBe('sha1,md5')
		})

		it('picks up algorithms that arrive after mount', async () => {
			// Regression guard: the settings pages fetch supportedAlgos and the
			// rules asynchronously, so this component mounts with an empty list.
			// It used to snapshot the selection at setup time, which filtered []
			// and left the widget permanently blank even though the server had
			// algorithms stored.
			const wrapper = mount(AlgorithmSelect, {
				props: { modelValue: ['sha256'], algorithms: [] as string[], multiple: true },
			})
			expect(wrapper.find('.nc-select').text()).toBe('')

			await wrapper.setProps({ algorithms: ALGORITHMS })
			expect(wrapper.find('.nc-select').text()).toBe('sha256')
		})

		it('keeps the bound order, because for an allowlist the first is the default', async () => {
			const wrapper = mount(AlgorithmSelect, {
				props: { modelValue: ['sha1'], algorithms: ALGORITHMS, multiple: true },
			})
			await wrapper.setProps({ modelValue: ['md5', 'sha256'] })
			expect(wrapper.find('.nc-select').text()).toBe('md5,sha256')
		})

		it('emits the selected ids, not the option objects', async () => {
			const wrapper = mount(AlgorithmSelect, {
				props: { modelValue: ['sha1'], algorithms: ALGORITHMS, multiple: true },
			})
			await wrapper.findComponent({ name: 'NcSelect' })
				.vm.$emit('update:modelValue', [{ id: 'sha256', label: 'SHA256' }, { id: 'md5', label: 'MD5' }])

			expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toEqual(['sha256', 'md5'])
		})
	})

	describe('single', () => {
		it('maps one bound id onto its option and emits one id back', async () => {
			const wrapper = mount(AlgorithmSelect, {
				props: { modelValue: 'sha256', algorithms: ALGORITHMS },
			})
			expect(wrapper.find('.nc-select').text()).toBe('sha256')

			await wrapper.findComponent({ name: 'NcSelect' })
				.vm.$emit('update:modelValue', { id: 'md5', label: 'MD5' })
			expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toBe('md5')
		})

		it('shows the leading pseudo-option for its id and falls back to it', async () => {
			// "All algorithms" on a filter, "Default (SHA1)" on a preference: the
			// caller stores '' for it, and an id no longer offered shows it too
			// rather than a blank.
			const leading = { id: '', label: 'All algorithms' }
			const wrapper = mount(AlgorithmSelect, {
				props: { modelValue: '', algorithms: ALGORITHMS, leading },
			})
			expect(wrapper.find('.nc-select').text()).toBe('')

			await wrapper.setProps({ modelValue: 'whirlpool' })
			expect(wrapper.find('.nc-select').text()).toBe('')

			await wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', null)
			expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toBe('')
		})
	})
})
