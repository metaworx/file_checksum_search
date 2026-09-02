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

interface JobRun {
	lastRun: number | null
	counts: Record<string, number>
}

interface StatusData {
	version?: string
	dbVersion?: string
	rowCount?: number
	pendingStats?: Record<string, number>
	/** Files whose stored hashes are not to be trusted, by reason. */
	staleStats?: Record<string, number>
	jobs?: Record<string, JobRun>
	idleBannerAcknowledged?: boolean
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
	 * Persist the admin's "leave it off" decision. The flag clears itself
	 * server-side the moment an include rule is enabled, so a later return
	 * to the idle state shows the banner afresh.
	 */
	async function acknowledgeIdleBanner(): Promise<boolean> {
		try {
			const response = await fetch(generateOcsUrl(OCS_SETTINGS.ackIdleBanner), {
				method: 'POST',
				headers: {
					requesttoken: (window as unknown as { OC: { requestToken: string } }).OC.requestToken,
				},
			})
			if (!response.ok) return false
			state.status = { ...state.status, idleBannerAcknowledged: true }
			return true
		} catch {
			return false
		}
	}

	return {
		...toRefs(state),
		loadStatus,
		acknowledgeIdleBanner,

		rules: rules.rules,
		supportedAlgos: rules.supportedAlgos,
		availableUsers: rules.availableUsers,
		availableGroups: rules.availableGroups,
		groupFoldersAvailable: rules.groupFoldersAvailable,
		groupFoldersLabel: rules.groupFoldersLabel,
		availableGroupFolders: rules.availableGroupFolders,
		availableStorages: rules.availableStorages,
		modes: rules.modes,
		types: rules.types,
		error: rules.error,

		loadRules: rules.loadRules,
		saveRule: rules.saveRule,
		deleteRule: rules.deleteRule,
		applyRule: rules.applyRule,
		toggleRule: rules.toggleRule,
		reorderSegment: rules.reorderSegment,
	}
}
