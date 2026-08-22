import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * settings-admin.ts has no exported functions — it wires everything up
 * imperatively inside a DOMContentLoaded listener. These tests drive it
 * black-box: build the DOM it expects, import the module fresh, fire
 * DOMContentLoaded, and assert on the resulting DOM / fetch calls.
 */

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => url,
}))

vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: { name: 'NcSelect', render: () => null },
}))

function flushPromises(): Promise<void> {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

function buildDom(): void {
	document.body.innerHTML = `
		<p id="fcias-msg"></p>
		<span id="fcias-status-version"></span>
		<span id="fcias-status-dbversion"></span>
		<span id="fcias-status-rowcount"></span>
		<span id="fcias-status-pending"></span>
		<span id="fcias-status-lastupdated"></span>
		<button id="fcias-btn-refresh-status"></button>

		<div id="fcias-global-rule"></div>

		<p id="fcias-cron-msg"></p>
		<div id="fcias-cron-list"></div>
		<button id="fcias-btn-add-definition"></button>
		<div id="fcias-cron-form">
			<select id="fcias-cron-userscope"><option value="all">All Users</option></select>
			<input id="fcias-cron-path" />
			<select id="fcias-cron-mode">
				<option value="auto">auto</option>
				<option value="missing">missing</option>
				<option value="force">force</option>
				<option value="lazy">lazy</option>
			</select>
			<input type="checkbox" id="fcias-cron-admin-enforced" />
			<div id="fcias-cron-algos"></div>
			<button id="fcias-btn-save-definition"></button>
			<button id="fcias-btn-cancel-definition"></button>
		</div>

		<button id="fcias-btn-generate-snippet"></button>
		<button id="fcias-btn-copy-snippet"></button>
		<div id="fcias-snippet-form">
			<select id="fcias-snippet-userscope"><option value="all">All Users</option></select>
			<input id="fcias-snippet-path" value="/" />
			<select id="fcias-snippet-algo"><option value="sha1">sha1</option></select>
			<input id="fcias-snippet-batchsize" value="100" />
			<input id="fcias-snippet-interval" value="900" />
		</div>
		<div id="fcias-cron-snippet-container">
			<pre id="fcias-cron-snippet"></pre>
		</div>
	`

	// Set display via the JS property rather than the HTML attribute —
	// more reliably observable through element.style.display than
	// relying on happy-dom's inline-style-attribute parsing.
	;(document.getElementById('fcias-cron-form') as HTMLElement).style.display = 'none'
	;(document.getElementById('fcias-snippet-form') as HTMLElement).style.display = 'none'
	;(document.getElementById('fcias-cron-snippet-container') as HTMLElement).style.display = 'none'
}

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200 })
}

