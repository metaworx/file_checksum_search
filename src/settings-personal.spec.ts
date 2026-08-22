import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * settings-personal.ts has no exported functions — it wires everything
 * up imperatively inside a DOMContentLoaded listener. These tests drive
 * it black-box: build the DOM it expects, import the module fresh, fire
 * DOMContentLoaded, and assert on the resulting DOM / fetch calls.
 */

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => url,
}))

vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: { name: 'NcSelect', render: () => null },
}))

vi.mock('@nextcloud/vue/components/NcRichText', () => ({
	default: { name: 'NcRichText', render: () => null },
}))

function flushPromises(): Promise<void> {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

function buildDom(): void {
	document.body.innerHTML = `
		<p id="fcias-personal-msg"></p>
		<button id="fcias-personal-add" style="display:none"></button>
		<div id="fcias-personal-rules"></div>
		<div id="fcias-personal-form" style="display:none">
			<input id="fcias-personal-path" />
			<select id="fcias-personal-mode"><option value="auto">auto</option></select>
			<div id="fcias-personal-algos"></div>
			<button id="fcias-personal-save"></button>
			<button id="fcias-personal-cancel"></button>
		</div>
		<div id="fcias-personal-faq-viewer"></div>
	`
}

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200 })
}

describe('settings-personal', () => {
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

	async function loadModule(rulesResponse: unknown): Promise<void> {
		fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((url) => {
			const href = String(url)
			if (href.includes('/personal/rules') && !href.includes('save') && !href.includes('delete') && !href.includes('toggle')) {
				return Promise.resolve(jsonResponse(rulesResponse))
			}
			return Promise.resolve(jsonResponse({}))
		})

		await import('./settings-personal')
		document.dispatchEvent(new Event('DOMContentLoaded'))
		await flushPromises()
	}

	it('renders rules and escapes untrusted values', async () => {
		await loadModule({
			success: true,
			canEdit: true,
			supportedAlgos: ['sha1'],
			rules: [
				{
					id: '1',
					enabled: true,
					mode: 'auto',
					algos: ['sha1'],
					path: '<img src=x onerror=alert(1)>',
					userScope: 'alice',
					canEdit: true,
				},
			],
		})

		const html = document.getElementById('fcias-personal-rules')!.innerHTML
		expect(html).not.toContain('<img src=x onerror=alert(1)>')
		expect(html).toContain('&lt;img src=x onerror=alert(1)&gt;')
		expect(html).toContain('alice')
		expect(html).toContain('Enabled')
	})

	it('hides the add button and shows a message when the user cannot edit', async () => {
		await loadModule({ success: true, canEdit: false, supportedAlgos: [], rules: [] })

		const addButton = document.getElementById('fcias-personal-add') as HTMLButtonElement
		expect(addButton.style.display).toBe('none')
		expect(document.getElementById('fcias-personal-msg')!.innerHTML).toContain('not allowed to edit rules')
	})

	it('shows "No rules." when the list is empty and editable', async () => {
		await loadModule({ success: true, canEdit: true, supportedAlgos: [], rules: [] })

		expect(document.getElementById('fcias-personal-rules')!.innerHTML).toContain('No rules.')
	})

	it('read-only rules show no action buttons', async () => {
		await loadModule({
			success: true,
			canEdit: true,
			supportedAlgos: ['sha1'],
			rules: [
				{ id: '1', enabled: true, mode: 'auto', algos: ['sha1'], path: '/', userScope: 'all', canEdit: false, admin_enforced: true },
			],
		})

		const html = document.getElementById('fcias-personal-rules')!.innerHTML
		expect(html).toContain('Read-only')
		expect(html).not.toContain('data-action="delete"')
	})

	it('saves the form and reloads rules on success', async () => {
		await loadModule({ success: true, canEdit: true, supportedAlgos: ['sha1'], rules: [] });

		(document.getElementById('fcias-personal-path') as HTMLInputElement).value = '/Documents';
		(document.getElementById('fcias-personal-mode') as HTMLSelectElement).value = 'auto'

		document.getElementById('fcias-personal-save')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await flushPromises()

		const saveCall = fetchMock.mock.calls.find(([url]) => String(url).includes('/personal/rules/save'))
		expect(saveCall).toBeDefined()
		const body = JSON.parse((saveCall![1] as RequestInit).body as string)
		expect(body.path).toBe('/Documents')
		expect(body.mode).toBe('auto')
		// Reload after save = a second GET to /personal/rules.
		const getCalls = fetchMock.mock.calls.filter(([url]) => String(url) === '/apps/file_checksum_search/personal/rules')
		expect(getCalls.length).toBeGreaterThanOrEqual(2)
	})

	it('toggles a rule via the delegated row click handler', async () => {
		await loadModule({
			success: true,
			canEdit: true,
			supportedAlgos: ['sha1'],
			rules: [
				{ id: '1', enabled: true, mode: 'auto', algos: ['sha1'], path: '/', userScope: 'alice', canEdit: true },
			],
		})

		const toggleBtn = document.querySelector<HTMLButtonElement>('[data-action="toggle"]')!
		toggleBtn.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await flushPromises()

		const toggleCall = fetchMock.mock.calls.find(([url]) => String(url).includes('/personal/rules/toggle'))
		expect(toggleCall).toBeDefined()
		const body = JSON.parse((toggleCall![1] as RequestInit).body as string)
		expect(body).toEqual({ id: '1', enabled: false })
	})

	it('deletes a rule via the delegated row click handler after confirmation', async () => {
		await loadModule({
			success: true,
			canEdit: true,
			supportedAlgos: ['sha1'],
			rules: [
				{ id: '1', enabled: true, mode: 'auto', algos: ['sha1'], path: '/', userScope: 'alice', canEdit: true },
			],
		})

		const deleteBtn = document.querySelector<HTMLButtonElement>('[data-action="delete"]')!
		deleteBtn.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await flushPromises()

		const deleteCall = fetchMock.mock.calls.find(([url]) => String(url).includes('/personal/rules/delete'))
		expect(deleteCall).toBeDefined()
		const body = JSON.parse((deleteCall![1] as RequestInit).body as string)
		expect(body).toEqual({ id: '1' })
	})
})
