import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import RecalcButton from './RecalcButton.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app: string, text: string) => text,
}))

vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: { name: 'NcLoadingIcon', render: () => null },
}))

describe('RecalcButton', () => {
	it('renders the label when idle', () => {
		const wrapper = mount(RecalcButton, {
			props: { algo: 'sha1', label: 'SHA-1', recalculating: null, recalcError: null },
		})
		expect(wrapper.text()).toContain('SHA-1')
	})

	it('emits the algorithm on click', async () => {
		const wrapper = mount(RecalcButton, {
			props: { algo: 'sha1', label: 'SHA-1', recalculating: null, recalcError: null },
		})
		await wrapper.find('button').trigger('click')
		expect(wrapper.emitted('recalc')?.[0]).toEqual(['sha1'])
	})

	it('shows the error label when the algorithm errored', () => {
		const wrapper = mount(RecalcButton, {
			props: { algo: 'sha1', label: 'SHA-1', recalculating: null, recalcError: 'sha1' },
		})
		expect(wrapper.text()).toContain('Error')
	})

	it('omits data-algo for the generic button', () => {
		const wrapper = mount(RecalcButton, {
			props: { algo: null, label: 'Recalc', recalculating: null, recalcError: null },
		})
		expect(wrapper.find('button').attributes('data-algo')).toBeUndefined()
	})

	it('emits null for the generic button', async () => {
		const wrapper = mount(RecalcButton, {
			props: { algo: null, label: 'Recalc', recalculating: null, recalcError: null },
		})
		await wrapper.find('button').trigger('click')
		expect(wrapper.emitted('recalc')?.[0]).toEqual([null])
	})
})
