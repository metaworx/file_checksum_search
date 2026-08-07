/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Admin settings page — status display, cron rule definitions, snippet generator.
 */

import { generateOcsUrl } from '@nextcloud/router'
import { OCS_SETTINGS } from './routes'
import { escapeHtml } from './utils'

declare const OC: {
	requestToken: string
	Notification: {
		showTemporary: (msg: string) => void
	}
	dialogs: {
		confirm: (text: string, title: string, callback: (confirmed: boolean) => void, modal?: boolean) => void
	}
}

interface StatusData {
	version?: string
	dbVersion?: string
	rowCount?: number
	pendingStats?: Record<string, number>
}

interface DefinitionData {
	id?: string | number
	enabled?: boolean
	mode?: string
	algos?: string[]
	algo?: string
	userScope?: string
	path?: string
}

interface DefinitionsResponse {
	supportedAlgos?: string[]
	users?: string[]
	definitions?: DefinitionData[]
}

interface ApiResponse {
	success?: boolean
	error?: string
	snippet?: string
}

let editingDefinitionId: string | number | null = null
let editingIsGlobal = false
let supportedAlgos: string[] = []
let availableUsers: string[] = []

function setText(id: string, text: string): void {
	const el = document.getElementById(id)
	if (el) {
		el.textContent = text
	}
}

function setHtml(id: string, html: string): void {
	const el = document.getElementById(id)
	if (el) {
		el.innerHTML = html
	}
}

function loadStatus(): void {
	const statusUrl = generateOcsUrl(OCS_SETTINGS.getStatus)
	fetch(statusUrl)
		.then(r => r.json() as Promise<StatusData>)
		.then(data => {
			setText('fcias-status-version', data.version || '—')
			setText('fcias-status-dbversion', data.dbVersion || '—')
			setText('fcias-status-rowcount', String(data.rowCount || 0))

			const stats = data.pendingStats || {}
			const lines: string[] = []
			Object.keys(stats).forEach(key => {
				lines.push(`${escapeHtml(key)}: ${stats[key]}`)
			})
			if (lines.length === 0) {
				lines.push('None')
			}
			setHtml('fcias-status-pending', lines.join('<br>'))
		})
		.catch(() => {
			setHtml('fcias-msg', '<p class="fcias-error">Failed to load status.</p>')
		})
}

function buildAlgoCheckboxes(containerId: string, selectedAlgos?: string[]): void {
	const container = document.getElementById(containerId)
	if (!container) return
	selectedAlgos = selectedAlgos || []
	let html = ''
	supportedAlgos.forEach(algo => {
		const checked = selectedAlgos!.indexOf(algo) !== -1 ? ' checked' : ''
		html += `<label class="fcias-checkbox-label"><input type="checkbox" name="fcias-algo" value="${escapeHtml(algo)}"${checked}> ${escapeHtml(algo)}</label> `
	})
	container.innerHTML = html
}

function getCheckedAlgos(containerId: string): string[] {
	const container = document.getElementById(containerId)
	if (!container) return []
	const boxes = container.querySelectorAll<HTMLInputElement>('input[type="checkbox"]:checked')
	const algos: string[] = []
	for (let i = 0; i < boxes.length; i++) {
		algos.push(boxes[i].value)
	}
	return algos
}

function populateDropdowns(): void {
	const userSelects = document.querySelectorAll<HTMLSelectElement>('#fcias-cron-userscope, #fcias-snippet-userscope')
	userSelects.forEach(sel => {
		const currentValue = sel.value
		sel.innerHTML = '<option value="all">All Users</option>'
		availableUsers.forEach(uid => {
			const opt = document.createElement('option')
			opt.value = uid
			opt.textContent = uid
			sel.appendChild(opt)
		})
		sel.value = currentValue
	})

	const algoSnippetSelects = document.querySelectorAll<HTMLSelectElement>('#fcias-snippet-algo')
	algoSnippetSelects.forEach(sel => {
		sel.innerHTML = ''
		supportedAlgos.forEach(algo => {
			const opt = document.createElement('option')
			opt.value = algo
			opt.textContent = algo
			sel.appendChild(opt)
		})
	})
}

function loadDefinitions(): void {
	const cronDefinitionsUrl = generateOcsUrl(OCS_SETTINGS.listRules)
	fetch(cronDefinitionsUrl)
		.then(r => r.json() as Promise<DefinitionsResponse>)
		.then(data => {
			supportedAlgos = data.supportedAlgos || []
			availableUsers = data.users || []
			populateDropdowns()
			const definitions = data.definitions || []
			renderGlobalRule(definitions.length > 0 ? definitions[0] : null)
			renderAdditionalRules(definitions.length > 1 ? definitions.slice(1) : [])
		})
		.catch(() => {
			setHtml('fcias-cron-msg', '<p class="fcias-error">Failed to load definitions.</p>')
		})
}

