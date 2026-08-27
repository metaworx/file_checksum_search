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
import { useRules } from '../../rules-vue/composables/useRules'

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
		saveRule: rules.saveRule,
		deleteRule: rules.deleteRule,
		toggleRule: rules.toggleRule,
		reorderSegment: rules.reorderSegment,
	}
}
