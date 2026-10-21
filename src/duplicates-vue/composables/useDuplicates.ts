/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Composable for duplicate group fetching and hash verification.
 */

import { reactive, toRefs } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import { OCS_API_V1 } from '../../routes'
import { fetchFeatures, mimeTypeSwitchedOff } from '../../features'
import { rowLink } from '../../fileLink'
import { t } from '../../l10n'
import type { RowAction } from '../duplicateActions'

declare const OC: {
	requestToken: string
}

export interface DuplicateFileItem {
	fileid: number
	path: string
	name: string
	/** The uid a home file belongs to; null for a group folder or external storage. */
	owner?: string | null
	/** Where the file really lives; shown instead of the path when it is not the viewer's. */
	location?: string
	/** Whether the viewer could open it in the Files app; absent on own listings, where they always can. */
	openable?: boolean
	/** Present on an earlier version of a file: the file's id, and when it held this content. */
	version?: { fileid: number, timestamp: string }
	/** Present on a file in the trash: when it was deleted. */
	trash?: { deletedAt: string }
	/** When the row was last changed, in seconds since the epoch; a version's is when it was kept. */
	mtime?: number
	/** The row's size in bytes. */
	size?: number
	/** What the viewer may do to the row; on their own listing only, which asks for it. */
	actions?: RowAction[]
	verified?: boolean
	verified_hash?: string
	verify_error?: string
}

export interface DuplicateGroup {
	algo: string
	hash_value: string
	file_count: number
	/** The empty files' group: its hash is its algorithm's checksum of no input. */
	empty?: boolean
	files: DuplicateFileItem[]
	match_count?: number
	mismatch_count?: number
	/**
	 * The type of the content the group's files share, as detected; null
	 * where none has one, absent while the server detects none.
	 */
	mimetype?: string | null
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
	/** Only the groups with a file this path pattern matches; empty is no filter. */
	path: string
	/** List the empty files' groups too, which the server leaves out by default. */
	includeEmpty: boolean
	minCount: number
	limit: number
	offset: number
	/**
	 * Where the next page starts, as the server said: it counts groups the
	 * viewer never sees, so it is not offset + limit. Null on the last page.
	 */
	nextOffset: number | null
	groups: DuplicateGroup[]
	loading: boolean
	hasMore: boolean
	verifying: boolean
	error: string | null
	/** Whether the viewer may look across accounts at all; the ordinary listing says. */
	canSudo: boolean
	/** Whether the viewer may recalculate by hand, which is what Verify does; every listing says. */
	canRecalc: boolean
	/** Whose files to show, or null for one's own. Never persisted. */
	scope: DuplicateScope | null
}

