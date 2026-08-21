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
	const error = ref('')
	const recalculating = ref<string | null>(null)
	const recalcError = ref<string | null>(null)
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
			return
		}

		loading.value = true
		error.value = ''
		hashes.value = []

		try {
			const url = generateOcsUrl(OCS_API_V1.getHashes, { fileId: id })
			const response = await fetch(url, { signal: abortController.signal })
			if (!response.ok) throw new Error(`HTTP ${response.status}`)
			const data = (await response.json()) as { hashes?: HashEntry[] }
			hashes.value = data.hashes || []
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

		try {
			const url = generateOcsUrl(OCS_API_V1.recalcHash, { fileId: id })
			const response = await fetch(`${url}?algo=${algo}`, {
				method: 'POST',
				headers: { requesttoken: OC.requestToken },
			})
			const result = (await response.json()) as { success?: boolean }
			if (result.success) {
				await loadHashes()
			} else {
				recalcError.value = algo
			}
		} catch {
			recalcError.value = algo
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
		error,
		recalculating,
		recalcError,
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
