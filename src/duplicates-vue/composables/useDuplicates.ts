/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Composable for duplicate group fetching and hash verification.
 */

import { reactive, toRefs } from 'vue'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { OCS_API_V1, FRONTEND } from '../../routes'

declare const OC: {
	requestToken: string
}

export interface DuplicateFileItem {
	fileid: number
	path: string
	name: string
	verified?: boolean
	verified_hash?: string
	verify_error?: string
}

export interface DuplicateGroup {
	algo: string
	hash_value: string
	file_count: number
	files: DuplicateFileItem[]
	match_count?: number
	mismatch_count?: number
}

interface State {
	algo: string
	minCount: number
	limit: number
	offset: number
	groups: DuplicateGroup[]
	loading: boolean
	hasMore: boolean
	verifying: boolean
	error: string | null
}

export function useDuplicates() {
	const state = reactive<State>({
		algo: '',
		minCount: 2,
		limit: 50,
		offset: 0,
		groups: [],
		loading: false,
		hasMore: false,
		verifying: false,
		error: null,
	})

	let abortController: AbortController | null = null

	// Set when a verification run stopped early on the recalc rate limit,
	// so the next run resumes instead of replaying what it already did.
	let verifyInterrupted = false

	async function load(): Promise<void> {
		abortController?.abort()
		abortController = new AbortController()
		const { signal } = abortController

		state.loading = true
		state.error = null

		try {
			const params = new URLSearchParams({
				limit: String(state.limit),
				offset: String(state.offset),
				minCount: String(state.minCount),
			})
			if (state.algo) {
				params.set('algo', state.algo)
			}

			const url = `${generateOcsUrl(OCS_API_V1.findAllDuplicates)}?${params.toString()}`
			const response = await fetch(url, { signal })
			if (!response.ok) throw new Error(`HTTP ${response.status}`)
			const data = (await response.json()) as { duplicates?: DuplicateGroup[] }

			state.groups = data.duplicates || []
			state.hasMore = state.groups.length >= state.limit
		} catch (err) {
			if (err instanceof DOMException && err.name === 'AbortError') return
			state.error = 'Failed to load duplicates.'
			state.groups = []
		} finally {
			if (!signal.aborted) {
				state.loading = false
			}
		}
	}

	async function verifyGroups(groups: DuplicateGroup[]): Promise<void> {
		state.verifying = true
		state.error = null

		// The recalc endpoint is rate limited per user (see docs/api-v1.md).
		// Verification issues one request per file, so a large enough run will
		// hit the limit; stop there and say so rather than marking every
		// remaining file as a mismatch.
		let rateLimited = false

		// Only a run that follows an interrupted one resumes; an ordinary
		// repeat click still re-checks every file.
		const resuming = verifyInterrupted

		for (const group of groups) {
			if (rateLimited) {
				break
			}

			let matchCount = 0
			let mismatchCount = 0

			for (const file of group.files) {
				// Already verified by the run that hit the limit — keep its
				// result so this one picks up where that one left off.
				if (resuming && file.verified !== undefined) {
					if (file.verified) {
						matchCount++
					} else {
						mismatchCount++
					}
					continue
				}

				try {
					const url = `${generateOcsUrl(OCS_API_V1.recalcHash, { fileId: file.fileid })}?algo=${group.algo}`
					const res = await fetch(url, {
						method: 'POST',
						headers: { requesttoken: OC.requestToken },
					})
					if (res.status === 429) {
						rateLimited = true
						break
					}
					const result = (await res.json()) as { success?: boolean; hash?: string; error?: string }
					if (result.success) {
						file.verified_hash = result.hash
						if (result.hash === group.hash_value) {
							file.verified = true
							matchCount++
						} else {
							file.verified = false
							mismatchCount++
						}
					} else {
						file.verified = false
						file.verify_error = result.error || 'Failed'
						mismatchCount++
					}
				} catch {
					file.verified = false
					file.verify_error = 'Network error'
					mismatchCount++
				}
			}

			// Leave an interrupted group's counts alone — partial totals would
			// read as a completed verification.
			if (!rateLimited) {
				group.match_count = matchCount
				group.mismatch_count = mismatchCount
			}
		}

		verifyInterrupted = rateLimited

		if (rateLimited) {
			state.error = 'Verification stopped: too many recalculation requests. Wait a minute and verify the remaining files.'
		}

		state.verifying = false
	}

	function fileUrl(file: DuplicateFileItem): string {
		const dirPath = file.path ? (file.path.substring(0, file.path.lastIndexOf('/')) || '/') : '/'
		return `${generateUrl(FRONTEND.fileLink, { fileid: file.fileid })}?dir=${encodeURIComponent(dirPath)}&opendetails=true`
	}

	function resetOffset(): void {
		state.offset = 0
	}

	function prevPage(): void {
		state.offset = Math.max(0, state.offset - state.limit)
	}

	function nextPage(): void {
		state.offset += state.limit
	}

	return {
		...toRefs(state),
		load,
		verifyGroups,
		fileUrl,
		resetOffset,
		prevPage,
		nextPage,
	}
}
