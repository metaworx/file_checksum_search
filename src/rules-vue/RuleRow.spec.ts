import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import RuleRow from './RuleRow.vue'
import type { Rule } from './types'
import { clickAction, hasAction, openActions } from '../test-utils/ncActions'

function makeRule(overrides: Partial<Rule> = {}): Rule {
	return {
		id: 1,
		enabled: true,
		mode: 'auto',
		algos: ['sha1'],
		path: '/docs',
		selector: 'home:*',
		admin_enforced: false,
		band: 7,
		position: 1,
		canEdit: true,
		...overrides,
	}
}

/** Column order: handle, priority, scope, path, type, algos, mode, status, enforced, actions. */
const COL = {
	handle: 0,
	priority: 1,
	scope: 2,
	path: 3,
	type: 4,
	algos: 5,
	mode: 6,
} as const

describe('RuleRow', () => {
	it('renders rule fields as text, auto-escaping unsafe content', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ path: '<script>alert(1)</script>' }), variant: 'admin' },
		})
		const pathCell = wrapper.findAll('td')[COL.path]

		// Asserted structurally rather than by searching the serialised HTML:
		// the cell also carries the raw value in a `title` tooltip, and HTML
		// attribute serialisation does not escape < or >, so a substring check
		// reports a payload that is in fact inert inside a quoted attribute.
		expect(wrapper.element.querySelectorAll('script')).toHaveLength(0)
		expect(pathCell.element.childElementCount).toBe(0)
		expect(pathCell.text()).toBe('<script>alert(1)</script>')
		expect(pathCell.attributes('title')).toBe('<script>alert(1)</script>')
	})

	it('shows the server-computed priority as <band>.<position>', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ band: 4, position: 2 }), variant: 'admin' },
		})
		expect(wrapper.findAll('td')[COL.priority].text()).toBe('4.2')
	})

	it('labels the band on the row itself, not by colour alone', () => {
		const wrapper = mount(RuleRow, { props: { rule: makeRule({ band: 1 }), variant: 'admin' } })

		// The tint is a class, but the number is also rendered as text — a
		// reader who cannot distinguish the colours still gets the grouping.
		expect(wrapper.classes()).toContain('fcias-band-1')
		expect(wrapper.findAll('td')[COL.priority].text()).toBe('1.1')
	})

	it('reads selectors as words rather than raw prefixes', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ selector: 'group:staff' }), variant: 'admin' },
		})
		expect(wrapper.findAll('td')[COL.scope].text()).toBe('Group: staff')

		const universal = mount(RuleRow, {
			props: { rule: makeRule({ selector: '*' }), variant: 'admin' },
		})
		expect(universal.findAll('td')[COL.scope].text()).toBe('Everything')
	})

	// One glyph per kind of place, before the words, titled with the kind.
	it.each([
		['home:alice', 'user'],
		['group:staff', 'group'],
		['home:*', 'homeAll'],
		['groupfolder:3', 'groupfolder'],
		['storage:7', 'storage'],
		['*', 'universal'],
	])('marks the scope %s with the %s glyph', (selector, kind) => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ selector }), variant: 'admin' },
		})
		const icon = wrapper.findAll('td')[COL.scope].find('.fcias-location-icon')
		expect(icon.attributes('data-kind')).toBe(kind)
		expect(icon.attributes('title')).not.toBe('')
	})

	it('shows no algorithms or mode for a rule that computes nothing', () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ type: 'exclude' }), variant: 'admin' },
		})

		expect(wrapper.findAll('td')[COL.type].text()).toBe('exclude')
		expect(wrapper.findAll('td')[COL.algos].text()).toBe('—')
		expect(wrapper.findAll('td')[COL.mode].text()).toBe('—')
	})

	it('treats a rule with no type as include', () => {
		const wrapper = mount(RuleRow, { props: { rule: makeRule(), variant: 'admin' } })

		expect(wrapper.findAll('td')[COL.type].text()).toBe('include')
		expect(wrapper.findAll('td')[COL.algos].text()).toBe('sha1')
	})

	it('shows Read-only instead of action buttons when the rule is not editable', async () => {
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ canEdit: false }), variant: 'personal' },
		})
		expect(await hasAction(wrapper, 'edit')).toBe(false)
		expect(wrapper.text()).toContain('Read-only')
	})

	it('offers the full action set for a default rule too', async () => {
		// pinned is gone: a deleted shipped default is recreated (disabled)
		// by the repair step, so deleting one is reversible housekeeping.
		const wrapper = mount(RuleRow, {
			props: { rule: makeRule({ isDefault: true, band: 7 }), variant: 'admin' },
		})

		expect(await hasAction(wrapper, 'delete')).toBe(true)
		expect(await hasAction(wrapper, 'edit')).toBe(true)
		expect(await hasAction(wrapper, 'toggle')).toBe(true)
	})

	it('renders a drag handle only when canDrag is true', () => {
		const withHandle = mount(RuleRow, { props: { rule: makeRule(), variant: 'admin', canDrag: true } })
		expect(withHandle.find('.fcias-drag-handle').exists()).toBe(true)
		expect(withHandle.find('.fcias-drag-handle').attributes('draggable')).toBe('true')

		const withoutHandle = mount(RuleRow, { props: { rule: makeRule(), variant: 'admin', canDrag: false } })
		expect(withoutHandle.find('.fcias-drag-handle').exists()).toBe(false)
		// The cell stays, so the column grid is identical for every row.
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

		expect(wrapper.emitted('rowDragstart')?.[0]?.[0]).toEqual(rule)
		expect(wrapper.emitted('rowDragend')).toBeTruthy()
		expect(wrapper.emitted('rowDragover')?.[0]?.[0]).toEqual(rule)
		expect(wrapper.emitted('rowDragleave')?.[0]?.[0]).toEqual(rule)
		expect(wrapper.emitted('rowDrop')?.[0]?.[0]).toEqual(rule)
	})

	it('emits edit/toggle/delete with the rule payload', async () => {
		const rule = makeRule()
		const wrapper = mount(RuleRow, { props: { rule, variant: 'admin' } })

		// Edit is the pen beside the menu; the other two are the menu's, which
		// opens on its toggle and closes again after each choice.
		await clickAction(wrapper, 'edit')
		await clickAction(wrapper, 'toggle')
		await clickAction(wrapper, 'delete')

		expect(wrapper.emitted('edit')?.[0]).toEqual([rule])
		expect(wrapper.emitted('toggle')?.[0]).toEqual([rule])
		expect(wrapper.emitted('delete')?.[0]).toEqual([rule])
	})

	// --- actions menu ---

	it('offers Re-apply for an enabled include rule and emits apply', async () => {
		const wrapper = mount(RuleRow, { props: { rule: makeRule(), variant: 'admin' } })

		expect(await hasAction(wrapper, 'apply')).toBe(true)

		await clickAction(wrapper, 'apply')
		expect(wrapper.emitted('apply')).toHaveLength(1)
	})

	it('hides Re-apply for a disabled rule', async () => {
		const wrapper = mount(RuleRow, { props: { rule: makeRule({ enabled: false }), variant: 'admin' } })

		// The server refuses a disabled rule at submission; the menu simply
		// does not offer what cannot succeed.
		expect(await hasAction(wrapper, 'apply')).toBe(false)
		expect(await hasAction(wrapper, 'toggle')).toBe(true)
	})

	it('hides Re-apply for rules that compute nothing', async () => {
		for (const type of ['ignore', 'exclude'] as const) {
			const wrapper = mount(RuleRow, { props: { rule: makeRule({ type }), variant: 'admin' } })
			expect(await hasAction(wrapper, 'apply')).toBe(false)
		}
	})

	it('offers Edit as a pen beside the menu AND mirrored inside it', async () => {
		const wrapper = mount(RuleRow, { props: { rule: makeRule(), variant: 'admin' } })

		// The pen is the one-click shortcut; the menu carries the same entry
		// with the same icon, which is what says they are one action.
		const pen = wrapper.find('button[data-action="edit"]')
		expect(pen.classes()).toContain('fcias-icon-btn')
		expect(pen.find('svg').exists()).toBe(true)

		const menu = await openActions(wrapper)
		const entry = menu.querySelector('[data-action="edit"] button')
		expect(entry).not.toBeNull()
		expect(entry?.querySelector('svg')).not.toBeNull()
	})

	// The badge is a block beneath the name, not an inline span after it:
	// the Scope cell ellipsises, and inline it vanished behind any name
	// long enough to need one. Layout is the e2e's to measure; this holds
	// the badge to its place and its text.
	it('badges a rule whose provider is gone, beneath the name', () => {
		const wrapper = mount(RuleRow, { props: { rule: makeRule({ selector: 'groupfolder:99' }), variant: 'admin', providerMissing: true } })
		const badge = wrapper.find('.fcias-provider-missing')
		expect(badge.exists()).toBe(true)
		expect(badge.text()).toBe('provider missing')
		expect(badge.attributes('title')).toContain('can never match')
		// In the Scope cell, after the name, as the cell's last element.
		const cell = badge.element.parentElement as HTMLElement
		expect(cell.tagName).toBe('TD')
		expect(cell.textContent).toContain('99')
		expect(cell.lastElementChild).toBe(badge.element)
	})

	it('shows no menu at all on a read-only row', () => {
		const wrapper = mount(RuleRow, { props: { rule: makeRule({ canEdit: false }), variant: 'personal' } })

		expect(wrapper.find('.action-item__menutoggle').exists()).toBe(false)
		expect(wrapper.find('td.fcias-rules-actions').text()).toBe('Read-only')
	})
})
