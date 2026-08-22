import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import HelpPopover from './HelpPopover.vue'

vi.mock('@nextcloud/vue/components/NcPopover', () => ({
	default: {
		name: 'NcPopover',
		template: '<div class="nc-popover"><slot name="trigger" /><slot /></div>',
	},
}))

describe('HelpPopover', () => {
	it('renders a labelled trigger and the help text', () => {
		const wrapper = mount(HelpPopover, { props: { text: 'What this does.', label: 'Mode' } })
		expect(wrapper.find('button').attributes('aria-label')).toBe('Help: Mode')
		expect(wrapper.text()).toContain('What this does.')
	})

	it('renders nothing without a help text', () => {
		const wrapper = mount(HelpPopover, { props: { label: 'Mode' } })
		expect(wrapper.find('button').exists()).toBe(false)
	})

	it('escapes the help text instead of treating it as markup', () => {
		const wrapper = mount(HelpPopover, { props: { text: '<b>bold</b>', label: 'Path' } })
		expect(wrapper.find('.fcias-help-body').element.childElementCount).toBe(0)
		expect(wrapper.find('.fcias-help-body').text()).toBe('<b>bold</b>')
	})
})
