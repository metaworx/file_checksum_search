/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Composable for the admin settings page: status, the global rule, the
 * additional-rules list, and the crontab snippet generator. Ported from
 * settings-admin.ts, adding the AbortController stale-response guard on
 * loadStatus()/loadDefinitions() that the vanilla page never had.
 */

import { reactive, toRefs } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import { OCS_SETTINGS } from '../../routes'
import type { Rule, RuleDraft } from '../../rules-vue/types'

declare const OC: {
	requestToken: string
}

interface StatusData {
	version?: string
	dbVersion?: string
	rowCount?: number
	pendingStats?: Record<string, number>
}

interface DefinitionsResponse {
	success?: boolean
	supportedAlgos?: string[]
	users?: string[]
	definitions?: Rule[]
}

interface ApiResponse {
	success?: boolean
	error?: string
	snippet?: string
}

interface SnippetParams {
	userScope: string
	path: string
	algo: string
	batchSize: string
	interval: string
}

interface State {
	status: StatusData
	statusLoading: boolean
	statusError: string | null
	lastUpdated: string | null

	definitions: Rule[]
	supportedAlgos: string[]
	availableUsers: string[]
	definitionsLoading: boolean
	definitionsError: string | null

	snippetFormVisible: boolean
	snippet: string
	snippetVisible: boolean
}

export function useAdminSettings() {
	const state = reactive<State>({
		status: {},
		statusLoading: false,
		statusError: null,
		lastUpdated: null,

		definitions: [],
		supportedAlgos: [],
		availableUsers: [],
		definitionsLoading: false,
		definitionsError: null,

		snippetFormVisible: false,
		snippet: '',
		snippetVisible: false,
	})

	let statusAbort: AbortController | null = null
	let definitionsAbort: AbortController | null = null

	async function loadStatus(): Promise<void> {
		statusAbort?.abort()
		statusAbort = new AbortController()
		const { signal } = statusAbort

		state.statusLoading = true
		state.statusError = null

		try {
			const response = await fetch(generateOcsUrl(OCS_SETTINGS.getStatus), { signal })
			state.status = (await response.json()) as StatusData
			state.lastUpdated = new Date().toLocaleString()
		} catch (err) {
			if (err instanceof DOMException && err.name === 'AbortError') return
			state.statusError = 'Failed to load status.'
		} finally {
			if (!signal.aborted) {
				state.statusLoading = false
			}
		}
	}

	async function loadDefinitions(): Promise<void> {
		definitionsAbort?.abort()
		definitionsAbort = new AbortController()
		const { signal } = definitionsAbort

		state.definitionsLoading = true
		state.definitionsError = null

		try {
			const response = await fetch(generateOcsUrl(OCS_SETTINGS.listRules), { signal })
			const data = (await response.json()) as DefinitionsResponse
			state.supportedAlgos = data.supportedAlgos || []
			state.availableUsers = data.users || []
			state.definitions = data.definitions || []
		} catch (err) {
			if (err instanceof DOMException && err.name === 'AbortError') return
			state.definitionsError = 'Failed to load definitions.'
		} finally {
			if (!signal.aborted) {
				state.definitionsLoading = false
			}
		}
	}

	async function post(url: string, body: unknown): Promise<ApiResponse> {
		try {
			const response = await fetch(url, {
				method: 'POST',
				headers: {
					requesttoken: OC.requestToken,
					'Content-Type': 'application/json',
				},
				body: JSON.stringify(body),
			})
			return (await response.json()) as ApiResponse
		} catch {
			return { success: false, error: 'Request failed.' }
		}
	}

	/** The first definition returned by listRules is always the global (priority 0) rule. */
	function globalRule(): Rule | null {
		return state.definitions[0] ?? null
	}

	/** Every definition after the global rule. */
	function additionalRules(): Rule[] {
		return state.definitions.slice(1)
	}

	async function saveGlobalRule(fields: { id?: string | number; mode: string; algos: string[]; admin_enforced: boolean }): Promise<ApiResponse> {
		const data = await post(generateOcsUrl(OCS_SETTINGS.saveRule), {
			id: fields.id || undefined,
			enabled: true,
			mode: fields.mode,
			algos: fields.algos,
			userScope: 'all',
			path: '**',
			admin_enforced: fields.admin_enforced,
		})
		if (data.success) {
			await loadDefinitions()
		}
		return data
	}

	async function saveRule(draft: RuleDraft): Promise<ApiResponse> {
		const data = await post(generateOcsUrl(OCS_SETTINGS.saveRule), {
			id: draft.id || undefined,
			enabled: true,
			mode: draft.mode,
			algos: draft.algos,
			userScope: draft.userScope,
			path: draft.path,
			admin_enforced: draft.admin_enforced,
		})
		if (data.success) {
			await loadDefinitions()
		}
		return data
	}

	async function deleteRule(id: string | number): Promise<ApiResponse> {
		const data = await post(generateOcsUrl(OCS_SETTINGS.deleteRule), { id })
		if (data.success) {
			await loadDefinitions()
		}
		return data
	}

	async function toggleRule(id: string | number, enabled: boolean): Promise<ApiResponse> {
		const data = await post(generateOcsUrl(OCS_SETTINGS.toggleRule), { id, enabled })
		if (data.success) {
			await loadDefinitions()
		}
		return data
	}

	function revealSnippetForm(): void {
		state.snippetFormVisible = true
	}

	async function generateSnippet(params: SnippetParams): Promise<void> {
		const query = new URLSearchParams(params)
		const response = await fetch(`${generateOcsUrl(OCS_SETTINGS.getCrontabSnippet)}?${query.toString()}`)
		const data = (await response.json()) as ApiResponse
		state.snippet = data.snippet || ''
		state.snippetVisible = true
	}

	return {
		...toRefs(state),
		loadStatus,
		loadDefinitions,
		globalRule,
		additionalRules,
		saveGlobalRule,
		saveRule,
		deleteRule,
		toggleRule,
		revealSnippetForm,
		generateSnippet,
	}
}