function renderGlobalRule(def: DefinitionData | null): void {
	const container = document.getElementById('fcias-global-rule')
	if (!container) return

	if (!def) {
		def = { enabled: true, mode: 'auto', algos: ['sha1'], path: '**', userScope: 'all' }
	}

	const html = '<div class="fcias-global-rule-form">' +
		'<h5>Global Rule (priority 0)</h5>' +
		'<div class="fcias-cron-form-row">' +
		'<label>User Scope</label>' +
		'<span>' + escapeHtml(def.userScope || 'all') + '</span>' +
		'</div>' +
		'<div class="fcias-cron-form-row">' +
		'<label>Path</label>' +
		'<span>' + escapeHtml(def.path || '**') + '</span>' +
		'</div>' +
		'<div class="fcias-cron-form-row">' +
		'<label>Algorithms</label>' +
		'<div id="fcias-global-algos" class="fcias-checkbox-group"></div>' +
		'</div>' +
		'<div class="fcias-cron-form-row">' +
		'<label for="fcias-global-mode">Mode</label>' +
		'<select id="fcias-global-mode">' +
		'<option value="auto"' + (def.mode === 'auto' ? ' selected' : '') + '>Auto (recalc existing only if stale)</option>' +
		'<option value="missing"' + (def.mode === 'missing' ? ' selected' : '') + '>Missing (recalc existing + missing)</option>' +
		'<option value="force"' + (def.mode === 'force' ? ' selected' : '') + '>Force (delete all, recalc all)</option>' +
		'<option value="lazy"' + (def.mode === 'lazy' ? ' selected' : '') + '>Lazy (delete hashes, recalc later)</option>' +
		'</select>' +
		'</div>' +
		'<div class="fcias-cron-form-row">' +
		'<label>Status</label>' +
		'<span class="' + (def.enabled ? 'fcias-compat-pass' : 'fcias-compat-fail') + '">' + (def.enabled ? 'Enabled' : 'Disabled') + '</span>' +
		'</div>' +
		'<div class="fcias-cron-form-actions">' +
		'<button class="fcias-btn" id="fcias-btn-save-global">Save Global Rule</button> ' +
		'<button class="fcias-btn" id="fcias-btn-toggle-global">' + (def.enabled ? 'Disable' : 'Enable') + '</button>' +
		'</div>' +
		'</div>'
	container.innerHTML = html

	buildAlgoCheckboxes('fcias-global-algos', def.algos || (def.algo ? [def.algo] : ['sha1']))

	const globalDefId = def.id || null
	const globalEnabled = def.enabled !== false

	document.getElementById('fcias-btn-save-global')?.addEventListener('click', () => {
		saveGlobalRule(globalDefId)
	})
	document.getElementById('fcias-btn-toggle-global')?.addEventListener('click', () => {
		if (globalDefId) {
			toggleDefinition(globalDefId, !globalEnabled)
		} else {
			saveGlobalRule(null)
		}
	})
}

function saveGlobalRule(id: string | number | null): void {
	const cronSaveUrl = generateOcsUrl(OCS_SETTINGS.saveRule)
	const def: Record<string, unknown> = {
		id: id || undefined,
		enabled: true,
		mode: (document.getElementById('fcias-global-mode') as HTMLSelectElement).value,
		algos: getCheckedAlgos('fcias-global-algos'),
		userScope: 'all',
		path: '**',
	}
	fetch(cronSaveUrl, {
		method: 'POST',
		headers: {
			requesttoken: OC.requestToken,
			'Content-Type': 'application/json',
		},
		body: JSON.stringify(def),
	})
		.then(r => r.json() as Promise<ApiResponse>)
		.then(data => {
			if (data.success) {
				loadDefinitions()
				OC.Notification.showTemporary('Global rule saved.')
			} else {
				setHtml('fcias-cron-msg', `<p class="fcias-error">${escapeHtml(data.error || 'Save failed.')}</p>`)
			}
		})
		.catch(() => {
			setHtml('fcias-cron-msg', '<p class="fcias-error">Request failed.</p>')
		})
}

