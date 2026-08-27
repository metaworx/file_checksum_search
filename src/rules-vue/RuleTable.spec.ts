import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import RuleTable from './RuleTable.vue'
import type { Rule } from './types'

// The real one pulls in a stylesheet Vitest cannot load; only its trigger slot
// matters here, and that is what the help icon lives in.
vi.mock('@nextcloud/vue/components/NcPopover', () => ({
	default: {
		name: 'NcPopover',
		template: '<div class="nc-popover"><slot name="trigger" /><slot /></div>',
	},
}))

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

/** Rule rows only — the band header rows are <tr> too. */
function ruleRows(wrapper: ReturnType<typeof mount>) {
	return wrapper.findAll('tbody tr[data-id]')
}

describe('RuleTable', () => {
	it('shows the admin empty-state message when there are no rules', () => {
		const wrapper = mount(RuleTable, { props: { rules: [], variant: 'admin' } })
		expect(wrapper.text()).toContain('No rules yet.')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('shows the personal empty-state message when there are no rules', () => {
		const wrapper = mount(RuleTable, { props: { rules: [], variant: 'personal', canEditAny: true } })
		expect(wrapper.text()).toContain('No rules apply to your files.')
	})

	it('shows the "not allowed to edit" banner only for the personal variant when canEditAny is false', () => {
		const withBanner = mount(RuleTable, { props: { rules: [], variant: 'personal', canEditAny: false } })
		expect(withBanner.text()).toContain('not allowed to edit')

		const adminNoBanner = mount(RuleTable, { props: { rules: [], variant: 'admin' } })
		expect(adminNoBanner.text()).not.toContain('not allowed to edit')
	})

	it('renders one row per rule and re-emits its events', async () => {
		const rules = [makeRule({ id: 1, path: '/a' }), makeRule({ id: 2, path: '/b', position: 2 })]
		const wrapper = mount(RuleTable, { props: { rules, variant: 'admin' } })

		expect(ruleRows(wrapper)).toHaveLength(2)

		await ruleRows(wrapper)[1].find('button[data-action="delete"]').trigger('click')
		expect(wrapper.emitted('delete')?.[0]).toEqual([rules[1]])
	})

	describe('bands', () => {
		it('opens each band with a labelled header row', () => {
			const rules = [
				makeRule({ id: 'e1', band: 1, selector: 'home:alice', admin_enforced: true }),
				makeRule({ id: 'u1', band: 5, selector: 'home:alice' }),
				makeRule({ id: 'u2', band: 5, selector: 'home:alice', position: 2 }),
				makeRule({ id: 'd1', band: 8, selector: '*', isDefault: true }),
			]
			const wrapper = mount(RuleTable, { props: { rules, variant: 'admin' } })

			// One header per band, not per row — and carrying words, so the
			// grouping does not depend on telling the tints apart.
			const headers = wrapper.findAll('tbody tr.fcias-band-header')
			expect(headers).toHaveLength(3)
			expect(headers[0].text()).toContain('Enforced — specific')
			expect(headers[1].text()).toContain('Specific rules')
			expect(headers[2].text()).toContain('Everything')
		})

		it('explains each band on its header row', () => {
			const rules = [makeRule({ id: 'd1', band: 7, pinned: true })]
			const wrapper = mount(RuleTable, { props: { rules, variant: 'admin' } })

			const help = wrapper.find('tbody tr.fcias-band-header .fcias-help-icon')
			expect(help.exists()).toBe(true)
			expect(help.attributes('aria-label')).toBe('Help: Band 7')
		})

		it('explains every named column', () => {
			const wrapper = mount(RuleTable, { props: { rules: [makeRule()], variant: 'admin' } })

			// A band, a position and an enforced flag are all derived values; a
			// one-word heading does not tell a reader what any of them mean.
			const labelled = wrapper.findAll('thead th[data-column]')
			expect(labelled.map((th) => th.attributes('data-column'))).toEqual([
				'priority', 'scope', 'path', 'type', 'algos', 'mode', 'status', 'enforced',
			])
			for (const th of labelled) {
				expect(th.find('.fcias-help-icon').exists()).toBe(true)
			}
		})
	})

	describe('drag-and-drop reorder', () => {
		const homeAll = [
			makeRule({ id: 'g1', band: 7, position: 1, path: '/a/**' }),
			makeRule({ id: 'g2', band: 7, position: 2, path: '/b/**' }),
			makeRule({ id: 'g3', band: 7, position: 3, path: '/c/**' }),
		]

		it('renders handles only when reorderable', () => {
			const off = mount(RuleTable, { props: { rules: homeAll, variant: 'admin' } })
			expect(off.find('.fcias-drag-handle').exists()).toBe(false)
			// The cell stays either way, so the column grid never shifts.
			expect(off.find('.fcias-drag-handle-cell').exists()).toBe(true)

			const on = mount(RuleTable, { props: { rules: homeAll, variant: 'admin', reorderable: true } })
			expect(on.findAll('.fcias-drag-handle')).toHaveLength(3)
		})

		it('emits the new order for the dragged row\'s segment partition', async () => {
			const wrapper = mount(RuleTable, { props: { rules: homeAll, variant: 'admin', reorderable: true } })
			const rows = ruleRows(wrapper)

			await rows[0].find('.fcias-drag-handle').trigger('dragstart')
			await rows[2].trigger('dragover')
			await rows[2].trigger('drop')

			expect(wrapper.emitted('reorder')?.[0]).toEqual([
				{ selector: 'home:*', defaults: false, orderedIds: ['g2', 'g3', 'g1'] },
			])
		})

		it('refuses a drop into a different segment', async () => {
			const rules = [
				makeRule({ id: 'u1', band: 5, selector: 'home:alice' }),
				makeRule({ id: 'g1', band: 7 }),
			]
			const wrapper = mount(RuleTable, { props: { rules, variant: 'admin', reorderable: true } })
			const rows = ruleRows(wrapper)

			// Dragging a user rule onto a default would promote it past every
			// rule between — exactly what bands exist to prevent.
			await rows[0].find('.fcias-drag-handle').trigger('dragstart')
			await rows[1].trigger('dragover')
			await rows[1].trigger('drop')

			expect(wrapper.emitted('reorder')).toBeUndefined()
		})

		it('keeps different users\' segments apart even inside one band', async () => {
			const rules = [
				makeRule({ id: 'a1', band: 5, selector: 'home:alice', position: 1, path: '/a/**' }),
				makeRule({ id: 'a2', band: 5, selector: 'home:alice', position: 2, path: '/b/**' }),
				makeRule({ id: 'b1', band: 5, selector: 'home:bob', position: 3, path: '/c/**' }),
			]
			const wrapper = mount(RuleTable, { props: { rules, variant: 'admin', reorderable: true } })
			const rows = ruleRows(wrapper)

			// Bob's rule is in the same band but is a different owner's
			// segment, so it is not a legal target.
			await rows[0].find('.fcias-drag-handle').trigger('dragstart')
			await rows[2].trigger('dragover')
			await rows[2].trigger('drop')
			expect(wrapper.emitted('reorder')).toBeUndefined()

			// Within alice's own segment it works, and the payload carries
			// only her rules.
			await rows[1].find('.fcias-drag-handle').trigger('dragstart')
			await rows[0].trigger('dragover')
			await rows[0].trigger('drop')

			expect(wrapper.emitted('reorder')?.[0]).toEqual([
				{ selector: 'home:alice', defaults: false, orderedIds: ['a2', 'a1'] },
			])
		})

		it('refuses a drop across the defaults partition', async () => {
			// A default can be reordered among defaults, never dragged above
			// the segment's specific rules — the old inversion cannot recur.
			const rules = [
				makeRule({ id: 'g1', band: 7, path: '/a/**' }),
				makeRule({ id: 'd1', band: 7, path: '**', isDefault: true }),
			]
			const wrapper = mount(RuleTable, { props: { rules, variant: 'admin', reorderable: true } })
			const rows = ruleRows(wrapper)

			await rows[1].find('.fcias-drag-handle').trigger('dragstart')
			await rows[0].trigger('dragover')
			await rows[0].trigger('drop')

			expect(wrapper.emitted('reorder')).toBeUndefined()
		})

		it('never offers a handle for a rule the caller may not edit', () => {
			const rules = [makeRule({ id: 'locked', band: 3, canEdit: false })]
			const wrapper = mount(RuleTable, { props: { rules, variant: 'personal', reorderable: true } })

			expect(wrapper.find('.fcias-drag-handle').exists()).toBe(false)
		})

		it('does nothing when a row is dropped on itself', async () => {
			const wrapper = mount(RuleTable, { props: { rules: homeAll, variant: 'admin', reorderable: true } })
			const rows = ruleRows(wrapper)

			await rows[0].find('.fcias-drag-handle').trigger('dragstart')
			await rows[0].trigger('dragover')
			await rows[0].trigger('drop')

			expect(wrapper.emitted('reorder')).toBeUndefined()
		})

		it('clears the dragging state on dragend without a drop', async () => {
			const wrapper = mount(RuleTable, { props: { rules: homeAll, variant: 'admin', reorderable: true } })
			const rows = ruleRows(wrapper)

			await rows[0].find('.fcias-drag-handle').trigger('dragstart')
			expect(ruleRows(wrapper)[0].classes()).toContain('fcias-dragging')

			await rows[0].find('.fcias-drag-handle').trigger('dragend')
			expect(ruleRows(wrapper)[0].classes()).not.toContain('fcias-dragging')
		})
	})
})
