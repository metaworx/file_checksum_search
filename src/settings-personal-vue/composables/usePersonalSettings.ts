/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Composable for the personal settings page's rule list. Ported from
 * settings-personal.ts, adding the AbortController stale-response guard
 * on loadRules() that the vanilla page never had.
 */

import { reactive, toRefs } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import { OCS_PERSONAL } from '../../routes'
import type { Rule, RuleDraft } from '../../rules-vue/types'

declare const OC: {
	requestToken: string
}

interface PersonalResponse {
	success?: boolean
	rules?: Rule[]
	canEdit?: boolean
	supportedAlgos?: string[]
	error?: string
}

interface ApiResponse {
	success?: boolean
	error?: string
}

interface State {
	rules: Rule[]
	canEditAny: boolean
	supportedAlgos: string[]
	loading: boolean
	error: string | null
}

export function usePersonalSettings() {
	const state = reactive<State>({
		rules: [],
		canEditAny: false,
		supportedAlgos: [],
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
			const response = await fetch(generateOcsUrl(OCS_PERSONAL.getRules), { signal })
			const data = (await response.json()) as PersonalResponse
			state.canEditAny = data.canEdit === true
			state.supportedAlgos = data.supportedAlgos || []
			state.rules = data.rules || []
		} catch (err) {
			if (err instanceof DOMException && err.name === 'AbortError') return
			state.error = 'Failed to load rules.'
		} finally {
			if (!signal.aborted) {
				state.loading = false
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

	async function saveRule(draft: RuleDraft): Promise<ApiResponse> {
		const data = await post(generateOcsUrl(OCS_PERSONAL.saveRule), {
			id: draft.id || undefined,
			enabled: true,
			mode: draft.mode,
			algos: draft.algos,
			path: draft.path,
		})
		if (data.success) {
			await loadRules()
		}
		return data
	}

	async function deleteRule(id: string | number): Promise<ApiResponse> {
		const data = await post(generateOcsUrl(OCS_PERSONAL.deleteRule), { id })
		if (data.success) {
			await loadRules()
		}
		return data
	}

	async function toggleRule(id: string | number, enabled: boolean): Promise<ApiResponse> {
		const data = await post(generateOcsUrl(OCS_PERSONAL.toggleRule), { id, enabled })
		if (data.success) {
			await loadRules()
		}
		return data
	}

	return {
		...toRefs(state),
		loadRules,
		saveRule,
		deleteRule,
		toggleRule,
	}
}