function renderAdditionalRules(definitions: DefinitionData[]): void {
	const container = document.getElementById('fcias-cron-list')
	if (!container) return

	if (definitions.length === 0) {
		container.innerHTML = '<p>No additional rules.</p>'
		return
	}

	let html = '<table class="grid fcias-cron-table"><thead><tr>' +
		'<th>Priority</th><th>User</th><th>Path</th><th>Algos</th><th>Mode</th><th>Status</th><th></th>' +
		'</tr></thead><tbody>'
	definitions.forEach((def, index) => {
		const statusClass = def.enabled ? 'fcias-compat-pass' : 'fcias-compat-fail'
		const statusText = def.enabled ? 'Enabled' : 'Disabled'
		const algosText = (def.algos || [def.algo]).join(', ')
		html += `<tr data-id="${escapeHtml(String(def.id))}">` +
			`<td>${index + 1}</td>` +
			`<td>${escapeHtml(def.userScope || 'all')}</td>` +
			`<td>${escapeHtml(def.path || '/')}</td>` +
			`<td>${escapeHtml(algosText)}</td>` +
			`<td>${escapeHtml(def.mode || 'auto')}</td>` +
			`<td><span class="${statusClass}">${statusText}</span></td>` +
			'<td class="fcias-cron-actions">' +
			'<button class="fcias-btn fcias-btn-edit" data-action="edit">Edit</button> ' +
			`<button class="fcias-btn fcias-btn-toggle" data-action="toggle">${def.enabled ? 'Disable' : 'Enable'}</button> ` +
			'<button class="fcias-btn fcias-btn-danger fcias-btn-delete" data-action="delete">Delete</button>' +
			'</td>' +
			'</tr>'
	})
	html += '</tbody></table>'
	container.innerHTML = html
}

function showDefinitionForm(def: DefinitionData | null): void {
	editingDefinitionId = def ? def.id ?? null : null
	editingIsGlobal = false
	const userscope = document.getElementById('fcias-cron-userscope') as HTMLSelectElement
	const path = document.getElementById('fcias-cron-path') as HTMLInputElement
	const mode = document.getElementById('fcias-cron-mode') as HTMLSelectElement
	if (userscope) userscope.value = def ? (def.userScope || 'all') : 'all'
	if (path) path.value = def ? (def.path || '/') : '/'
	if (mode) mode.value = def ? (def.mode || 'auto') : 'auto'
	buildAlgoCheckboxes('fcias-cron-algos', def ? (def.algos || (def.algo ? [def.algo] : ['sha1'])) : ['sha1'])
	const form = document.getElementById('fcias-cron-form')
	if (form) form.style.display = 'block'
}

function hideDefinitionForm(): void {
	editingDefinitionId = null
	editingIsGlobal = false
	const form = document.getElementById('fcias-cron-form')
	if (form) form.style.display = 'none'
}

function saveDefinition(): void {
	const cronSaveUrl = generateOcsUrl(OCS_SETTINGS.saveRule)
	const def: Record<string, unknown> = {
		id: editingDefinitionId || undefined,
		enabled: true,
		mode: (document.getElementById('fcias-cron-mode') as HTMLSelectElement).value,
		algos: getCheckedAlgos('fcias-cron-algos'),
		userScope: (document.getElementById('fcias-cron-userscope') as HTMLSelectElement).value,
		path: (document.getElementById('fcias-cron-path') as HTMLInputElement).value,
	}
	fetch(cronSaveUrl, {
		method: 'POST',
		headers: {
			requesttoken: OC.requestToken,
			'Content-Type': 'application/json',
		},
		body: JSON.stringify(def),
	})
		.then(r => r.json() as Promise<ApiResponse>)
		.then(data => {
			if (data.success) {
				hideDefinitionForm()
				loadDefinitions()
				OC.Notification.showTemporary('Rule saved.')
			} else {
				setHtml('fcias-cron-msg', `<p class="fcias-error">${escapeHtml(data.error || 'Save failed.')}</p>`)
			}
		})
		.catch(() => {
			setHtml('fcias-cron-msg', '<p class="fcias-error">Request failed.</p>')
		})
}