export function useDuplicates() {
	const state = reactive<State>({
		algo: '',
		hash: '',
		anywhere: false,
		path: '',
		includeEmpty: false,
		minCount: 2,
		limit: 50,
		offset: 0,
		nextOffset: null,
		groups: [],
		loading: false,
		hasMore: false,
		verifying: false,
		error: null,
		canSudo: false,
		canRecalc: true,
		scope: null,
	})

	let abortController: AbortController | null = null

	/** The offsets of the pages before this one, for Previous: the server's offsets cannot be counted back. */
	const previousOffsets: number[] = []

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
			if (state.path.trim()) {
				params.set('path', state.path.trim())
			}
			if (state.includeEmpty) {
				params.set('includeEmpty', '1')
			}
			// Each group's type, while the server detects types: asked for
			// otherwise, the listing would be refused.
			const asked = (await fetchFeatures()).mimetype
			if (asked) {
				params.set('mimetype', '1')
			}
			// A newer load() began while the features were read: it asks.
			if (signal.aborted) {
				return
			}

			// A scope means the cross-account route. It carries the password
			// confirmation the tab has already been through; a 403 here means
			// the thirty minutes ran out, and the listing says so rather than
			// silently showing one's own files as if nothing happened.
			const scoped = state.scope !== null
			const route = scoped ? OCS_API_V1.sudoFindAllDuplicates : OCS_API_V1.findAllDuplicates

			// What may be done to each row: on one's own listing, the only
			// one the page acts on.
			if (!scoped) {
				params.set('actions', '1')
			}

			if (scoped && !state.scope!.all) {
				for (const uid of state.scope!.users) {
					params.append('users[]', uid)
				}
				for (const gid of state.scope!.groups) {
					params.append('groups[]', gid)
				}
			}

			let response = await fetch(`${generateOcsUrl(route)}?${params.toString()}`, { signal })
			if (await mimeTypeSwitchedOff(response, asked)) {
				params.delete('mimetype')
				response = await fetch(`${generateOcsUrl(route)}?${params.toString()}`, { signal })
			}
			if (response.status === 403 && scoped) {
				state.groups = []
				state.error = t('file_checksum_search', 'That view needs your password confirmed again, or is not yours to look at.')
				return
			}
			if (!response.ok) throw new Error(`HTTP ${response.status}`)
			const data = (await response.json()) as {
				duplicates?: DuplicateGroup[]
				pagination?: { next_offset?: number | null }
				canSudo?: boolean
				canRecalc?: boolean
			}

			state.groups = data.duplicates || []
			state.canRecalc = data.canRecalc !== false
			if (!scoped) {
				state.canSudo = data.canSudo === true
			}
			state.nextOffset = data.pagination?.next_offset ?? null
			state.hasMore = state.nextOffset !== null
		} catch (err) {
			if (err instanceof DOMException && err.name === 'AbortError') return
			state.error = t('file_checksum_search', 'Could not load the duplicates.')
			state.groups = []
		} finally {
			if (!signal.aborted) {
				state.loading = false
			}
		}
	}

	/**
	 * How many files one verification request carries. The server reads at
	 * most this many per call (and at most 100 MiB), so sending more would
	 * only come back as `remaining`.
	 */
	const VERIFY_CHUNK = 25

	interface BatchResult {
		fileid: number
		success?: boolean
		hash?: string
		error?: string
	}

	async function verifyGroups(groups: DuplicateGroup[]): Promise<void> {
		state.verifying = true
		state.error = null

		// The recalc endpoint is rate limited per user (see docs/api-v1.md),
		// and it counts requests. One gesture is one request per chunk of
		// files rather than one per file, so a group of a hundred is four
		// requests, not a hundred; a run that still hits the limit stops
		// there and says so rather than marking what it never asked about.
		let rateLimited = false

		// Only a run that follows an interrupted one resumes; an ordinary
		// repeat click still re-checks every file.
		const resuming = verifyInterrupted

		// A scoped listing shows files that are not the caller's own, and the
		// ordinary route resolves files in the caller's own reach — so it
		// answered "File not found." for every row on the Others tab. The
		// cross-account route reaches what the caller may, per file; it needs
		// the same password confirmation the tab already went through.
		const route = state.scope !== null ? OCS_API_V1.sudoRecalcMany : OCS_API_V1.recalcMany

		for (const group of groups) {
			if (rateLimited) {
				break
			}

			const byId = new Map(group.files.map((f) => [f.fileid, f]))

			// What still needs an answer. A resumed run keeps what the
			// interrupted one already answered and starts after it. A version
			// is not read again: it holds what its file held, and nothing
			// writes to it; nor is a file in the trash, which keeps what it had.
			let queue = group.files.filter((f) => !f.version && !f.trash && !(resuming && f.verified !== undefined))

			while (queue.length > 0 && !rateLimited) {
				const chunk = queue.slice(0, VERIFY_CHUNK)
				queue = queue.slice(VERIFY_CHUNK)

				try {
					const res = await fetch(generateOcsUrl(route), {
						method: 'POST',
						headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
						body: JSON.stringify({ fileIds: chunk.map((f) => f.fileid), algo: group.algo }),
					})
					if (res.status === 429) {
						rateLimited = true
						break
					}
					const data = (await res.json()) as { results?: BatchResult[]; remaining?: number[] }
					const results = data.results ?? []

					for (const result of results) {
						const file = byId.get(result.fileid)
						if (!file) continue
						if (result.success) {
							file.verified_hash = result.hash
							file.verified = result.hash === group.hash_value
						} else {
							file.verified = false
							// TRANSLATORS: a file's verification failed, for no reason the server gave
							file.verify_error = result.error || t('file_checksum_search', 'Failed')
						}
					}

					// The server stopped at its own budget: what it did not read
					// goes back to the front of the queue. A server that read
					// nothing and handed everything back would loop forever, so
					// that is treated as a failure of the chunk instead.
					const remaining = (data.remaining ?? [])
						.map((id) => byId.get(id))
						.filter((f): f is DuplicateFileItem => f !== undefined)
					if (results.length === 0 && remaining.length > 0) {
						for (const file of remaining) {
							file.verified = false
							// TRANSLATORS: a file's verification result: the server did not get to this file
							file.verify_error = t('file_checksum_search', 'Not processed')
						}
					} else {
						queue = [...remaining, ...queue]
					}
				} catch {
					for (const file of chunk) {
						file.verified = false
						file.verify_error = t('file_checksum_search', 'Network error')
					}
				}
			}

			// Leave an interrupted group's counts alone — partial totals would
			// read as a completed verification.
			if (!rateLimited) {
				group.match_count = group.files.filter((f) => f.verified === true).length
				group.mismatch_count = group.files.filter((f) => f.verified === false).length
			}
		}

		verifyInterrupted = rateLimited

		if (rateLimited) {
			state.error = t('file_checksum_search', 'Verification stopped: too many recalculation requests. Wait a minute and verify the remaining files.')
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
		return rowLink(file)
	}

	function resetOffset(): void {
		state.offset = 0
		previousOffsets.length = 0
	}

	/** Back to the page this one came from; to the first, for a page opened from the address. */
	function prevPage(): void {
		state.offset = previousOffsets.pop() ?? 0
	}

	function nextPage(): void {
		if (state.nextOffset === null) {
			return
		}
		previousOffsets.push(state.offset)
		state.offset = state.nextOffset
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
