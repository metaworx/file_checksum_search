import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useDuplicates } from './useDuplicates'

// Both substitute placeholders the way the real ones do; a stand-in that
// returned the template unchanged would pass `{fileid}` through and let a
// link test assert on nothing.
const substitute = (url: string, params?: Record<string, unknown>) =>
	url.replace(/\{(\w+)\}/g, (whole, token) => (params && token in params ? String(params[token]) : whole))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string, params?: Record<string, unknown>) => substitute(url, params),
	generateUrl: (url: string, params?: Record<string, unknown>) => substitute(url, params),
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

	describe('verifyGroups', () => {
		// The recalc endpoint is rate limited per user, and verification
		// issues one request per file, so a long enough run will hit the
		// limit mid-way.
		beforeEach(() => {
			vi.stubGlobal('OC', { requestToken: 'token' })
		})

		afterEach(() => {
			vi.unstubAllGlobals()
		})

		function group(...names: string[]) {
			return {
				algo: 'sha256',
				hash_value: 'abc',
				file_count: names.length,
				files: names.map((name, index) => ({ fileid: index + 1, path: `/${name}`, name })),
			}
		}

		/** The ids one batch request asked for, read back from its body. */
		function requestedIds(init: RequestInit | undefined): number[] {
			return (JSON.parse(String(init?.body)) as { fileIds: number[] }).fileIds
		}

		/** A server that answers every id it is asked for with the group hash. */
		function batchOk(hash = 'abc') {
			return (_url: unknown, init?: RequestInit) =>
				Promise.resolve(jsonResponse({
					results: requestedIds(init).map((fileid) => ({ fileid, success: true, hash })),
					remaining: [],
				}))
		}

		it('marks files as verified against the group hash', async () => {
			vi.spyOn(globalThis, 'fetch').mockImplementation(batchOk())

			const { verifyGroups } = useDuplicates()
			const g = group('a.txt', 'b.txt')
			await verifyGroups([g])

			expect(g.files.every((f) => f.verified === true)).toBe(true)
			expect(g.match_count).toBe(2)
			expect(g.mismatch_count).toBe(0)
		})

		// One gesture, one request per chunk: a group of thirty is two
		// requests of 25 and 5, not thirty — which is what keeps a group from
		// exhausting a limit of twenty requests a minute on its own.
		it('sends one request per chunk of 25, in order', async () => {
			const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(batchOk())

			const { verifyGroups } = useDuplicates()
			const g = group(...Array.from({ length: 30 }, (_v, i) => `f${i}.txt`))
			await verifyGroups([g])

			expect(fetchMock).toHaveBeenCalledTimes(2)
			expect(requestedIds(fetchMock.mock.calls[0][1])).toHaveLength(25)
			expect(requestedIds(fetchMock.mock.calls[1][1])).toEqual([26, 27, 28, 29, 30])
			expect(g.match_count).toBe(30)
		})

		// The server has its own budget in bytes as well as files; what it
		// did not get to comes back as `remaining` and is asked for again,
		// ahead of anything not yet sent.
		it('re-sends what the server handed back as remaining', async () => {
			let call = 0
			const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((_url, init) => {
				call++
				const ids = requestedIds(init)
				// First answer: read the first id only, hand the rest back.
				const done = call === 1 ? ids.slice(0, 1) : ids
				return Promise.resolve(jsonResponse({
					results: done.map((fileid) => ({ fileid, success: true, hash: 'abc' })),
					remaining: ids.filter((id) => !done.includes(id)),
				}))
			})

			const { verifyGroups } = useDuplicates()
			const g = group('a.txt', 'b.txt', 'c.txt')
			await verifyGroups([g])

			expect(fetchMock).toHaveBeenCalledTimes(2)
			expect(requestedIds(fetchMock.mock.calls[1][1])).toEqual([2, 3])
			expect(g.match_count).toBe(3)
		})

		// A server that reads nothing and hands everything back would be
		// asked again forever; that answer is a failure of the chunk instead.
		it('does not loop on a server that processes nothing', async () => {
			const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((_url, init) =>
				Promise.resolve(jsonResponse({ results: [], remaining: requestedIds(init) })),
			)

			const { verifyGroups } = useDuplicates()
			const g = group('a.txt', 'b.txt')
			await verifyGroups([g])

			expect(fetchMock).toHaveBeenCalledTimes(1)
			expect(g.files.map((f) => f.verify_error)).toEqual(['Not processed', 'Not processed'])
			expect(g.mismatch_count).toBe(2)
		})

		it('stops verifying and reports the limit when the recalc endpoint returns 429', async () => {
			// Regression test: the loop used to parse the 429's empty body,
			// find no `success` field, and silently mark every remaining
			// file as a mismatch — indistinguishable from real hash drift.
			let calls = 0
			vi.spyOn(globalThis, 'fetch').mockImplementation((url, init) => {
				calls++
				return calls === 1
					? batchOk()(url, init)
					: Promise.resolve(new Response('[]', { status: 429 }))
			})

			const { error, verifyGroups } = useDuplicates()
			// 26 files: the first chunk of 25 answers, the second hits the limit.
			const g = group(...Array.from({ length: 26 }, (_v, i) => `f${i}.txt`))
			await verifyGroups([g])

			expect(g.files.slice(0, 25).every((f) => f.verified === true)).toBe(true)
			// The file past the limit is left untouched, not marked as
			// mismatching.
			expect(g.files[25].verified).toBeUndefined()
			expect(error.value).toMatch(/too many recalculation requests/i)
		})

		it('leaves an interrupted group without match counts', async () => {
			// Partial totals would read as a completed verification.
			vi.spyOn(globalThis, 'fetch').mockImplementation(() =>
				Promise.resolve(new Response('[]', { status: 429 })),
			)

			const { verifyGroups } = useDuplicates()
			const g = group('a.txt', 'b.txt')
			await verifyGroups([g])

			expect(g.match_count).toBeUndefined()
			expect(g.mismatch_count).toBeUndefined()
		})

		it('resumes where the interrupted run stopped, and only then', async () => {
			let attempt = 0
			const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((url, init) => {
				attempt++
				return attempt === 1
					? batchOk()(url, init)
					: Promise.resolve(new Response('[]', { status: 429 }))
			})

			const { verifyGroups } = useDuplicates()
			const g = group(...Array.from({ length: 30 }, (_v, i) => `f${i}.txt`))
			await verifyGroups([g])
			expect(fetchMock).toHaveBeenCalledTimes(2)

			// The limit has cleared: the second run skips the 25 the first one
			// already verified and asks only for the five it could not reach.
			fetchMock.mockImplementation(batchOk())
			fetchMock.mockClear()
			await verifyGroups([g])
			expect(fetchMock).toHaveBeenCalledTimes(1)
			expect(requestedIds(fetchMock.mock.calls[0][1])).toEqual([26, 27, 28, 29, 30])
			expect(g.match_count).toBe(30)

			// That run completed, so a further click is an ordinary re-check
			// of every file, not a resume.
			fetchMock.mockClear()
			await verifyGroups([g])
			expect(fetchMock).toHaveBeenCalledTimes(2)
		})

		// Verification is asked for per file now, so one file must be able to
		// answer without its neighbours being read — that is the whole point
		// of the change: reading costs money on metered storage.
		it('verifies one file without touching the rest of its group', async () => {
			const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(batchOk())

			const { verifyFile } = useDuplicates()
			const g = group('a.txt', 'b.txt')
			await verifyFile(g, g.files[0])

			expect(fetchMock).toHaveBeenCalledTimes(1)
			expect(requestedIds(fetchMock.mock.calls[0][1])).toEqual([1])
			expect(g.files[0].verified).toBe(true)
			expect(g.files[1].verified).toBeUndefined()
		})

		// The header must not claim a verdict for a group only half of which
		// has been read.
		it('leaves the group unjudged until every file has an answer', async () => {
			vi.spyOn(globalThis, 'fetch').mockImplementation(batchOk())

			const { verifyFile } = useDuplicates()
			const g = group('a.txt', 'b.txt')

			await verifyFile(g, g.files[0])
			expect(g.match_count).toBeUndefined()

			await verifyFile(g, g.files[1])
			expect(g.match_count).toBe(2)
			expect(g.mismatch_count).toBe(0)
		})

		// An explicit click re-reads, even where an interrupted run had
		// already answered for that file.
		it('re-checks a file that was already verified', async () => {
			const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(batchOk())

			const { verifyFile } = useDuplicates()
			const g = group('a.txt')
			await verifyFile(g, g.files[0])
			fetchMock.mockClear()

			await verifyFile(g, g.files[0])
			expect(fetchMock).toHaveBeenCalledTimes(1)
		})

		it('does not start later groups once the limit was hit', async () => {
			const fetchMock = vi
				.spyOn(globalThis, 'fetch')
				.mockImplementation(() => Promise.resolve(new Response('[]', { status: 429 })))

			const { verifyGroups } = useDuplicates()
			await verifyGroups([group('a.txt'), group('b.txt')])

			// One attempt, then the run stops — the second group is never
			// requested.
			expect(fetchMock).toHaveBeenCalledTimes(1)
		})

		// The bug this fixes: the Others tab answered "File not found." for
		// every row, because the ordinary route resolves the file in the
		// caller's own home and none of those files are there.
		it('verifies a scoped listing through the cross-account route', async () => {
			const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(batchOk())

			const { scope, verifyGroups } = useDuplicates()
			scope.value = { all: true, users: [], groups: [] }
			await verifyGroups([group('a.txt')])

			expect(fetchMock.mock.calls[0][0]).toContain('/sudo/file/many/recalc')
		})

		it('verifies an unscoped listing through the ordinary route', async () => {
			const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(batchOk())

			const { verifyGroups } = useDuplicates()
			await verifyGroups([group('a.txt')])

			expect(fetchMock.mock.calls[0][0]).toContain('/file/many/recalc')
			expect(fetchMock.mock.calls[0][0]).not.toContain('/sudo/')
		})
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

describe('fileUrl', () => {
	// Core resolves the id in the viewer's own folder and works the directory
	// out for itself; a `dir` sent along was never read, so none is sent.
	it('links by id alone, opening the details pane', () => {
		const { fileUrl } = useDuplicates()
		const url = fileUrl({ fileid: 42, path: '/Docs/report.pdf', name: 'report.pdf' })

		expect(url).toContain('/apps/files/files/42')
		expect(url).toContain('opendetails=true')
		expect(url).not.toContain('dir=')
	})
})

describe('the hash filter', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('sends nothing extra when the field is empty', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ duplicates: [] }))
		const { load } = useDuplicates()

		await load()

		const url = String(fetchMock.mock.calls[0][0])
		expect(url).not.toContain('hash=')
		expect(url).not.toContain('anywhere=')
	})

	it('sends the term, trimmed, and no anywhere flag by default', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ duplicates: [] }))
		const { hash, load } = useDuplicates()

		hash.value = '  0beec7  '
		await load()

		const url = String(fetchMock.mock.calls[0][0])
		expect(url).toContain('hash=0beec7')
		expect(url).not.toContain('anywhere=')
	})

	it('sends the anywhere flag when asked', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ duplicates: [] }))
		const { hash, anywhere, load } = useDuplicates()

		hash.value = 'eec7'
		anywhere.value = true
		await load()

		expect(String(fetchMock.mock.calls[0][0])).toContain('anywhere=1')
	})

	// The flag alone filters nothing; without a term it must not narrow.
	it('ignores the anywhere flag with no term', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ duplicates: [] }))
		const { anywhere, load } = useDuplicates()

		anywhere.value = true
		await load()

		expect(String(fetchMock.mock.calls[0][0])).not.toContain('anywhere=')
	})
})

