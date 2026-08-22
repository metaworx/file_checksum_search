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
		const pathCell = wrapper.findAll('td')[2]

		// Asserted structurally rather than by searching the serialised HTML:
		// the cell also carries the raw value in a `title` tooltip, and HTML
		// attribute serialisation does not escape < or >, so a substring check
		// reports a payload that is in fact inert inside a quoted attribute.
		expect(wrapper.element.querySelectorAll('script')).toHaveLength(0)
		expect(pathCell.element.childElementCount).toBe(0)
		expect(pathCell.text()).toBe('<script>alert(1)</script>')
		expect(pathCell.attributes('title')).toBe('<script>alert(1)</script>')
	})

	it('starts the Priority column at priorityOffset when given', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule(), variant: 'admin', index: 0, priorityOffset: 0 },
		})
		expect(wrapper.findAll('td')[0].text()).toBe('0')
	})

	it('shows a Priority column and Yes/No enforced text for the admin variant', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ admin_enforced: true }), variant: 'admin', index: 2 },
		})
		expect(wrapper.findAll('td')[0].text()).toBe('3')
		expect(wrapper.text()).toContain('Yes')
	})

	it('shows the Priority column and a disabled checkbox for the personal variant', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ admin_enforced: true, canEdit: true }), variant: 'personal', index: 1 },
		})
		// Personal rules are an ordered subset evaluated first-match-wins, so
		// their position is a real priority and is shown like the admin page's.
		expect(wrapper.findAll('td')[0].text()).toBe('2')
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
