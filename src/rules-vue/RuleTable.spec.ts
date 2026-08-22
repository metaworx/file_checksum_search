import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import RuleTable from './RuleTable.vue'
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

describe('RuleTable', () => {
	it('shows the admin empty-state message when there are no rules', () => {
		const wrapper = mount(RuleTable, { props: { rules: [], variant: 'admin' } })
		expect(wrapper.text()).toContain('No additional rules.')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('shows the personal empty-state message when there are no rules', () => {
		const wrapper = mount(RuleTable, { props: { rules: [], variant: 'personal', canEditAny: true } })
		expect(wrapper.text()).toContain('No rules.')
	})

	it('shows the "not allowed to edit" banner only for the personal variant when canEditAny is false', () => {
		const withBanner = mount(RuleTable, { props: { rules: [], variant: 'personal', canEditAny: false } })
		expect(withBanner.text()).toContain('not allowed to edit')

		const adminNoBanner = mount(RuleTable, { props: { rules: [], variant: 'admin' } })
		expect(adminNoBanner.text()).not.toContain('not allowed to edit')
	})

	it('renders one RuleRow per rule and re-emits its events', async () => {
		const rules = [makeRule({ id: 1, path: '/a' }), makeRule({ id: 2, path: '/b' })]
		const wrapper = mount(RuleTable, { props: { rules, variant: 'admin' } })

		const rows = wrapper.findAll('tbody tr')
		expect(rows).toHaveLength(2)

		await rows[1].find('button[data-action="delete"]').trigger('click')
		expect(wrapper.emitted('delete')?.[0]).toEqual([rules[1]])
	})
})