describe('the instance-wide view', () => {
	const statusResponse = (status: number, body: unknown = {}): Response =>
		({ ok: status < 400, status, json: () => Promise.resolve(body) } as unknown as Response)

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('learns from the ordinary listing whether the viewer may switch', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(jsonResponse({ duplicates: [], canSudo: true }))
		const { canSudo, load } = useDuplicates()

		await load()

		expect(canSudo.value).toBe(true)
	})

	it('reads the cross-account route once a scope is set', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ duplicates: [] }))
		const { scope, load } = useDuplicates()

		scope.value = { all: true, users: [], groups: [] }
		await load()

		expect(String(fetchMock.mock.calls[0][0])).toContain('/api/v1/sudo/duplicates')
	})

	// Named accounts and groups go to the server as they were chosen: it
	// expands the groups and authorises each, because membership is not the
	// client's to enumerate.
	it('names the chosen accounts and groups rather than expanding them', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ duplicates: [] }))
		const { scope, load } = useDuplicates()

		scope.value = { all: false, users: ['alice'], groups: ['team'] }
		await load()

		const url = String(fetchMock.mock.calls[0][0])
		expect(url).toContain('users%5B%5D=alice')
		expect(url).toContain('groups%5B%5D=team')
	})

	// The confirmation core holds lasts thirty minutes. When it has run out
	// the route answers 403 — and a cross-account listing must say so rather
	// than quietly falling back to one's own files, which would look like
	// the other account simply had no duplicates.
	it('says so when a scoped view is refused rather than showing own files', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(statusResponse(403, { message: 'Password confirmation required' }))
		const { scope, load, error, groups } = useDuplicates()

		scope.value = { all: true, users: [], groups: [] }
		await load()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(groups.value).toEqual([])
		expect(error.value).toContain('not yours to look at')
	})
})
