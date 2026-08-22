import { afterEach, describe, expect, it, vi } from 'vitest'
import { useDuplicates } from './useDuplicates'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => url,
	generateUrl: (url: string) => url,
}))

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200 })
}

/**
 * A fetch mock whose returned promise rejects with an AbortError as
 * soon as the request's signal is aborted — mirroring real fetch()
 * behavior, unlike a plain resolved/rejected mock.
 */
function mockAbortableFetch(): { fetchMock: ReturnType<typeof vi.spyOn>; pending: Array<(response: Response) => void> } {
	const pending: Array<(response: Response) => void> = []

	const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((_url, options) => {
		let resolveFn!: (value: Response) => void
		const promise = new Promise<Response>((resolve, reject) => {
			resolveFn = resolve
			const signal = (options as RequestInit | undefined)?.signal
			signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))
		})
		pending.push(resolveFn)
		return promise
	})

	return { fetchMock, pending }
}

describe('useDuplicates', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('discards a stale response when a newer load() supersedes it', async () => {
		// Regression test for FCIAS Review §3, Finding 4: load() had no
		// request-cancellation guard, so an earlier request resolving
		// after a newer one could overwrite it with stale data.
		const { pending } = mockAbortableFetch()

		const { groups, error, load } = useDuplicates()

		const loadA = load() // request #1, still pending
		const loadB = load() // aborts #1 (if guarded), starts request #2

		pending[1](
			jsonResponse({
				duplicates: [{ algo: 'sha256', hash_value: 'new', file_count: 2, files: [] }],
			}),
		)
		await loadB

		// Resolve the stale first request *after* the newer one settled.
		// A correctly-aborted request #1 rejects before this call does
		// anything; an unguarded load() would instead let this stale
		// response overwrite the newer one just applied above.
		pending[0](
			jsonResponse({
				duplicates: [{ algo: 'sha1', hash_value: 'stale', file_count: 1, files: [] }],
			}),
		)
		await loadA

		expect(groups.value).toEqual([
			{ algo: 'sha256', hash_value: 'new', file_count: 2, files: [] },
		])
		expect(error.value).toBeNull()
	})

	it('loads duplicate groups on success', async () => {
		const { pending } = mockAbortableFetch()

		const { groups, hasMore, load } = useDuplicates()

		const p = load()
		pending[0](
			jsonResponse({
				duplicates: [{ algo: 'sha1', hash_value: 'abc', file_count: 3, files: [] }],
			}),
		)
		await p

		expect(groups.value).toEqual([
			{ algo: 'sha1', hash_value: 'abc', file_count: 3, files: [] },
		])
		expect(hasMore.value).toBe(false)
	})
})
