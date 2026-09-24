import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import RuleForm from './RuleForm.vue'
import { optionLabels, pickOption } from '../test-utils/ncSelect'

describe('RuleForm', () => {
	it('seeds defaults for a new rule and uses the admin element ids', () => {
		const wrapper = mount(RuleForm, {
			props: { rule: null, variant: 'admin', supportedAlgos: ['sha1', 'sha256'] },
		})
		expect((wrapper.find('#fcias-rule-path').element as HTMLInputElement).value).toBe('/')
		expect(wrapper.find('#fcias-rule-selector').exists()).toBe(true)
		expect(wrapper.find('#fcias-rule-admin-enforced').exists()).toBe(true)
	})

	it('seeds fields from an existing rule', () => {
		const wrapper = mount(RuleForm, {
			props: {
				rule: { id: 5, path: '/existing', mode: 'force', algos: ['md5'], selector: 'home:alice', admin_enforced: true },
				variant: 'admin',
				supportedAlgos: ['sha1', 'md5'],
			},
		})
		expect((wrapper.find('#fcias-rule-path').element as HTMLInputElement).value).toBe('/existing')
		expect((wrapper.find('#fcias-rule-mode').element as HTMLSelectElement).value).toBe('force')
	})

	it('hides admin-only fields for the personal variant and uses personal element ids', () => {
		const wrapper = mount(RuleForm, {
			props: { rule: null, variant: 'personal', supportedAlgos: ['sha1'] },
		})
		expect(wrapper.find('#fcias-personal-path').exists()).toBe(true)
		expect(wrapper.find('#fcias-rule-selector').exists()).toBe(false)
		expect(wrapper.find('#fcias-personal-selector').exists()).toBe(false)
		expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
	})

	it('emits save with the edited path and mode', async () => {
		const wrapper = mount(RuleForm, {
			props: { rule: null, variant: 'admin', supportedAlgos: ['sha1'] },
		})
		await wrapper.find('#fcias-rule-path').setValue('/new-path')
		await wrapper.find('#fcias-rule-mode').setValue('force')
		await wrapper.find('#fcias-btn-save-rule').trigger('click')

		const payload = wrapper.emitted('save')?.[0]?.[0] as { path: string; mode: string }
		expect(payload.path).toBe('/new-path')
		expect(payload.mode).toBe('force')
	})

	// The dialog has no X and no click-outside (`no-close`); Escape is the
	// form's own document listener, and this is what it tests.
	it('cancels on an Escape that reaches the document', async () => {
		const wrapper = mount(RuleForm, {
			props: { rule: null, variant: 'admin', supportedAlgos: ['sha1'] },
		})
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		await flushPromises()

		expect(wrapper.emitted('cancel')).toHaveLength(1)
		expect(wrapper.emitted('save')).toBeUndefined()
	})

	it('emits cancel', async () => {
		const wrapper = mount(RuleForm, {
			props: { rule: null, variant: 'personal', supportedAlgos: ['sha1'] },
		})
		await wrapper.find('#fcias-personal-cancel').trigger('click')
		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})

	describe('type selector', () => {
		it('drops Algorithms and Mode for a rule that computes nothing', async () => {
			const wrapper = mount(RuleForm, {
				props: { rule: null, variant: 'admin', supportedAlgos: ['sha1'] },
			})
			expect(wrapper.find('#fcias-rule-mode').exists()).toBe(true)
			expect(wrapper.find('#fcias-rule-algos').exists()).toBe(true)

			await wrapper.find('#fcias-rule-type').setValue('exclude')

			// An exclude rule never hashes, so asking which algorithms it uses
			// would be a field with no meaning.
			expect(wrapper.find('#fcias-rule-mode').exists()).toBe(false)
			expect(wrapper.find('#fcias-rule-algos').exists()).toBe(false)
		})

		it('saves the chosen type and defaults an untyped rule to include', async () => {
			const wrapper = mount(RuleForm, {
				props: { rule: null, variant: 'personal', supportedAlgos: ['sha1'] },
			})
			await wrapper.find('#fcias-personal-type').setValue('ignore')
			await wrapper.find('#fcias-personal-save').trigger('click')
			expect((wrapper.emitted('save')?.[0]?.[0] as { type: string }).type).toBe('ignore')

			const seeded = mount(RuleForm, {
				props: {
					rule: { id: 7, path: '/a', mode: 'auto', algos: ['sha1'], userScope: 'all', admin_enforced: false },
					variant: 'personal',
					supportedAlgos: ['sha1'],
				},
			})
			expect((seeded.find('#fcias-personal-type').element as HTMLSelectElement).value).toBe('include')
		})
	})

	describe('selector controls', () => {
		it('reveals a group picker and composes the group selector string', async () => {
			const wrapper = mount(RuleForm, {
				props: {
					rule: null,
					variant: 'admin',
					supportedAlgos: ['sha1'],
					availableGroups: ['staff', 'admin'],
				},
			})
			expect(wrapper.find('#fcias-rule-selector-target').exists()).toBe(false)

			await wrapper.find('#fcias-rule-selector').setValue('group')
			// The real picker: its menu offers the groups, and one is clicked.
			expect(await optionLabels(wrapper, 'fcias-rule-selector-target')).toEqual(['staff', 'admin'])
			await pickOption(wrapper, 'staff', 'fcias-rule-selector-target')
			await wrapper.find('#fcias-btn-save-rule').trigger('click')

			// Two controls in the dialog, one string on the wire.
			expect((wrapper.emitted('save')?.[0]?.[0] as { selector: string }).selector).toBe('group:staff')
		})

		it('splits an existing group selector back into its two controls', () => {
			const wrapper = mount(RuleForm, {
				props: {
					rule: { id: 3, path: '/a', mode: 'auto', algos: ['sha1'], selector: 'group:staff', admin_enforced: false },
					variant: 'admin',
					supportedAlgos: ['sha1'],
					availableGroups: ['staff'],
				},
			})
			expect((wrapper.find('#fcias-rule-selector').element as HTMLSelectElement).value).toBe('group')
			// The picker says which option it holds through its selected-option
			// slot — the id, not only the label, which is what the slot is for.
			expect(wrapper.find('.vs__selected [data-selected-id="staff"]').exists()).toBe(true)
		})
	})

	describe('group folder picker', () => {
		it('offers no group-folder option when the app is unavailable', () => {
			const wrapper = mount(RuleForm, {
				props: { rule: null, variant: 'admin', supportedAlgos: ['sha1'] },
			})

			const kinds = wrapper.find('#fcias-rule-selector').findAll('option')
				.map((option) => option.attributes('value'))
			expect(kinds).not.toContain('groupfolder')
		})

		it('offers group folders by name and stores the folder id', async () => {
			const wrapper = mount(RuleForm, {
				props: {
					rule: null,
					variant: 'admin',
					supportedAlgos: ['sha1'],
					groupFoldersAvailable: true,
					availableGroupFolders: [{ id: 1, name: 'Team Docs' }],
				},
			})

			await wrapper.find('#fcias-rule-selector').setValue('groupfolder')

			expect(await optionLabels(wrapper, 'fcias-rule-selector-target')).toEqual(['Team Docs (#1)'])
			await pickOption(wrapper, 'Team Docs (#1)', 'fcias-rule-selector-target')
			await wrapper.find('#fcias-btn-save-rule').trigger('click')

			// The name is display only; the selector stores the id.
			expect((wrapper.emitted('save')?.[0]?.[0] as { selector: string }).selector).toBe('groupfolder:1')
		})

		it('keeps the raw text input for storage ids', async () => {
			const wrapper = mount(RuleForm, {
				props: { rule: null, variant: 'admin', supportedAlgos: ['sha1'] },
			})

			await wrapper.find('#fcias-rule-selector').setValue('storage')

			const input = wrapper.find('#fcias-rule-selector-target')
			expect(input.element.tagName).toBe('INPUT')

			await input.setValue('smb::user@host//share/')
			await wrapper.find('#fcias-btn-save-rule').trigger('click')

			expect((wrapper.emitted('save')?.[0]?.[0] as { selector: string }).selector)
				.toBe('storage:smb::user@host//share/')
		})
	})

	describe('kind changes', () => {
		it('clears the picked target when "Applies to" changes', async () => {
			const wrapper = mount(RuleForm, {
				props: {
					rule: null,
					variant: 'admin',
					supportedAlgos: ['sha1'],
					availableUsers: ['alice'],
					groupFoldersAvailable: true,
					availableGroupFolders: [{ id: 1, name: 'Team Docs' }],
				},
			})

			await wrapper.find('#fcias-rule-selector').setValue('user')
			await pickOption(wrapper, 'alice', 'fcias-rule-selector-target')
			expect(wrapper.find('.vs__selected [data-selected-id="alice"]').exists()).toBe(true)

			// Switching the kind must not carry alice into a group-folder
			// selector: a stale target composes a rule nobody asked for.
			await wrapper.find('#fcias-rule-selector').setValue('groupfolder')

			// The target pickers are the ones that render `data-selected-id`;
			// the algorithm picker beside them keeps its own selection.
			expect(wrapper.find('#fcias-rule-selector-target').exists()).toBe(true)
			expect(wrapper.find('[data-selected-id]').exists()).toBe(false)

			await wrapper.find('#fcias-btn-save-rule').trigger('click')
			expect((wrapper.emitted('save')?.[0]?.[0] as { selector: string }).selector).toBe('')
		})

		it('keeps the seeded target when editing an existing rule', () => {
			const wrapper = mount(RuleForm, {
				props: {
					rule: { id: 3, path: '/a', mode: 'auto', algos: ['sha1'], selector: 'home:alice', admin_enforced: false },
					variant: 'admin',
					supportedAlgos: ['sha1'],
					availableUsers: ['alice'],
				},
			})

			// Seeding is programmatic, not a user interaction — the target
			// survives it, and the picker shows it.
			expect(wrapper.find('.vs__selected [data-selected-id="alice"]').exists()).toBe(true)
		})
	})

	describe('naming', () => {
		it('speaks the groupfolders app\'s own name when it supplies one', async () => {
			const wrapper = mount(RuleForm, {
				props: {
					rule: null,
					variant: 'admin',
					supportedAlgos: ['sha1'],
					groupFoldersAvailable: true,
					groupFoldersLabel: 'Team Folders',
					availableGroupFolders: [{ id: 1, name: 'Team Docs' }],
				},
			})

			const kindSelect = wrapper.find('#fcias-rule-selector')
			expect(kindSelect.text()).toContain('Team Folders')

			await kindSelect.setValue('groupfolder')
			expect(wrapper.find('.fcias-rule-form-row label[for="fcias-rule-selector-target"]').text())
				.toBe('Team Folders')
		})
	})

	describe('save errors', () => {
		it('shows the failure inside the dialog, where the form still is', () => {
			const wrapper = mount(RuleForm, {
				props: {
					rule: null,
					variant: 'admin',
					supportedAlgos: ['sha1'],
					errorMessage: 'The server answered 404 Not Found.',
				},
			})

			expect(wrapper.find('.fcias-form-error').text()).toContain('404')
		})

		it('shows no error card without a message', () => {
			const wrapper = mount(RuleForm, {
				props: { rule: null, variant: 'admin', supportedAlgos: ['sha1'] },
			})

			expect(wrapper.find('.fcias-form-error').exists()).toBe(false)
		})
	})

	describe('band preview', () => {
		it('previews the band from the kind alone, before any target is picked', async () => {
			const wrapper = mount(RuleForm, {
				props: {
					rule: null,
					variant: 'admin',
					supportedAlgos: ['sha1'],
					availableUsers: ['alice'],
				},
			})
			const preview = () => wrapper.find('.fcias-band-preview').text()

			// Regression: with the preview derived from the composed selector,
			// an empty target made every kind read as band 8 — Everything.
			await wrapper.find('#fcias-rule-selector').setValue('user')
			expect(preview()).toContain('band 5')

			await wrapper.find('#fcias-rule-selector').setValue('group')
			expect(preview()).toContain('band 6')
		})

		it('follows the scope and enforced controls while editing', async () => {
			const wrapper = mount(RuleForm, {
				props: {
					rule: null,
					variant: 'admin',
					supportedAlgos: ['sha1'],
					availableUsers: ['alice'],
				},
			})
			const preview = () => wrapper.find('.fcias-band-preview').text()

			// Band is derived, never chosen — the preview is what makes that
			// legible before saving rather than only after.
			expect(preview()).toContain('band 7')
			expect(preview()).toContain('All home folders')

			await wrapper.find('#fcias-rule-selector').setValue('user')
			await pickOption(wrapper, 'alice', 'fcias-rule-selector-target')
			expect(preview()).toContain('band 5')

			// The switch's id lands on its input, which is the control.
			await wrapper.find('#fcias-rule-admin-enforced').setValue(true)
			expect(preview()).toContain('band 1')
			expect(preview()).toContain('Enforced — specific')
		})

		it('moves the universal selector between bands 8 and 4 with the enforced flag', async () => {
			const wrapper = mount(RuleForm, {
				props: {
					rule: {
						id: 'd1',
						path: '**',
						mode: 'auto',
						algos: ['sha1'],
						selector: '*',
						admin_enforced: false,
					},
					variant: 'admin',
					supportedAlgos: ['sha1'],
				},
			})
			expect(wrapper.find('.fcias-band-preview').text()).toContain('band 8')

			await wrapper.find('#fcias-rule-admin-enforced').setValue(true)
			expect(wrapper.find('.fcias-band-preview').text()).toContain('band 4')
		})
	})
})
