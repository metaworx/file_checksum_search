import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import RuleForm from './RuleForm.vue'

vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: { name: 'NcSelect', render: () => null },
}))
vi.mock('@nextcloud/vue/components/NcPopover', () => ({
	default: {
		name: 'NcPopover',
		template: '<div class="nc-popover"><slot name="trigger" /><slot /></div>',
	},
}))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type'],
		emits: ['update:modelValue'],
		template: '<span class="nc-switch"><input type="checkbox" :checked="modelValue"'
			+ ' @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></span>',
	},
}))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['open', 'name', 'size'],
		emits: ['update:open'],
		template: '<div><slot /></div>',
	},
}))

describe('RuleForm', () => {
	it('seeds defaults for a new rule and uses the admin element ids', () => {
		const wrapper = mount(RuleForm, {
			props: { rule: null, variant: 'admin', supportedAlgos: ['sha1', 'sha256'] },
		})
		expect((wrapper.find('#fcias-cron-path').element as HTMLInputElement).value).toBe('/')
		expect(wrapper.find('#fcias-cron-userscope').exists()).toBe(true)
		expect(wrapper.find('#fcias-cron-admin-enforced').exists()).toBe(true)
	})

	it('seeds fields from an existing rule', () => {
		const wrapper = mount(RuleForm, {
			props: {
				rule: { id: 5, path: '/existing', mode: 'force', algos: ['md5'], selector: 'home:alice', admin_enforced: true },
				variant: 'admin',
				supportedAlgos: ['sha1', 'md5'],
			},
		})
		expect((wrapper.find('#fcias-cron-path').element as HTMLInputElement).value).toBe('/existing')
		expect((wrapper.find('#fcias-cron-mode').element as HTMLSelectElement).value).toBe('force')
	})

	it('hides admin-only fields for the personal variant and uses personal element ids', () => {
		const wrapper = mount(RuleForm, {
			props: { rule: null, variant: 'personal', supportedAlgos: ['sha1'] },
		})
		expect(wrapper.find('#fcias-personal-path').exists()).toBe(true)
		expect(wrapper.find('#fcias-cron-userscope').exists()).toBe(false)
		expect(wrapper.find('#fcias-personal-userscope').exists()).toBe(false)
		expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
	})

	it('emits save with the edited path and mode', async () => {
		const wrapper = mount(RuleForm, {
			props: { rule: null, variant: 'admin', supportedAlgos: ['sha1'] },
		})
		await wrapper.find('#fcias-cron-path').setValue('/new-path')
		await wrapper.find('#fcias-cron-mode').setValue('force')
		await wrapper.find('#fcias-btn-save-definition').trigger('click')

		const payload = wrapper.emitted('save')?.[0]?.[0] as { path: string; mode: string }
		expect(payload.path).toBe('/new-path')
		expect(payload.mode).toBe('force')
	})

	it('cancels when the dialog closes itself (Esc, the X button, a click outside)', async () => {
		const wrapper = mount(RuleForm, {
			props: { rule: null, variant: 'admin', supportedAlgos: ['sha1'] },
		})
		await wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)

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
			expect(wrapper.find('#fcias-cron-mode').exists()).toBe(true)
			expect(wrapper.find('#fcias-cron-algos').exists()).toBe(true)

			await wrapper.find('#fcias-cron-type').setValue('exclude')

			// An exclude rule never hashes, so asking which algorithms it uses
			// would be a field with no meaning.
			expect(wrapper.find('#fcias-cron-mode').exists()).toBe(false)
			expect(wrapper.find('#fcias-cron-algos').exists()).toBe(false)
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
			expect(wrapper.find('#fcias-cron-scope-target').exists()).toBe(false)

			await wrapper.find('#fcias-cron-userscope').setValue('group')
			await wrapper.find('#fcias-cron-scope-target').setValue('staff')
			await wrapper.find('#fcias-btn-save-definition').trigger('click')

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
			expect((wrapper.find('#fcias-cron-userscope').element as HTMLSelectElement).value).toBe('group')
			expect((wrapper.find('#fcias-cron-scope-target').element as HTMLSelectElement).value).toBe('staff')
		})
	})

	describe('band preview', () => {
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

			await wrapper.find('#fcias-cron-userscope').setValue('user')
			await wrapper.find('#fcias-cron-scope-target').setValue('alice')
			expect(preview()).toContain('band 5')

			// The id lands on the switch component's root; the control is its input.
			await wrapper.find('#fcias-cron-admin-enforced input').setValue(true)
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

			await wrapper.find('#fcias-cron-admin-enforced input').setValue(true)
			expect(wrapper.find('.fcias-band-preview').text()).toContain('band 4')
		})
	})
})
