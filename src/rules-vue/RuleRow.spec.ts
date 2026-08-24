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
		const pathCell = wrapper.findAll('td')[3]

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
		expect(wrapper.findAll('td')[1].text()).toBe('0')
	})

	it('shows a Priority column and Yes/No enforced text for the admin variant', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ admin_enforced: true }), variant: 'admin', index: 2 },
		})
		expect(wrapper.findAll('td')[1].text()).toBe('3')
		expect(wrapper.text()).toContain('Yes')
	})

	it('shows the Priority column and a disabled checkbox for the personal variant', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ admin_enforced: true, canEdit: true }), variant: 'personal', index: 1 },
		})
		// Personal rules are an ordered subset evaluated first-match-wins, so
		// their position is a real priority and is shown like the admin page's.
		expect(wrapper.findAll('td')[1].text()).toBe('2')
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

	it('renders a drag handle only when canDrag is true', () => {
		const withHandle = mount(RuleRow, { props: { rule: makeRule(), variant: 'admin', canDrag: true } })
		expect(withHandle.find('.fcias-drag-handle').exists()).toBe(true)
		expect(withHandle.find('.fcias-drag-handle').attributes('draggable')).toBe('true')

		const withoutHandle = mount(RuleRow, { props: { rule: makeRule(), variant: 'admin', canDrag: false } })
		expect(withoutHandle.find('.fcias-drag-handle').exists()).toBe(false)
		// The cell itself is always present, so the column grid stays
		// identical whether or not this particular row is draggable.
		expect(withoutHandle.find('.fcias-drag-handle-cell').exists()).toBe(true)
	})

	it('applies dragging/drag-over classes from props', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule(), variant: 'admin', isDragging: true, isDragOver: true },
		})
		expect(wrapper.classes()).toContain('fcias-dragging')
		expect(wrapper.classes()).toContain('fcias-drag-over')
	})

	it('forwards drag events tied to its own rule', async () => {
		const rule = makeRule()
		const wrapper = mount(RuleRow, { props: { rule, variant: 'admin', canDrag: true } })

		await wrapper.find('.fcias-drag-handle').trigger('dragstart')
		await wrapper.find('.fcias-drag-handle').trigger('dragend')
		await wrapper.trigger('dragover')
		await wrapper.trigger('dragleave')
		await wrapper.trigger('drop')

		expect(wrapper.emitted('row-dragstart')?.[0]?.[0]).toEqual(rule)
		expect(wrapper.emitted('row-dragend')).toBeTruthy()
		expect(wrapper.emitted('row-dragover')?.[0]?.[0]).toEqual(rule)
		expect(wrapper.emitted('row-dragleave')?.[0]?.[0]).toEqual(rule)
		expect(wrapper.emitted('row-drop')?.[0]?.[0]).toEqual(rule)
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
