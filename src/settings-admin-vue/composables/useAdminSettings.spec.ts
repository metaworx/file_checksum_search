import { afterEach, describe, expect, it, vi } from 'vitest'
import { useAdminSettings } from './useAdminSettings'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string, params?: Record<string, unknown>) =>
		url.replace(/\{(\w+)\}/g, (whole, token) => (params && token in params ? String(params[token]) : whole)),
}))

;(globalThis as unknown as { OC: { requestToken: string } }).OC = { requestToken: 'token' }

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200 })
}

/**
 * A fetch mock whose returned promise rejects with an AbortError as soon
 * as the request's signal is aborted — mirroring real fetch() behavior,
 * unlike a plain resolved/rejected mock.
 */
function mockAbortableFetch(): { pending: Array<(response: Response) => void> } {
	const pending: Array<(response: Response) => void> = []

	vi.spyOn(globalThis, 'fetch').mockImplementation((_url, options) => {
		let resolveFn!: (value: Response) => void
		const promise = new Promise<Response>((resolve, reject) => {
			resolveFn = resolve
			const signal = (options as RequestInit | undefined)?.signal
			signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))
		})
		pending.push(resolveFn)
		return promise
	})

	return { pending }
}

/** Let the promises already settled run on. */
const tick = () => new Promise((resolve) => setTimeout(resolve, 0))

/** The URLs fetched so far, in order, below the app's base. */
function urls(fetchMock: { mock: { calls: unknown[][] } }): string[] {
	return fetchMock.mock.calls.map(([url]) => String(url).replace('/apps/file_checksum_search', ''))
}

describe('useAdminSettings', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	// The information first, the counts once it has answered: fired together,
	// a count warming a cold cache would delay the version.
	it('asks for the counts only once the information has answered', async () => {
		const { pending } = mockAbortableFetch()
		const fetchMock = vi.mocked(globalThis.fetch)
		const { status, statusLoading, countsLoading, lastUpdated, loadStatus } = useAdminSettings()

		const p = loadStatus()
		await tick()
		expect(urls(fetchMock)).toEqual(['/settings/status'])

		pending[0](jsonResponse({ version: '1.9.2', dbVersion: '1' }))
		await tick()

		expect(status.value.version).toBe('1.9.2')
		expect(statusLoading.value).toBe(false)
		expect(countsLoading.value).toBe(true)
		expect(urls(fetchMock)).toEqual(['/settings/status', '/settings/status/counts'])

		pending[1](jsonResponse({ rowCount: 42, rowCountAt: 1759300000, pendingStats: {} }))
		await p

		expect(status.value).toEqual({ version: '1.9.2', dbVersion: '1', rowCount: 42, rowCountAt: 1759300000, pendingStats: {} })
		expect(countsLoading.value).toBe(false)
		expect(lastUpdated.value).not.toBeNull()
	})

	it('recounts on Refresh, whatever the kept count\'s age', async () => {
		const { pending } = mockAbortableFetch()
		const fetchMock = vi.mocked(globalThis.fetch)
		const { loadStatus } = useAdminSettings()

		const p = loadStatus(true)
		await tick()
		pending[0](jsonResponse({ version: '1.9.2' }))
		await tick()
		pending[1](jsonResponse({ rowCount: 42 }))
		await p

		expect(urls(fetchMock)[1]).toBe('/settings/status/counts?recount=1')
	})

	it('treats a non-OK status response as an error, and still loads the counts', async () => {
		const { pending } = mockAbortableFetch()
		const { status, statusError, statusLoading, countsError, loadStatus } = useAdminSettings()

		const p = loadStatus()
		await tick()
		pending[0](new Response(JSON.stringify({ message: 'Password confirmation required' }), { status: 403 }))
		await tick()
		pending[1](jsonResponse({ rowCount: 3 }))
		await p

		expect(statusError.value).toBe('Could not load the status (HTTP 403).')
		expect(statusLoading.value).toBe(false)
		expect(countsError.value).toBeNull()
		expect(status.value).toEqual({ rowCount: 3 })
	})

	it('says the counts failed and leaves the information standing', async () => {
		const { pending } = mockAbortableFetch()
		const { status, statusError, countsError, countsLoading, lastUpdated, loadStatus } = useAdminSettings()

		const p = loadStatus()
		await tick()
		pending[0](jsonResponse({ version: '1.9.2' }))
		await tick()
		pending[1](new Response('{}', { status: 500 }))
		await p

		expect(countsError.value).toBe('Could not load the counts (HTTP 500).')
		expect(countsLoading.value).toBe(false)
		expect(statusError.value).toBeNull()
		expect(status.value).toEqual({ version: '1.9.2' })
		expect(lastUpdated.value).toBeNull()
	})

	it('abandons a running sequence when a newer loadStatus() starts', async () => {
		const { pending } = mockAbortableFetch()
		const fetchMock = vi.mocked(globalThis.fetch)
		const { status, statusError, countsLoading, loadStatus } = useAdminSettings()

		const first = loadStatus()
		const second = loadStatus(true)
		await tick()

		pending[1](jsonResponse({ version: '2.0.0' }))
		await tick()
		pending[2](jsonResponse({ rowCount: 5 }))
		await Promise.all([first, second])

		// The first never got as far as its counts.
		expect(urls(fetchMock)).toEqual(['/settings/status', '/settings/status', '/settings/status/counts?recount=1'])
		expect(status.value).toEqual({ version: '2.0.0', rowCount: 5 })
		expect(statusError.value).toBeNull()
		expect(countsLoading.value).toBe(false)
	})

	it('asks for the whole-instance view of the rules', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ rules: [] }))

		await useAdminSettings().loadRules()

		expect(String(fetchMock.mock.calls[0][0])).toContain('scope=all')
	})

})
