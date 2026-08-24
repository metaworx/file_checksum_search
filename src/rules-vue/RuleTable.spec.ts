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

	describe('drag-and-drop reorder', () => {
		it('renders the drag-handle column on every instance, live only where reorderable', () => {
			const rules = [makeRule({ id: 1 })]

			const off = mount(RuleTable, { props: { rules, variant: 'admin' } })
			expect(off.find('.fcias-drag-handle-cell').exists()).toBe(true)
			expect(off.find('.fcias-drag-handle').exists()).toBe(false)

			const on = mount(RuleTable, { props: { rules, variant: 'admin', reorderable: true } })
			expect(on.find('.fcias-drag-handle').exists()).toBe(true)
		})

		it('emits reorder with the new order after dragging one row onto another (admin)', async () => {
			const rules = [makeRule({ id: 'r1' }), makeRule({ id: 'r2' }), makeRule({ id: 'r3' })]
			const wrapper = mount(RuleTable, { props: { rules, variant: 'admin', reorderable: true } })
			const rows = wrapper.findAll('tbody tr')

			// Drag r1 onto r3's position.
			await rows[0].find('.fcias-drag-handle').trigger('dragstart')
			await rows[2].trigger('dragover')
			await rows[2].trigger('drop')

			expect(wrapper.emitted('reorder')?.[0]).toEqual([['r2', 'r3', 'r1']])
		})

		it('excludes locked rows from the payload but still allows dropping onto them (personal)', async () => {
			const rules = [
				makeRule({ id: 'locked', canEdit: false }),
				makeRule({ id: 'r1', canEdit: true }),
				makeRule({ id: 'r2', canEdit: true }),
			]
			const wrapper = mount(RuleTable, { props: { rules, variant: 'personal', reorderable: true } })
			const rows = wrapper.findAll('tbody tr')

			// The locked row has no handle to drag from...
			expect(rows[0].find('.fcias-drag-handle').exists()).toBe(false)

			// ...but dragging r2 onto the locked row's position is a valid drop:
			// the locked row itself never moves, and only the mutable IDs are
			// reported, in their new relative order.
			await rows[2].find('.fcias-drag-handle').trigger('dragstart')
			await rows[0].trigger('dragover')
			await rows[0].trigger('drop')

			expect(wrapper.emitted('reorder')?.[0]).toEqual([['r2', 'r1']])
		})

		it('does nothing when a row is dropped on itself', async () => {
			const rules = [makeRule({ id: 'r1' }), makeRule({ id: 'r2' })]
			const wrapper = mount(RuleTable, { props: { rules, variant: 'admin', reorderable: true } })
			const rows = wrapper.findAll('tbody tr')

			await rows[0].find('.fcias-drag-handle').trigger('dragstart')
			await rows[0].trigger('dragover')
			await rows[0].trigger('drop')

			expect(wrapper.emitted('reorder')).toBeUndefined()
		})

		it('clears the dragging state on dragend without a drop', async () => {
			const rules = [makeRule({ id: 'r1' }), makeRule({ id: 'r2' })]
			const wrapper = mount(RuleTable, { props: { rules, variant: 'admin', reorderable: true } })
			const rows = wrapper.findAll('tbody tr')

			await rows[0].find('.fcias-drag-handle').trigger('dragstart')
			expect(rows[0].classes()).toContain('fcias-dragging')

			await rows[0].find('.fcias-drag-handle').trigger('dragend')
			expect(wrapper.find('tbody tr').classes()).not.toContain('fcias-dragging')
		})
	})
})
