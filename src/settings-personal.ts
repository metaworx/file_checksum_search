/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Personal settings page — rules applying to the current user's files.
 */

import { createApp } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import { OCS_ADMIN, OCS_PERSONAL } from './routes'
import { escapeHtml } from './utils'
import { toAlgoOptions } from './algorithms'
import { getAlgoSelection, mountAlgoSelect } from './settings-vue/useAlgoSelect'
import { activateTab, tabFromHash } from './tabs'
import DocsViewer from './docs-vue/DocsViewer.vue'
import './settings-admin.css'

declare const OC: {
	requestToken: string
	Notification: {
		showTemporary: (msg: string) => void
	}
	dialogs: {
		confirm: (text: string, title: string, callback: (confirmed: boolean) => void, modal?: boolean) => void
	}
}

interface PersonalRule {
	id?: string | number
	enabled?: boolean
	mode?: string
	algos?: string[]
	algo?: string
	path?: string
	userScope?: string
	admin_enforced?: boolean
	canEdit?: boolean
}

interface PersonalResponse {
	success?: boolean
	rules?: PersonalRule[]
	canEdit?: boolean
	supportedAlgos?: string[]
	error?: string
}

interface ApiResponse {
	success?: boolean
	error?: string
}

let canEditAll = false
let supportedAlgos: string[] = []
let editingId: string | number | null = null

function setHtml(id: string, html: string): void {
	const el = document.getElementById(id)
	if (el) {
		el.innerHTML = html
	}
}

function loadRules(): void {
	const rulesUrl = generateOcsUrl(OCS_PERSONAL.getRules)
	fetch(rulesUrl)
		.then(r => r.json() as Promise<PersonalResponse>)
		.then(data => {
			canEditAll = data.canEdit === true
			supportedAlgos = data.supportedAlgos || []
			renderRules(data.rules || [])
		})
		.catch(() => {
			setHtml('fcias-personal-msg', '<p class="fcias-error">Failed to load rules.</p>')
		})
}

function renderRules(rules: PersonalRule[]): void {
	const container = document.getElementById('fcias-personal-rules')
	if (!container) return

	const addButton = document.getElementById('fcias-personal-add')
	if (addButton) {
		addButton.style.display = canEditAll ? '' : 'none'
	}

	if (!canEditAll) {
		setHtml('fcias-personal-msg', '<p class="fcias-error">You are not allowed to edit rules. Contact an administrator.</p>')
	}

	if (rules.length === 0) {
		container.innerHTML = '<p>No rules.</p>'
		return
	}

	let html = '<table class="grid fcias-cron-table"><thead><tr>' +
		'<th>Scope</th><th>Path</th><th>Algos</th><th>Mode</th><th>Status</th><th>Admin-enforced</th><th></th>' +
		'</tr></thead><tbody>'
	rules.forEach((def) => {
		const statusClass = def.enabled ? 'fcias-compat-pass' : 'fcias-compat-fail'
		const statusText = def.enabled ? 'Enabled' : 'Disabled'
		const algosText = (def.algos || [def.algo]).join(', ')
		const enforcedChecked = def.admin_enforced ? ' checked disabled' : ' disabled'
		html += `<tr data-id="${escapeHtml(String(def.id))}">` +
			`<td>${escapeHtml(def.userScope || 'all')}</td>` +
			`<td>${escapeHtml(def.path || '/')}</td>` +
			`<td>${escapeHtml(algosText)}</td>` +
			`<td>${escapeHtml(def.mode || 'auto')}</td>` +
			`<td><span class="${statusClass}">${statusText}</span></td>` +
			`<td><input type="checkbox"${enforcedChecked}></td>` +
			'<td class="fcias-cron-actions">'
		if (def.canEdit) {
			html += '<button class="fcias-btn fcias-btn-edit" data-action="edit">Edit</button> ' +
				`<button class="fcias-btn fcias-btn-toggle" data-action="toggle">${def.enabled ? 'Disable' : 'Enable'}</button> ` +
				'<button class="fcias-btn fcias-btn-danger fcias-btn-delete" data-action="delete">Delete</button>'
		} else {
			html += '<span class="fcias-muted">Read-only</span>'
		}
		html += '</td></tr>'
	})
	html += '</tbody></table>'
	container.innerHTML = html
}

