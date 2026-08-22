import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import RuleRow from './RuleRow.vue'
import type { Rule } from './types'

function makeRule(overrides: Partial<Rule> = {}): Rule {
	return {
		id: 1,
		enabled: true,
		mode: 'auto',
		algos: ['sha1'],
		path: '/docs',
		userScope: 'all',
		admin_enforced: false,
		...overrides,
	}
}

describe('RuleRow', () => {
	it('renders rule fields as text, auto-escaping unsafe content', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ path: '<script>alert(1)</script>' }), variant: 'admin' },
		})
		expect(wrapper.html()).not.toContain('<script>alert')
		expect(wrapper.text()).toContain('<script>alert(1)</script>')
	})

	it('shows a Priority column and Yes/No enforced text for the admin variant', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ admin_enforced: true }), variant: 'admin', index: 2 },
		})
		expect(wrapper.findAll('td')[0].text()).toBe('3')
		expect(wrapper.text()).toContain('Yes')
	})

	it('hides the Priority column and shows a disabled checkbox for the personal variant', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ admin_enforced: true, canEdit: true }), variant: 'personal' },
		})
		expect(wrapper.findAll('td')[0].text()).not.toBe('1')
		const checkbox = wrapper.find('input[type="checkbox"]')
		expect(checkbox.exists()).toBe(true)
		expect((checkbox.element as HTMLInputElement).disabled).toBe(true)
		expect((checkbox.element as HTMLInputElement).checked).toBe(true)
	})

	it('shows Read-only instead of action buttons when the personal rule is not editable', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ canEdit: false }), variant: 'personal' },
		})
		expect(wrapper.find('button[data-action="edit"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Read-only')
	})

	it('emits edit/toggle/delete with the rule payload', async () => {
		const rule = makeRule()
		const wrapper = mount(RuleRow, { props: { rule, variant: 'admin' } })

		await wrapper.find('button[data-action="edit"]').trigger('click')
		await wrapper.find('button[data-action="toggle"]').trigger('click')
		await wrapper.find('button[data-action="delete"]').trigger('click')

		expect(wrapper.emitted('edit')?.[0]).toEqual([rule])
		expect(wrapper.emitted('toggle')?.[0]).toEqual([rule])
		expect(wrapper.emitted('delete')?.[0]).toEqual([rule])
	})
})
