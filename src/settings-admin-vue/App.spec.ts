import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import App from './App.vue'

// The rest of the router as it is: the real Nextcloud components read
// `imagePath` and friends, and a mock that names one export hides the rest.
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...await importOriginal<typeof import('@nextcloud/router')>(),
	generateOcsUrl: (url: string, params?: Record<string, unknown>) =>
		url.replace(/\{(\w+)\}/g, (whole, token) => (params && token in params ? String(params[token]) : whole)),
}))

// Toasts are asserted in the sections' own specs; here they only must not throw.
vi.mock('../toast', () => ({
	toastSaved: () => undefined,
	toastSuccess: () => undefined,
	toastError: () => undefined,
}))

vi.mock('@nextcloud/vue/components/NcActions', () => ({
	default: { name: 'NcActions', template: '<div class="nc-actions"><slot /></div>' },
}))
vi.mock('@nextcloud/vue/components/NcActionButton', () => ({
	default: { name: 'NcActionButton', template: '<button><slot /></button>' },
}))

vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: { name: 'NcDialog', template: '<div><slot /></div>' },
}))
vi.mock('@nextcloud/vue/components/NcSettingsSelectGroup', () => ({
	default: { name: 'NcSettingsSelectGroup', render: () => null },
}))

const confirmMock = vi.fn((_text: string, _title: string, onConfirm: (confirmed: boolean) => void) => onConfirm(true))

;(globalThis as unknown as { OC: unknown }).OC = {
	requestToken: 'token',
	dialogs: { confirm: confirmMock },
}

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200 })
}

function mockFetch(options: { rules?: unknown[], idleBannerAcknowledged?: boolean } = {}): void {
	vi.spyOn(globalThis, 'fetch').mockImplementation((input) => {
		const url = String(input)
		if (url.includes('/settings/status')) {
			return Promise.resolve(jsonResponse({
				version: '1.0',
				dbVersion: '1',
				rowCount: 3,
				pendingStats: {},
				staleStats: { 'stale:eroded': 4, 'stale:reset': 9 },
				jobs: {
					rule_sweep: { lastRun: 1700000000, counts: { matched: 12, marked: 3 } },
					pending_drain: { lastRun: null, counts: {} },
				},
				idleBannerAcknowledged: options.idleBannerAcknowledged ?? false,
			}))
		}
		if (url.includes('/api/v1/rules') && options.rules) {
			return Promise.resolve(jsonResponse({
				success: true,
				rules: options.rules,
				canCreate: true,
				supportedAlgos: ['sha1', 'sha256'],
				availableUsers: ['alice'],
				availableGroups: [],
			}))
		}
		if (url.includes('/api/v1/rules')) {
			// Server order is derived order: the segment's default last.
			return Promise.resolve(jsonResponse({
				success: true,
				rules: [
					{ id: 2, path: '/docs', selector: 'home:*', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: false, band: 7, position: 1, canEdit: true },
					{ id: 1, path: '**', selector: 'home:*', mode: 'auto', algos: ['sha1'], enabled: true, admin_enforced: false, isDefault: true, band: 7, position: 2, canEdit: true },
				],
				canCreate: true,
				supportedAlgos: ['sha1', 'sha256'],
				availableUsers: ['alice'],
				availableGroups: [],
			}))
		}
		if (url.includes('/settings/global')) {
			return Promise.resolve(jsonResponse({ permissions: { rule_editing: { allowAll: false, groups: [], users: [] }, instance_view: { allowAll: false, groups: [], users: [] }, api_access: { allowAll: true, groups: [], users: [] }, manual_recalc: { allowAll: true, groups: [], users: [] } }, availableUsers: [], allowedAlgorithms: ['sha1', 'sha256'], availableAlgorithms: ['sha1', 'md5', 'sha256'], defaultAlgorithm: 'sha1' }))
		}
		return Promise.resolve(jsonResponse({ success: true }))
	})
}

