/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Composable for the admin settings page: instance status, plus the rule list
 * in its whole-instance view.
 *
 * Rule CRUD itself lives in the shared useRules composable — this page is the
 * `all` view of the same resource the personal page reads as `own`. The
 * status block is the only part specific to this page.
 */

import { reactive, toRefs } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import { OCS_SETTINGS } from '../../routes'
import { useRules } from '../../rules-vue/composables/useRules'
import { formatDateTime, t } from '../../l10n'

/** A background job's last attempt, successful or not. */
interface JobAttempt {
	at: number
	ok: boolean
	durationMs: number | null
	/** The exception's class and message, in English as the server logs it; null for a run that ended normally. */
	reason: string | null
}

interface JobRun {
	/** The last successful run. */
	lastRun: number | null
	counts: Record<string, number>
	/** Null where none was recorded, as for every run before attempts were; absent from an older server. */
	attempt?: JobAttempt | null
}

interface StatusData {
	version?: string
	dbVersion?: string
	/** The indexed checksums, as kept, and when they were counted (Unix time). */
	rowCount?: number
	rowCountAt?: number
	pendingStats?: Record<string, number>
	/** Of the queued files, those that failed at least once and wait behind the rest. */
	pendingFailed?: number
	/** Files whose stored hashes are not to be trusted, by reason. */
	staleStats?: Record<string, number>
	jobs?: Record<string, JobRun>
	idleBannerAcknowledged?: boolean
}

interface State {
	status: StatusData
	/** The first request: versions, jobs, banner flag. */
	statusLoading: boolean
	statusError: string | null
	/** The second: the counts, asked for once the first has answered. */
	countsLoading: boolean
	countsError: string | null
	lastUpdated: string | null
}

export function useAdminSettings() {
	const state = reactive<State>({
		status: {},
		statusLoading: false,
		statusError: null,
		countsLoading: false,
		countsError: null,
		lastUpdated: null,
	})

	const rules = useRules('all')

	let statusAbort: AbortController | null = null

	/**
	 * Read one part of the status into `state.status`, keeping what the other
	 * part brought. Answers '' once it is read, the message on a failure, and
	 * null on an abort.
	 */
	async function readPart(url: string, signal: AbortSignal, httpError: (status: number) => string, failed: string): Promise<string | null> {
		try {
			const response = await fetch(url, { signal })

			// A refused or failed request answers with JSON too, and read as
			// a status it renders every count as zero — which is what a
			// healthy empty instance shows. An error is an error.
			if (!response.ok) {
				return httpError(response.status)
			}

			state.status = { ...state.status, ...((await response.json()) as StatusData) }
			return ''
		} catch (err) {
			if (err instanceof DOMException && err.name === 'AbortError') return null
			return failed
		}
	}

	/**
	 * The information first, then the counts, once it has answered: fired
	 * together, a count warming a cold cache would compete for the disk and
	 * delay the version it does not depend on. Each part's failure is its
	 * own, and leaves the other's rows standing.
	 *
	 * @param recount Count the indexed checksums now, whatever the kept
	 *                count's age: the Refresh button.
	 */
	async function loadStatus(recount = false): Promise<void> {
		statusAbort?.abort()
		statusAbort = new AbortController()
		const { signal } = statusAbort

		state.statusLoading = true
		state.countsLoading = true
		state.statusError = null
		state.countsError = null

		const info = await readPart(
			generateOcsUrl(OCS_SETTINGS.getStatus),
			signal,
			(status) => t('file_checksum_search', 'Could not load the status (HTTP {status}).', { status }),
			t('file_checksum_search', 'Could not load the status.'),
		)
		if (info === null || signal.aborted) return
		state.statusError = info || null
		state.statusLoading = false

		const counts = await readPart(
			generateOcsUrl(OCS_SETTINGS.getStatusCounts) + (recount ? '?recount=1' : ''),
			signal,
			(status) => t('file_checksum_search', 'Could not load the counts (HTTP {status}).', { status }),
			t('file_checksum_search', 'Could not load the counts.'),
		)
		if (counts === null || signal.aborted) return
		state.countsError = counts || null
		state.countsLoading = false

		// When the second answer landed: the counts are the newest the panel has.
		if (!counts) {
			state.lastUpdated = formatDateTime(new Date())
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

		...rules,
	}
}
