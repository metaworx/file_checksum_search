import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import RuleForm from './RuleForm.vue'

vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: { name: 'NcSelect', render: () => null },
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
				rule: { id: 5, path: '/existing', mode: 'force', algos: ['md5'], userScope: 'alice', admin_enforced: true },
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

	it('emits cancel', async () => {
		const wrapper = mount(RuleForm, {
			props: { rule: null, variant: 'personal', supportedAlgos: ['sha1'] },
		})
		await wrapper.find('#fcias-personal-cancel').trigger('click')
		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})
})
