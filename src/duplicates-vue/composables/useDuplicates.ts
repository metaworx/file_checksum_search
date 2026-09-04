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

/**
 * Whose files a listing shows. Null is one's own — the ordinary listing.
 * `all` is every account, offered to a sudoer only; otherwise the named
 * accounts and groups, which the server expands and authorises.
 */
export interface DuplicateScope {
	all: boolean
	users: string[]
	groups: string[]
}

interface State {
	algo: string
	/** Filter to hashes this names; empty is no filter. */
	hash: string
	/** Match the term anywhere in the hash rather than at its start. */
	anywhere: boolean
	minCount: number
	limit: number
	offset: number
	groups: DuplicateGroup[]
	loading: boolean
	hasMore: boolean
	verifying: boolean
	error: string | null
	/** Whether the viewer may look across accounts at all; the ordinary listing says. */
	canSudo: boolean
	/** Whose files to show, or null for one's own. Never persisted. */
	scope: DuplicateScope | null
}

export function useDuplicates() {
	const state = reactive<State>({
		algo: '',
		hash: '',
		anywhere: false,
		minCount: 2,
		limit: 50,
		offset: 0,
		groups: [],
		loading: false,
		hasMore: false,
		verifying: false,
		error: null,
		canSudo: false,
		scope: null,
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
			if (state.hash.trim()) {
				params.set('hash', state.hash.trim())
				if (state.anywhere) {
					params.set('anywhere', '1')
				}
			}

			// A scope means the cross-account route. It carries the password
			// confirmation the tab has already been through; a 403 here means
			// the thirty minutes ran out, and the listing says so rather than
			// silently showing one's own files as if nothing happened.
			const scoped = state.scope !== null
			const route = scoped ? OCS_API_V1.sudoFindAllDuplicates : OCS_API_V1.findAllDuplicates

			if (scoped && !state.scope!.all) {
				for (const uid of state.scope!.users) {
					params.append('users[]', uid)
				}
				for (const gid of state.scope!.groups) {
					params.append('groups[]', gid)
				}
			}

			const url = `${generateOcsUrl(route)}?${params.toString()}`
			const response = await fetch(url, { signal })
			if (response.status === 403 && scoped) {
				state.groups = []
				state.error = 'That view needs your password confirmed again, or is not yours to look at.'
				return
			}
			if (!response.ok) throw new Error(`HTTP ${response.status}`)
			const data = (await response.json()) as { duplicates?: DuplicateGroup[], canSudo?: boolean }

			state.groups = data.duplicates || []
			if (!scoped) {
				state.canSudo = data.canSudo === true
			}
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

		// A scoped listing shows files that are not the caller's own, and the
		// ordinary route resolves the file in the caller's own home — so it
		// answered "File not found." for every row on the Others tab. The
		// cross-account route asks whether the caller may reach the file
		// instead; it needs the same password confirmation the tab already
		// went through.
		const route = state.scope !== null ? OCS_API_V1.sudoRecalcHash : OCS_API_V1.recalcHash

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
					const url = `${generateOcsUrl(route, { fileId: file.fileid })}?algo=${group.algo}`
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

	/**
	 * Verify one file, through the same loop a group goes through — so it
	 * obeys the same rate limit and reports the same way. The group's counts
	 * are refreshed from its files afterwards, since one file changing its
	 * answer changes what the header says.
	 */
	async function verifyFile(group: DuplicateGroup, file: DuplicateFileItem): Promise<void> {
		// An explicit click always re-reads the file. Without this the resume
		// path — which skips what an interrupted run already answered — would
		// quietly return the old verdict instead of checking again.
		file.verified = undefined
		file.verified_hash = undefined
		file.verify_error = undefined

		await verifyGroups([{ ...group, files: [file] }])

		// verifyGroups counted the group of one; recount the real group from
		// what its files now say, leaving the never-verified ones out.
		const judged = group.files.filter((f) => f.verified !== undefined)

		if (judged.length === group.files.length) {
			group.match_count = judged.filter((f) => f.verified).length
			group.mismatch_count = judged.length - group.match_count
		} else {
			// Not every file has an answer yet, so the header must not claim
			// the group was verified.
			group.match_count = undefined
			group.mismatch_count = undefined
		}
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
		verifyFile,
		fileUrl,
		resetOffset,
		prevPage,
		nextPage,
	}
}
