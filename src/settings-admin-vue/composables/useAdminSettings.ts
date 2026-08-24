/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Composable for the admin settings page: instance status, plus the rule list
 * in its whole-instance view.
 *
 * Rule CRUD itself lives in the shared useRules composable — this page is the
 * `all` view of the same resource the personal page reads as `own`. Only the
 * status block and the catch-all default's fixed reach are specific to it.
 */

import { reactive, toRefs } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import { OCS_SETTINGS } from '../../routes'
import { useRules, type ApiResponse } from '../../rules-vue/composables/useRules'
import type { Rule } from '../../rules-vue/types'

interface StatusData {
	version?: string
	dbVersion?: string
	rowCount?: number
	pendingStats?: Record<string, number>
}

interface State {
	status: StatusData
	statusLoading: boolean
	statusError: string | null
	lastUpdated: string | null
}

export function useAdminSettings() {
	const state = reactive<State>({
		status: {},
		statusLoading: false,
		statusError: null,
		lastUpdated: null,
	})

	const rules = useRules('all')

	let statusAbort: AbortController | null = null

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

	/**
	 * The catch-all default rule, identified by its `pinned` flag.
	 *
	 * It used to be whatever sat at index 0. Rules are now stored in band
	 * order and the catch-all evaluates *last*, so slot 0 is no longer it —
	 * the flag is the only reliable identifier.
	 */
	function globalRule(): Rule | null {
		return rules.rules.value.find((rule) => rule.pinned === true) ?? null
	}

	/** Every rule except the pinned catch-all, in band order. */
	function additionalRules(): Rule[] {
		return rules.rules.value.filter((rule) => rule.pinned !== true)
	}

	/**
	 * Save the catch-all default. Its reach is pinned server-side, so a
	 * locked-but-tampered form can never narrow it into an ordinary rule.
	 */
	async function saveGlobalRule(fields: {
		id?: Rule['id']
		mode: string
		algos: string[]
		admin_enforced: boolean
	}): Promise<ApiResponse> {
		return rules.saveRule({
			id: fields.id,
			mode: fields.mode,
			algos: fields.algos,
			userScope: 'all',
			path: '**',
			admin_enforced: fields.admin_enforced,
			// Marks this as the catch-all default, which evaluates last.
			pinned: true,
		})
	}

	return {
		...toRefs(state),
		loadStatus,

		definitions: rules.rules,
		supportedAlgos: rules.supportedAlgos,
		availableUsers: rules.availableUsers,
		availableGroups: rules.availableGroups,
		modes: rules.modes,
		types: rules.types,
		definitionsError: rules.error,

		loadDefinitions: rules.load,
		globalRule,
		additionalRules,
		saveGlobalRule,
		saveRule: rules.saveRule,
		deleteRule: rules.deleteRule,
		toggleRule: rules.toggleRule,
		reorderBand: rules.reorderBand,
	}
}