describe('settings-admin App', () => {
	// --- Idle banner (D5) visibility matrix ---

	const disabledInclude = { id: 1, path: '**', selector: 'home:*', mode: 'auto', algos: ['sha1'], enabled: false, admin_enforced: false, isDefault: true, band: 7, position: 1, canEdit: true }
	const enabledExclude = { id: 2, path: '**/*.iso', selector: 'home:*', type: 'exclude', enabled: true, admin_enforced: false, band: 7, position: 1, canEdit: true }

	it('shows the idle banner when no rules exist at all', async () => {
		mockFetch({ rules: [] })
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-idle-banner').exists()).toBe(true)
	})

	it('shows the idle banner when every include rule is disabled', async () => {
		mockFetch({ rules: [disabledInclude] })
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-idle-banner').exists()).toBe(true)
	})

	it('shows the idle banner when only non-include rules are enabled', async () => {
		// An enabled exclude computes nothing: hashing is still idle.
		mockFetch({ rules: [enabledExclude, disabledInclude] })
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-idle-banner').exists()).toBe(true)
	})

	it('hides the idle banner when an enabled include rule exists', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-idle-banner').exists()).toBe(false)
	})

	it('hides the idle banner once acknowledged server-side', async () => {
		mockFetch({ rules: [], idleBannerAcknowledged: true })
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-idle-banner').exists()).toBe(false)
	})

	it('Close hides the banner for this view only, without persisting', async () => {
		mockFetch({ rules: [] })
		const wrapper = mount(App)
		await flushPromises()

		const fetchSpy = globalThis.fetch as unknown as ReturnType<typeof vi.fn>
		const callsBefore = fetchSpy.mock.calls.length

		await wrapper.find('#fcias-idle-banner button[data-action="banner-close"]').trigger('click')

		expect(wrapper.find('#fcias-idle-banner').exists()).toBe(false)
		expect(fetchSpy.mock.calls.length).toBe(callsBefore)
	})

	it('Acknowledged persists via the settings endpoint and hides the banner', async () => {
		mockFetch({ rules: [] })
		const wrapper = mount(App)
		await flushPromises()

		await wrapper.find('#fcias-idle-banner button[data-action="banner-ack"]').trigger('click')
		await flushPromises()

		const fetchSpy = globalThis.fetch as unknown as ReturnType<typeof vi.fn>
		const ackCall = fetchSpy.mock.calls.find((call) => String(call[0]).includes('/settings/idle-banner/ack'))
		expect(ackCall).toBeTruthy()
		expect(wrapper.find('#fcias-idle-banner').exists()).toBe(false)
	})

	afterEach(() => {
		vi.restoreAllMocks()
		window.location.hash = ''
	})

	it('loads status and lists every rule in one banded table', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		// One table now, in evaluation order: the catch-all is a row in it
		// rather than a separate table above.
		const rows = wrapper.find('#fcias-rules-list').findAll('tbody tr[data-id]')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('/docs')
		expect(rows[1].text()).toContain('**')
		// Position numbers run through the whole segment, defaults included.
		expect(rows[1].findAll('td')[1].text()).toBe('7.2')

		// The status table is on its own tab; the load happened at mount.
		await wrapper.find('[aria-controls="fcias-tab-panel-advanced"]').trigger('click')

		expect(wrapper.find('#fcias-status-rowcount').text()).toBe('3')

		// D17: untrusted hashes are queryable state, and each job carries a
		// heartbeat. Broken down by reason, because erosion heals itself and
		// a reset is waiting for something — different things to do about them.
		const untrusted = wrapper.find('#fcias-status-untrusted').text()
		expect(untrusted).toContain('Total: 13')
		expect(untrusted).toContain('Eroded: 4')
		expect(untrusted).toContain('Reset: 9')
		expect(untrusted).toContain('heals itself')
		const jobs = wrapper.find('#fcias-status-jobs').text()
		expect(jobs).toContain('Rule sweep')
		expect(jobs).toContain('matched 12, marked 3')
		expect(jobs).toContain('Queue drain')
		expect(jobs).toContain('never ran yet')
	})

	it('switches to the Documentation tab', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-tab-panel-settings').exists()).toBe(true)
		expect(wrapper.find('#fcias-tab-panel-docs').exists()).toBe(false)

		// By the panel it controls, not by position: Documentation is the
		// last tab and stays so, and tabs are added in front of it.
		await wrapper.find('[aria-controls="fcias-tab-panel-docs"]').trigger('click')

		expect(wrapper.find('#fcias-tab-panel-settings').exists()).toBe(false)
		expect(wrapper.find('#fcias-tab-panel-docs').exists()).toBe(true)
	})

	it('keeps Documentation as the last tab', () => {
		mockFetch()
		const wrapper = mount(App)

		const tabs = wrapper.findAll('.fcias-tab')
		expect(tabs.at(-1)!.attributes('aria-controls')).toBe('fcias-tab-panel-docs')
	})

	it('gathers every permission on the Permissions tab, and none on Settings', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.findAllComponents({ name: 'PermissionSection' })).toHaveLength(0)

		await wrapper.find('[aria-controls="fcias-tab-panel-permissions"]').trigger('click')

		const sections = wrapper.findAllComponents({ name: 'PermissionSection' })
		expect(sections.map((s) => s.props('permission'))).toEqual([
			'manual_recalc', 'rule_editing', 'instance_view', 'api_access',
		])
		// One form for every heading — the reader learns the shape once.
		const headings = wrapper.findAll('#fcias-tab-panel-permissions h4').map((h) => h.text())
		expect(headings.every((h) => h.startsWith('Who may '))).toBe(true)
	})

	it('orders the tabs: settings, permissions, tokens, status, docs', () => {
		mockFetch()
		const wrapper = mount(App)

		expect(wrapper.findAll('.fcias-tab').map((t) => t.attributes('aria-controls'))).toEqual([
			'fcias-tab-panel-settings',
			'fcias-tab-panel-permissions',
			'fcias-tab-panel-tokens',
			'fcias-tab-panel-advanced',
			'fcias-tab-panel-docs',
		])
	})

	// Diagnostics on their own tab. The idle banner stays with the rules it
	// points at: the banner cases above find it on Settings, at mount.
	it('shows the status table on its tab only', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('.fcias-status-table').exists()).toBe(false)
		expect(wrapper.find('#fcias-rules-list').exists()).toBe(true)

		await wrapper.find('[aria-controls="fcias-tab-panel-advanced"]').trigger('click')

		expect(wrapper.find('.fcias-status-table').exists()).toBe(true)
		expect(wrapper.find('#fcias-rules-list').exists()).toBe(false)
	})

	it('keeps Path and User Scope editable for an additional rule', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		await wrapper.find('#fcias-rules-list').findAll('tbody tr[data-id]')[0]
			.find('button[data-action="edit"]').trigger('click')

		expect(wrapper.find('#fcias-rule-path').element.tagName).toBe('INPUT')
		expect(wrapper.find('#fcias-rule-selector').element.tagName).toBe('SELECT')
		expect((wrapper.find('#fcias-rule-path').element as HTMLInputElement).value).toBe('/docs')
	})

	it('opens and cancels the add-rule form', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.find('#fcias-rule-form').exists()).toBe(false)

		await wrapper.find('#fcias-btn-add-rule').trigger('click')
		expect(wrapper.find('#fcias-rule-form').exists()).toBe(true)

		await wrapper.find('#fcias-btn-cancel-rule').trigger('click')
		expect(wrapper.find('#fcias-rule-form').exists()).toBe(false)
	})

	it('treats a default like any other rule of its segment', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		const rows = wrapper.find('#fcias-rules-list').findAll('tbody tr[data-id]')
		const dflt = rows[1]

		// pinned is gone: a deleted shipped default is recreated (disabled)
		// by the repair step, so the full action set applies. What protects
		// evaluation order is the partition, not button removal.
		expect(dflt.find('button[data-action="delete"]').exists()).toBe(true)
		expect(dflt.find('.fcias-drag-handle').exists()).toBe(true)
		expect(dflt.find('button[data-action="edit"]').exists()).toBe(true)
	})

	it('edits a default through the same dialog as every other rule', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		const defaultRow = wrapper.find('#fcias-rules-list').findAll('tbody tr[data-id]')[1]
		await defaultRow.find('button[data-action="edit"]').trigger('click')

		// No locked fields any more: a default is an ordinary rule whose
		// shape puts it in the trailing partition. The dialog seeds its
		// selector and path like anyone else's.
		const path = wrapper.find('#fcias-rule-path')
		expect(path.element.tagName).toBe('INPUT')
		expect((path.element as HTMLInputElement).value).toBe('**')

		await wrapper.find('#fcias-rule-mode').setValue('force')
		await wrapper.find('#fcias-btn-save-rule').trigger('click')
		await flushPromises()

		// The listing GET also hits /api/v1/rules, so match the mutation.
		const saveCall = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
			([, init]) => (init as RequestInit | undefined)?.method === 'PUT',
		)
		expect(saveCall).toBeDefined()
		expect(String(saveCall![0])).toBe('/apps/file_checksum_search/api/v1/rules/1')
		const body = JSON.parse((saveCall![1] as RequestInit).body as string)
		expect(body).toMatchObject({ path: '**', selector: 'home:*', mode: 'force' })
	})

	it('shows the stored algorithms of the global rule once the async load resolves', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		// Regression guard: the algorithm picker used to snapshot its selection at
		// setup time, when supportedAlgos was still empty, and stayed blank.
		expect(wrapper.find('#fcias-rules-list').text()).toContain('sha1')
	})

	it('deletes an additional rule after confirmation', async () => {
		mockFetch()
		const wrapper = mount(App)
		await flushPromises()

		await wrapper.find('#fcias-rules-list').findAll('tbody tr[data-id]')[0]
			.find('button[data-action="delete"]').trigger('click')
		await flushPromises()

		expect(confirmMock).toHaveBeenCalled()
		const deleteCall = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
			([, init]) => (init as RequestInit | undefined)?.method === 'DELETE',
		)
		expect(deleteCall).toBeDefined()
		expect(String(deleteCall![0])).toBe('/apps/file_checksum_search/api/v1/rules/2')
	})
})
