import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import RuleTable from './RuleTable.vue'
import type { Rule } from './types'

vi.mock('@nextcloud/vue/components/NcActions', () => ({
	default: { name: 'NcActions', template: '<div class="nc-actions"><slot /></div>' },
}))
vi.mock('@nextcloud/vue/components/NcActionButton', () => ({
	default: { name: 'NcActionButton', template: '<button><slot /></button>' },
}))

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

		// …and, below it, what could be ruled on: with nothing stored, the
		// home and universal namespaces are both uncovered.
		expect(wrapper.findAll('tr[data-placeholder]').map((row) => row.attributes('data-placeholder')))
			.toEqual(['home:*', '*'])
	})

	it('shows the personal empty-state message when there are no rules', () => {
		const wrapper = mount(RuleTable, { props: { rules: [], variant: 'personal', canEditAny: true } })
		expect(wrapper.text()).toContain('No rules apply to your files.')
	})

	// Before the first answer the flag is only its initial value and the list
	// only its initial emptiness; saying either would be saying something the
	// page does not know yet, in red.
	it('says nothing about permission or emptiness while the rules are still loading', () => {
		const wrapper = mount(RuleTable, { props: { rules: [], variant: 'personal', canEditAny: false, loading: true } })
		expect(wrapper.text()).not.toContain('not allowed to edit')
		expect(wrapper.text()).not.toContain('No rules apply')
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

	// --- placeholder rows for uncovered namespaces ---

	const folderProps = {
		groupFoldersAvailable: true,
		availableGroupFolders: [{ id: 1, name: 'Team Docs' }, { id: 2, name: 'Archive' }],
		groupFoldersLabel: 'Team Folders',
	}

	/** The four namespace kinds, all covered by a catch-all of their own. */
	const covered = [
		makeRule({ id: 'a', selector: 'storage:smb::u@h//share/', path: '**', isDefault: true }),
		makeRule({ id: 'b', selector: 'groupfolder:1', path: '**', isDefault: true }),
		makeRule({ id: 'c', selector: 'groupfolder:2', path: '**', isDefault: true }),
		makeRule({ id: 'd', selector: 'home:*', path: '**', isDefault: true }),
		makeRule({ id: 'e', selector: '*', path: '**', isDefault: true }),
	]

	it('lists every namespace kind that has no catch-all of its own', async () => {
		const wrapper = mount(RuleTable, {
			props: {
				// Folder 1 and the home namespace are ruled on; the storage,
				// folder 2 and the universal namespace are not.
				rules: [covered[1], covered[3]],
				variant: 'admin',
				availableStorages: ['smb::u@h//share/'],
				...folderProps,
			},
		})

		const rows = wrapper.findAll('tr[data-placeholder]')
		expect(rows.map((row) => row.attributes('data-placeholder')))
			.toEqual(['storage:smb::u@h//share/', 'groupfolder:2', '*'])
		expect(rows[1].text()).toContain('Team Folders: Archive (#2)')

		await rows[1].find('button[data-action="create"]').trigger('click')
		expect(wrapper.emitted('create')?.[0]?.[0]).toMatchObject({ selector: 'groupfolder:2' })
	})

	it('lists nothing once every namespace has its own catch-all', () => {
		const wrapper = mount(RuleTable, {
			props: {
				rules: covered,
				variant: 'admin',
				availableStorages: ['smb::u@h//share/'],
				...folderProps,
			},
		})

		expect(wrapper.findAll('tr[data-placeholder]')).toHaveLength(0)
	})

	it('says so differently when a namespace has rules but no catch-all', () => {
		const wrapper = mount(RuleTable, {
			props: {
				// A rule for the folder, but only for part of it.
				rules: [makeRule({ selector: 'groupfolder:1', path: 'Photos/**', isDefault: false })],
				variant: 'admin',
				...folderProps,
			},
		})

		const folderRow = wrapper.find('tr[data-placeholder="groupfolder:1"]')
		expect(folderRow.text()).toContain('no catch-all rule')
		expect(wrapper.find('tr[data-placeholder="groupfolder:2"]').text()).toContain('not covered')
	})

	it('offers no group-folder namespaces when the app is unavailable', () => {
		const wrapper = mount(RuleTable, {
			props: { rules: [makeRule()], variant: 'admin' },
		})

		const selectors = wrapper.findAll('tr[data-placeholder]')
			.map((row) => row.attributes('data-placeholder'))
		expect(selectors.some((selector) => selector?.startsWith('groupfolder:'))).toBe(false)
		// The namespaces that do not depend on that app are still listed.
		expect(selectors).toContain('home:*')
	})

	it('offers no placeholders at all on the personal page', () => {
		const wrapper = mount(RuleTable, {
			props: { rules: [makeRule()], variant: 'personal', ...folderProps },
		})

		expect(wrapper.findAll('tr[data-placeholder]')).toHaveLength(0)
	})

	it('badges a rule whose provider is gone', () => {
		const missingApp = mount(RuleTable, {
			props: { rules: [makeRule({ selector: 'groupfolder:1' })], variant: 'admin' },
		})
		expect(missingApp.find('.fcias-provider-missing').exists()).toBe(true)

		const deletedFolder = mount(RuleTable, {
			props: {
				rules: [makeRule({ selector: 'groupfolder:9' })],
				variant: 'admin',
				...folderProps,
			},
		})
		expect(deletedFolder.find('.fcias-provider-missing').exists()).toBe(true)

		const present = mount(RuleTable, {
			props: {
				rules: [makeRule({ selector: 'groupfolder:1' })],
				variant: 'admin',
				...folderProps,
			},
		})
		expect(present.find('.fcias-provider-missing').exists()).toBe(false)
	})

	it('names group folders by the app\'s word and the folder\'s own name', () => {
		const wrapper = mount(RuleTable, {
			props: {
				rules: [makeRule({ selector: 'groupfolder:1' })],
				variant: 'admin',
				...folderProps,
			},
		})

		// The app's own word for the namespace, and the folder's own name —
		// the id alone told the reader nothing.
		expect(wrapper.find('tbody tr[data-id] td:nth-child(3)').text())
			.toContain('Team Folders: Team Docs (#1)')
	})
})
