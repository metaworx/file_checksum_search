/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Composable for loading, recalculating, and duplicate-lookup of file checksums.
 */
import { computed, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import { OCS_API_V1 } from '../../routes'
import type { DuplicateGroup, FileNode, HashEntry } from '../types'

declare const OC: {
	requestToken: string
}

export function useSidebarHashes(getNode: () => FileNode | null) {
	const loading = ref(false)
	const hashes = ref<HashEntry[]>([])
	// What the quick buttons are composed from; see ChecksumsSidebarTab.
	const ruleAlgos = ref<string[]>([])
	const preferredAlgo = ref('')
	const defaultAlgo = ref('')
	/**
	 * Whether this user may trigger a recalculation at all — the server's
	 * manual-recalculation permission, carried on the hashes response so
	 * the buttons are hidden rather than shown to fail. True until told
	 * otherwise: the section renders only after the response has landed.
	 */
	const canRecalc = ref(true)
	/**
	 * Whether this user may look across accounts — carried on the same
	 * response, so the way to the Duplicates page's Others tab is offered
	 * to those who may and to nobody else. False until told otherwise: an
	 * offer that leads to a refusal is worse than none.
	 */
	const canSudo = ref(false)
	const error = ref('')
	const recalculating = ref<string | null>(null)
	const recalcError = ref<string | null>(null)
	/** Why the last recalculation failed, when the server said. */
	const recalcErrorMessage = ref('')
	const duplicates = ref<DuplicateGroup[] | null>(null)
	const searching = ref(false)
	const dupError = ref('')
	const showDuplicates = ref(false)

	let abortController: AbortController | null = null

	const fileId = computed<number | null>(() => {
		const node = getNode()
		return node?.fileid ?? node?.attributes?.fileid ?? null
	})

	async function loadHashes(): Promise<void> {
		abortController?.abort()
		abortController = new AbortController()
		duplicates.value = null
		showDuplicates.value = false
		dupError.value = ''

		const id = fileId.value
		if (id === null) {
			hashes.value = []
			ruleAlgos.value = []
			preferredAlgo.value = ''
			defaultAlgo.value = ''
			return
		}

		loading.value = true
		error.value = ''
		hashes.value = []

		try {
			const url = generateOcsUrl(OCS_API_V1.getHashes, { fileId: id })
			const response = await fetch(url, { signal: abortController.signal })
			if (!response.ok) throw new Error(`HTTP ${response.status}`)
			const data = (await response.json()) as {
				hashes?: HashEntry[]
				algos?: string[]
				preferred?: string
				default?: string
				canRecalc?: boolean
				canSudo?: boolean
			}
			hashes.value = data.hashes || []
			ruleAlgos.value = Array.isArray(data.algos) ? data.algos : []
			preferredAlgo.value = typeof data.preferred === 'string' ? data.preferred : ''
			defaultAlgo.value = typeof data.default === 'string' ? data.default : ''
			canRecalc.value = data.canRecalc !== false
			canSudo.value = data.canSudo === true
		} catch (err) {
			if (err instanceof DOMException && err.name === 'AbortError') return
			error.value = 'Failed to load checksums.'
		} finally {
			loading.value = false
		}
	}

	async function recalc(algo: string): Promise<void> {
		const id = fileId.value
		if (id === null) return

		recalculating.value = algo
		recalcError.value = null
		recalcErrorMessage.value = ''

		try {
			const url = generateOcsUrl(OCS_API_V1.recalcHash, { fileId: id })
			const response = await fetch(`${url}?algo=${algo}`, {
				method: 'POST',
				headers: { requesttoken: OC.requestToken },
			})
			const result = (await response.json()) as { success?: boolean; error?: string }
			if (result.success) {
				await loadHashes()
			} else {
				recalcError.value = algo
				// A refusal is usually policy, not breakage — an exclude rule
				// blocking the read. "Error" alone leaves that looking like a
				// malfunction to retry, so say what the server said.
				recalcErrorMessage.value = result.error || 'Recalculation failed.'
			}
		} catch {
			recalcError.value = algo
			recalcErrorMessage.value = 'Recalculation failed.'
		} finally {
			recalculating.value = null
		}
	}

	async function toggleDuplicates(): Promise<void> {
		if (duplicates.value !== null) {
			showDuplicates.value = !showDuplicates.value
			return
		}

		searching.value = true
		showDuplicates.value = true
		dupError.value = ''

		try {
			const id = fileId.value
			if (id === null) throw new Error('No file selected')
			const url = generateOcsUrl(OCS_API_V1.findDuplicates, { fileId: id })
			const response = await fetch(url)
			if (!response.ok) throw new Error(`HTTP ${response.status}`)
			const data = (await response.json()) as { duplicates?: DuplicateGroup[] }
			duplicates.value = data.duplicates || []
		} catch {
			dupError.value = 'Failed to load duplicates.'
			duplicates.value = []
		} finally {
			searching.value = false
		}
	}

	return {
		loading,
		hashes,
		ruleAlgos,
		preferredAlgo,
		defaultAlgo,
		canRecalc,
		canSudo,
		error,
		recalculating,
		recalcError,
		recalcErrorMessage,
		duplicates,
		searching,
		dupError,
		showDuplicates,
		fileId,
		loadHashes,
		recalc,
		toggleDuplicates,
	}
}
