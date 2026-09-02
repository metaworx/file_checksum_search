/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Rule CRUD against /api/v1/rules, shared by the admin and personal pages.
 *
 * One resource serves both: what a caller may do follows from who they are,
 * so the pages differ only in which *view* they request. `scope: 'all'` is the
 * administrator's whole-instance view; `scope: 'own'` lists the rules that
 * concern the caller's own files and marks only their own as editable — which
 * is what keeps the personal page personal even for an administrator.
 */

import { reactive, toRefs } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import { API_RULES } from '../../routes'
import type { GroupFolderOption, Rule, RuleDraft } from '../types'

declare const OC: {
	requestToken: string
}

export interface ApiResponse {
	success?: boolean
	error?: string
}

interface RulesResponse extends ApiResponse {
	rules?: Rule[]
	canCreate?: boolean
	supportedAlgos?: string[]
	modes?: string[]
	types?: string[]
	availableUsers?: string[]
	availableGroups?: string[]
	groupFoldersAvailable?: boolean
	groupFoldersLabel?: string | null
	availableGroupFolders?: GroupFolderOption[]
	availableStorages?: string[]
}

interface State {
	rules: Rule[]
	canCreate: boolean
	supportedAlgos: string[]
	modes: string[]
	types: string[]
	availableUsers: string[]
	availableGroups: string[]
	groupFoldersAvailable: boolean
	groupFoldersLabel: string | null
	availableGroupFolders: GroupFolderOption[]
	availableStorages: string[]
	loading: boolean
	error: string | null
}

export function useRules(scope: 'own' | 'all') {
	const state = reactive<State>({
		rules: [],
		canCreate: false,
		supportedAlgos: [],
		modes: [],
		types: [],
		availableUsers: [],
		availableGroups: [],
		groupFoldersAvailable: false,
		groupFoldersLabel: null,
		availableGroupFolders: [],
		availableStorages: [],
		loading: false,
		error: null,
	})

	let abortController: AbortController | null = null

	async function loadRules(): Promise<void> {
		abortController?.abort()
		abortController = new AbortController()
		const { signal } = abortController

		state.loading = true
		state.error = null

		try {
			const url = `${generateOcsUrl(API_RULES.list)}?scope=${scope}`
			const data = (await (await fetch(url, { signal })).json()) as RulesResponse

			state.rules = data.rules || []
			state.canCreate = data.canCreate === true
			state.supportedAlgos = data.supportedAlgos || []
			state.modes = data.modes || []
			state.types = data.types || []
			state.availableUsers = data.availableUsers || []
			state.availableGroups = data.availableGroups || []
			state.groupFoldersAvailable = data.groupFoldersAvailable === true
			state.groupFoldersLabel = data.groupFoldersLabel || null
			state.availableGroupFolders = data.availableGroupFolders || []
			state.availableStorages = data.availableStorages || []
		} catch (err) {
			if (err instanceof DOMException && err.name === 'AbortError') return
			state.error = 'Failed to load rules.'
		} finally {
			if (!signal.aborted) {
				state.loading = false
			}
		}
	}

	async function request(method: string, url: string, body?: unknown): Promise<ApiResponse> {
		try {
			const response = await fetch(url, {
				method,
				headers: {
					requesttoken: OC.requestToken,
					'Content-Type': 'application/json',
				},
				...(body === undefined ? {} : { body: JSON.stringify(body) }),
			})

			// A non-JSON body (an OCS XML error page, a proxy page) must not
			// collapse into a generic message that hides the status code.
			let data: ApiResponse | null = null
			try {
				data = (await response.json()) as ApiResponse
			} catch {
				data = null
			}

			if (data && typeof data.success === 'boolean') {
				return data
			}

			if (!response.ok) {
				return {
					success: false,
					error: `The server answered ${response.status} ${response.statusText || ''}`.trim() + '.',
				}
			}

			return data ?? { success: false, error: 'The server sent an answer this page could not read.' }
		} catch {
			return { success: false, error: 'The request never reached the server.' }
		}
	}

	/** Runs a mutation and reloads only if it took, so a failure leaves the view intact. */
	async function mutate(method: string, url: string, body?: unknown): Promise<ApiResponse> {
		const data = await request(method, url, body)
		if (data.success) {
			await loadRules()
		}
		return data
	}

	function ruleUrl(id: Rule['id']): string {
		// The router substitutes AND encodes the id; substituting by hand
		// after generateOcsUrl() misses, because the braces come back
		// percent-encoded — the request then PUTs to the literal
		// placeholder and 404s.
		return generateOcsUrl(API_RULES.update, { id })
	}

	/** Create when the draft has no id, update when it has one. */
	async function saveRule(draft: RuleDraft & { type?: string; enabled?: boolean }): Promise<ApiResponse> {
		const payload = {
			type: draft.type,
			mode: draft.mode,
			algos: draft.algos,
			path: draft.path,
			selector: draft.selector,
			admin_enforced: draft.admin_enforced,
			enabled: draft.enabled ?? true,
			// Only ever set for the catch-all default; the server ignores it
			// from a non-admin and holds it to an at-most-one invariant.
		}

		return draft.id
			? mutate('PUT', ruleUrl(draft.id), payload)
			: mutate('POST', generateOcsUrl(API_RULES.create), payload)
	}

	async function deleteRule(id: Rule['id']): Promise<ApiResponse> {
		return mutate('DELETE', ruleUrl(id))
	}

	/**
	 * Enabling or disabling is an update of `enabled` — there is no separate
	 * toggle endpoint. The server fills the rest from the stored rule, so a
	 * minimal payload cannot erase it.
	 */
	async function toggleRule(id: Rule['id'], enabled: boolean): Promise<ApiResponse> {
		return mutate('PUT', ruleUrl(id), { enabled })
	}

	/** Reorder one segment partition: which selector, and whether its defaults. */
	async function reorderSegment(selector: string, defaults: boolean, orderedIds: Array<Rule['id']>): Promise<ApiResponse> {
		const result = await request('PUT', generateOcsUrl(API_RULES.order), {
			selector,
			defaults,
			orderedIds,
		})
		if (result.success) {
			await loadRules()
		}
		return result
	}

	/**
	 * Queue a full apply pass for one rule: every file it currently governs,
	 * uncapped, in the background. Applying changes no rule, so
	 * nothing needs reloading.
	 */
	async function applyRule(id: Rule['id']): Promise<ApiResponse> {
		return request('POST', generateOcsUrl(API_RULES.apply, { id }))
	}

	return {
		...toRefs(state),
		loadRules,
		saveRule,
		deleteRule,
		toggleRule,
		applyRule,
		reorderSegment,
	}
}
