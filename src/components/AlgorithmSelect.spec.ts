import { afterEach, describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import AlgorithmSelect from './AlgorithmSelect.vue'
import { optionLabels, pickOption, selectedLabels } from '../test-utils/ncSelect'

const ALGORITHMS = ['sha1', 'sha256', 'md5']

// The real NcSelect, driven through its menu: what it shows as selected is
// what the page shows, and what a click on an option emits is what the
// page receives.
describe('AlgorithmSelect', () => {
	let wrapper: VueWrapper | null = null

	afterEach(() => {
		wrapper?.unmount()
		wrapper = null
	})

	describe('multiple', () => {
		it('maps the bound ids onto the matching options', () => {
			wrapper = mount(AlgorithmSelect, {
				props: { modelValue: ['sha1', 'md5'], algorithms: ALGORITHMS, multiple: true },
			})
			expect(selectedLabels(wrapper)).toEqual(['SHA1', 'MD5'])
		})

		it('picks up algorithms that arrive after mount', async () => {
			// Regression guard: the settings pages fetch supportedAlgos and the
			// rules asynchronously, so this component mounts with an empty list.
			// It used to snapshot the selection at setup time, which filtered []
			// and left the widget permanently blank even though the server had
			// algorithms stored.
			wrapper = mount(AlgorithmSelect, {
				props: { modelValue: ['sha256'], algorithms: [] as string[], multiple: true },
			})
			expect(selectedLabels(wrapper)).toEqual([])

			await wrapper.setProps({ algorithms: ALGORITHMS })
			expect(selectedLabels(wrapper)).toEqual(['SHA256'])
		})

		it('keeps the bound order, because for an allowlist the first is the default', async () => {
			wrapper = mount(AlgorithmSelect, {
				props: { modelValue: ['sha1'], algorithms: ALGORITHMS, multiple: true },
			})
			await wrapper.setProps({ modelValue: ['md5', 'sha256'] })
			expect(selectedLabels(wrapper)).toEqual(['MD5', 'SHA256'])
		})

		it('emits the selected ids, not the option objects', async () => {
			wrapper = mount(AlgorithmSelect, {
				props: { modelValue: ['sha1'], algorithms: ALGORITHMS, multiple: true },
			})
			await pickOption(wrapper, 'SHA256')

			expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toEqual(['sha1', 'sha256'])
		})
	})

	describe('single', () => {
		it('maps one bound id onto its option and emits one id back', async () => {
			wrapper = mount(AlgorithmSelect, {
				props: { modelValue: 'sha256', algorithms: ALGORITHMS },
			})
			expect(selectedLabels(wrapper)).toEqual(['SHA256'])
			expect(await optionLabels(wrapper)).toEqual(['SHA1', 'SHA256', 'MD5'])

			await pickOption(wrapper, 'MD5')
			expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toBe('md5')
		})

		it('shows the leading pseudo-option for its id and falls back to it', async () => {
			// "All algorithms" on a filter, "Default (SHA1)" on a preference: the
			// caller stores '' for it, and an id no longer offered shows it too
			// rather than a blank.
			const leading = { id: '', label: 'All algorithms' }
			wrapper = mount(AlgorithmSelect, {
				props: { modelValue: '', algorithms: ALGORITHMS, leading },
			})
			expect(selectedLabels(wrapper)).toEqual(['All algorithms'])

			await wrapper.setProps({ modelValue: 'whirlpool' })
			expect(selectedLabels(wrapper)).toEqual(['All algorithms'])

			// Picking it back emits the id the caller stores for it.
			await wrapper.setProps({ modelValue: 'sha1' })
			await pickOption(wrapper, 'All algorithms')
			expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toBe('')
		})
	})
})