function showForm(def: PersonalRule | null): void {
	editingId = def ? def.id ?? null : null
	const path = document.getElementById('fcias-personal-path') as HTMLInputElement
	const mode = document.getElementById('fcias-personal-mode') as HTMLSelectElement
	if (path) path.value = def ? (def.path || '/') : '/'
	if (mode) mode.value = def ? (def.mode || 'auto') : 'auto'
	mountAlgoSelect('fcias-personal-algos', toAlgoOptions(supportedAlgos), def ? (def.algos || (def.algo ? [def.algo] : ['sha1'])) : ['sha1'])
	const form = document.getElementById('fcias-personal-form')
	if (form) form.style.display = 'block'
}

function hideForm(): void {
	editingId = null
	const form = document.getElementById('fcias-personal-form')
	if (form) form.style.display = 'none'
}

function saveRule(): void {
	const saveUrl = generateOcsUrl(OCS_PERSONAL.saveRule)
	const def: Record<string, unknown> = {
		id: editingId || undefined,
		enabled: true,
		mode: (document.getElementById('fcias-personal-mode') as HTMLSelectElement).value,
		algos: getAlgoSelection('fcias-personal-algos'),
		path: (document.getElementById('fcias-personal-path') as HTMLInputElement).value,
	}
	fetch(saveUrl, {
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
				hideForm()
				loadRules()
				OC.Notification.showTemporary('Rule saved.')
			} else {
				setHtml('fcias-personal-msg', `<p class="fcias-error">${escapeHtml(data.error || 'Save failed.')}</p>`)
			}
		})
		.catch(() => {
			setHtml('fcias-personal-msg', '<p class="fcias-error">Request failed.</p>')
		})
}

function deleteRule(id: string): void {
	const deleteUrl = generateOcsUrl(OCS_PERSONAL.deleteRule)
	OC.dialogs.confirm(
		'Delete this rule?',
		'Confirm Delete',
		(confirmed: boolean) => {
			if (!confirmed) return
			fetch(deleteUrl, {
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
						loadRules()
						OC.Notification.showTemporary('Rule deleted.')
					} else {
						setHtml('fcias-personal-msg', `<p class="fcias-error">${escapeHtml(data.error || 'Delete failed.')}</p>`)
					}
				})
				.catch(() => {
					setHtml('fcias-personal-msg', '<p class="fcias-error">Request failed.</p>')
				})
		},
		true,
	)
}

function toggleRule(id: string | number, enabled: boolean): void {
	const toggleUrl = generateOcsUrl(OCS_PERSONAL.toggleRule)
	fetch(toggleUrl, {
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
				loadRules()
				OC.Notification.showTemporary(enabled ? 'Rule enabled.' : 'Rule disabled.')
			} else {
				setHtml('fcias-personal-msg', `<p class="fcias-error">${escapeHtml(data.error || 'Toggle failed.')}</p>`)
			}
		})
		.catch(() => {
			setHtml('fcias-personal-msg', '<p class="fcias-error">Request failed.</p>')
		})
}

document.addEventListener('DOMContentLoaded', () => {
	loadRules()
	activateTab(tabFromHash('rules', 'faq'))

	document.querySelectorAll<HTMLButtonElement>('.fcias-tab').forEach(btn => {
		btn.addEventListener('click', () => {
			const tab = btn.dataset.tab || 'rules'
			activateTab(tab)
			window.location.hash = tab
		})
	})

	window.addEventListener('hashchange', () => {
		activateTab(tabFromHash('rules', 'faq'))
	})

	createApp(DocsViewer, { endpoint: OCS_ADMIN.getHelp, only: 'docs/FAQ.md' }).mount('#fcias-personal-faq-viewer')

	document.getElementById('fcias-personal-add')?.addEventListener('click', () => { showForm(null) })
	document.getElementById('fcias-personal-save')?.addEventListener('click', saveRule)
	document.getElementById('fcias-personal-cancel')?.addEventListener('click', hideForm)

	const rulesList = document.getElementById('fcias-personal-rules')
	if (rulesList) {
		rulesList.addEventListener('click', event => {
			const btn = (event.target as HTMLElement).closest('button')
			if (!btn) return
			const action = btn.getAttribute('data-action')
			const row = btn.closest('tr')
			const id = row ? row.getAttribute('data-id') : null
			if (!id) return
			if (action === 'edit') {
				const algosText = row!.cells[2].textContent || ''
				const def: PersonalRule = {
					id,
					path: row!.cells[1].textContent || undefined,
					algos: algosText.split(',').map(s => s.trim()),
					mode: row!.cells[3].textContent?.trim() || undefined,
				}
				showForm(def)
			} else if (action === 'toggle') {
				const isEnabled = row!.querySelector('.fcias-compat-pass') !== null
				toggleRule(id, !isEnabled)
			} else if (action === 'delete') {
				deleteRule(id)
			}
		})
	}
})