function deleteDefinition(id: string): void {
	const cronDeleteUrl = generateOcsUrl(OCS_SETTINGS.deleteRule)
	OC.dialogs.confirm(
		'Delete this rule definition?',
		'Confirm Delete',
		(confirmed: boolean) => {
			if (!confirmed) return
			fetch(cronDeleteUrl, {
				method: 'POST',
				headers: {
					requesttoken: OC.requestToken,
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({ id }),
			})
				.then(r => r.json() as Promise<ApiResponse>)
				.then(data => {
					if (data.success) {
						loadDefinitions()
						OC.Notification.showTemporary('Rule deleted.')
					} else {
						setHtml('fcias-cron-msg', `<p class="fcias-error">${escapeHtml(data.error || 'Delete failed.')}</p>`)
					}
				})
				.catch(() => {
					setHtml('fcias-cron-msg', '<p class="fcias-error">Request failed.</p>')
				})
		},
		true,
	)
}

function toggleDefinition(id: string | number, enabled: boolean): void {
	const cronToggleUrl = generateOcsUrl(OCS_SETTINGS.toggleRule)
	fetch(cronToggleUrl, {
		method: 'POST',
		headers: {
			requesttoken: OC.requestToken,
			'Content-Type': 'application/json',
		},
		body: JSON.stringify({ id, enabled }),
	})
		.then(r => r.json() as Promise<ApiResponse>)
		.then(data => {
			if (data.success) {
				loadDefinitions()
				OC.Notification.showTemporary(enabled ? 'Rule enabled.' : 'Rule disabled.')
			} else {
				setHtml('fcias-cron-msg', `<p class="fcias-error">${escapeHtml(data.error || 'Toggle failed.')}</p>`)
			}
		})
		.catch(() => {
			setHtml('fcias-cron-msg', '<p class="fcias-error">Request failed.</p>')
		})
}

function generateSnippet(): void {
	const cronSnippetUrl = generateOcsUrl(OCS_SETTINGS.getCrontabSnippet)
	const form = document.getElementById('fcias-snippet-form')
	if (form && form.style.display === 'none') {
		form.style.display = 'block'
		return
	}
	const params = new URLSearchParams({
		userScope: (document.getElementById('fcias-snippet-userscope') as HTMLSelectElement).value,
		path: (document.getElementById('fcias-snippet-path') as HTMLInputElement).value,
		algo: (document.getElementById('fcias-snippet-algo') as HTMLSelectElement).value,
		batchSize: (document.getElementById('fcias-snippet-batchsize') as HTMLInputElement).value,
		interval: (document.getElementById('fcias-snippet-interval') as HTMLInputElement).value,
	})
	fetch(`${cronSnippetUrl}?${params.toString()}`)
		.then(r => r.json() as Promise<ApiResponse>)
		.then(data => {
			const snippetEl = document.getElementById('fcias-cron-snippet')
			if (snippetEl) snippetEl.textContent = data.snippet || ''
			const container = document.getElementById('fcias-cron-snippet-container')
			if (container) container.style.display = 'block'
		})
		.catch(() => {
			setHtml('fcias-cron-msg', '<p class="fcias-error">Failed to generate snippet.</p>')
		})
}

function copySnippet(): void {
	const pre = document.getElementById('fcias-cron-snippet')
	const text = pre ? pre.textContent : ''
	if (text) {
		navigator.clipboard.writeText(text).then(() => {
			OC.Notification.showTemporary('Copied to clipboard.')
		}).catch(() => {
			OC.Notification.showTemporary('Copy failed. Please copy manually.')
		})
	}
}

document.addEventListener('DOMContentLoaded', () => {
	loadStatus()
	loadDefinitions()

	document.getElementById('fcias-btn-add-definition')?.addEventListener('click', () => { showDefinitionForm(null) })
	document.getElementById('fcias-btn-save-definition')?.addEventListener('click', saveDefinition)
	document.getElementById('fcias-btn-cancel-definition')?.addEventListener('click', hideDefinitionForm)

	const cronList = document.getElementById('fcias-cron-list')
	if (cronList) {
		cronList.addEventListener('click', event => {
			const btn = (event.target as HTMLElement).closest('button')
			if (!btn) return
			const action = btn.getAttribute('data-action')
			const row = btn.closest('tr')
			const id = row ? row.getAttribute('data-id') : null
			if (!id) return
			if (action === 'edit') {
				const algosText = row!.cells[3].textContent || ''
				const def: DefinitionData = {
					id,
					userScope: row!.cells[1].textContent || undefined,
					path: row!.cells[2].textContent || undefined,
					algos: algosText.split(',').map(s => s.trim()),
					mode: row!.cells[4].textContent?.trim() || undefined,
				}
				showDefinitionForm(def)
			} else if (action === 'toggle') {
				const isEnabled = row!.querySelector('.fcias-compat-pass') !== null
				toggleDefinition(id, !isEnabled)
			} else if (action === 'delete') {
				deleteDefinition(id)
			}
		})
	}

	document.getElementById('fcias-btn-generate-snippet')?.addEventListener('click', generateSnippet)
	document.getElementById('fcias-btn-copy-snippet')?.addEventListener('click', copySnippet)
})