describe('settings-admin', () => {
	let fetchMock: ReturnType<typeof vi.spyOn>

	beforeEach(() => {
		vi.resetModules()
		buildDom()

		;(globalThis as unknown as { OC: unknown }).OC = {
			requestToken: 'token',
			Notification: { showTemporary: vi.fn() },
			dialogs: {
				confirm: (
					_text: string,
					_title: string,
					callback: (confirmed: boolean) => void,
				) => callback(true),
			},
		}
	})

	afterEach(() => {
		vi.restoreAllMocks()
		document.body.innerHTML = ''
	})

	async function loadModule(
		statusResponse: unknown,
		definitionsResponse: unknown,
	): Promise<void> {
		fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((url) => {
			const href = String(url)
			if (href.includes('/settings/status')) {
				return Promise.resolve(jsonResponse(statusResponse))
			}
			if (href.includes('/settings/cron/definitions')) {
				return Promise.resolve(jsonResponse(definitionsResponse))
			}
			return Promise.resolve(jsonResponse({}))
		})

		await import('./settings-admin')
		document.dispatchEvent(new Event('DOMContentLoaded'))
		await flushPromises()
	}

	it('renders status counts and pending stats', async () => {
		await loadModule(
			{ version: '1.9.2', dbVersion: '10.11-MariaDB', rowCount: 5000, pendingStats: { 'pending:auto': 3 } },
			{ supportedAlgos: ['sha1'], users: [], definitions: [] },
		)

		expect(document.getElementById('fcias-status-version')!.textContent).toBe('1.9.2')
		expect(document.getElementById('fcias-status-rowcount')!.textContent).toBe('5000')
		expect(document.getElementById('fcias-status-pending')!.innerHTML).toContain('pending:auto: 3')
	})

	it('renders the global rule from the first definition and escapes untrusted values', async () => {
		await loadModule(
			{ version: '1.9.2', rowCount: 0, pendingStats: {} },
			{
				supportedAlgos: ['sha1'],
				users: [],
				definitions: [
					{ id: 'g1', enabled: true, mode: 'auto', algos: ['sha1'], path: '<script>evil()</script>', userScope: 'all' },
				],
			},
		)

		const html = document.getElementById('fcias-global-rule')!.innerHTML
		expect(html).not.toContain('<script>evil()</script>')
		expect(html).toContain('&lt;script&gt;evil()&lt;/script&gt;')
		expect(html).toContain('Global Rule (priority 0)')
	})

	it('defaults the global rule form when no definitions exist', async () => {
		await loadModule(
			{ version: '1.9.2', rowCount: 0, pendingStats: {} },
			{ supportedAlgos: ['sha1'], users: [], definitions: [] },
		)

		const html = document.getElementById('fcias-global-rule')!.innerHTML
		expect(html).toContain('Global Rule (priority 0)')
		expect(document.getElementById('fcias-cron-list')!.innerHTML).toContain('No additional rules.')
	})

	it('renders additional rules (definitions after the first) and escapes untrusted values', async () => {
		await loadModule(
			{ version: '1.9.2', rowCount: 0, pendingStats: {} },
			{
				supportedAlgos: ['sha1'],
				users: [],
				definitions: [
					{ id: 'g1', enabled: true, mode: 'auto', algos: ['sha1'], path: '**', userScope: 'all' },
					{ id: 'r1', enabled: false, mode: 'force', algos: ['md5'], path: '<b>x</b>', userScope: 'alice', admin_enforced: true },
				],
			},
		)

		const html = document.getElementById('fcias-cron-list')!.innerHTML
		expect(html).not.toContain('<b>x</b>')
		expect(html).toContain('&lt;b&gt;x&lt;/b&gt;')
		expect(html).toContain('Disabled')
		expect(html).toContain('Yes') // admin_enforced column
	})

	it('saves the definition form and reloads definitions on success', async () => {
		await loadModule(
			{ version: '1.9.2', rowCount: 0, pendingStats: {} },
			{ supportedAlgos: ['sha1'], users: [], definitions: [] },
		);

		(document.getElementById('fcias-cron-path') as HTMLInputElement).value = '/Documents';
		(document.getElementById('fcias-cron-mode') as HTMLSelectElement).value = 'force'

		document.getElementById('fcias-btn-save-definition')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await flushPromises()

		const saveCall = fetchMock.mock.calls.find(([url]) => String(url).includes('/settings/cron/save'))
		expect(saveCall).toBeDefined()
		const body = JSON.parse((saveCall![1] as RequestInit).body as string)
		expect(body.path).toBe('/Documents')
		expect(body.mode).toBe('force')
	})

	it('toggles an additional rule via the delegated row click handler', async () => {
		await loadModule(
			{ version: '1.9.2', rowCount: 0, pendingStats: {} },
			{
				supportedAlgos: ['sha1'],
				users: [],
				definitions: [
					{ id: 'g1', enabled: true, mode: 'auto', algos: ['sha1'], path: '**', userScope: 'all' },
					{ id: 'r1', enabled: true, mode: 'auto', algos: ['sha1'], path: '/x', userScope: 'alice' },
				],
			},
		)

		const toggleBtn = document.querySelector<HTMLButtonElement>('#fcias-cron-list [data-action="toggle"]')!
		toggleBtn.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await flushPromises()

		const toggleCall = fetchMock.mock.calls.find(([url]) => String(url).includes('/settings/cron/toggle'))
		expect(toggleCall).toBeDefined()
		const body = JSON.parse((toggleCall![1] as RequestInit).body as string)
		expect(body).toEqual({ id: 'r1', enabled: false })
	})

	it('deletes an additional rule via the delegated row click handler after confirmation', async () => {
		await loadModule(
			{ version: '1.9.2', rowCount: 0, pendingStats: {} },
			{
				supportedAlgos: ['sha1'],
				users: [],
				definitions: [
					{ id: 'g1', enabled: true, mode: 'auto', algos: ['sha1'], path: '**', userScope: 'all' },
					{ id: 'r1', enabled: true, mode: 'auto', algos: ['sha1'], path: '/x', userScope: 'alice' },
				],
			},
		)

		const deleteBtn = document.querySelector<HTMLButtonElement>('#fcias-cron-list [data-action="delete"]')!
		deleteBtn.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await flushPromises()

		const deleteCall = fetchMock.mock.calls.find(([url]) => String(url).includes('/settings/cron/delete'))
		expect(deleteCall).toBeDefined()
		const body = JSON.parse((deleteCall![1] as RequestInit).body as string)
		expect(body).toEqual({ id: 'r1' })
	})

})
