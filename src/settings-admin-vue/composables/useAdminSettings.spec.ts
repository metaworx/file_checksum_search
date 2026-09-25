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

describe('useAdminSettings', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('discards a stale status response when a newer loadStatus() supersedes it', async () => {
		const { pending } = mockAbortableFetch()
		const { status, statusError, loadStatus } = useAdminSettings()

		const first = loadStatus()
		const second = loadStatus()

		pending[1](jsonResponse({ version: '2.0.0', rowCount: 5 }))
		await second

		pending[0](jsonResponse({ version: '1.0.0', rowCount: 1 }))
		await first

		expect(status.value).toEqual({ version: '2.0.0', rowCount: 5 })
		expect(statusError.value).toBeNull()
	})

	it('treats a non-OK status response as an error, not as a status', async () => {
		const { pending } = mockAbortableFetch()
		const { status, statusError, statusLoading, loadStatus } = useAdminSettings()

		const p = loadStatus()
		pending[0](new Response(JSON.stringify({ message: 'Password confirmation required' }), { status: 403 }))
		await p

		expect(statusError.value).toBe('Failed to load status (HTTP 403).')
		expect(status.value).toEqual({})
		expect(statusLoading.value).toBe(false)
	})

	it('loads status on success', async () => {
		const { pending } = mockAbortableFetch()
		const { status, statusLoading, loadStatus } = useAdminSettings()

		const p = loadStatus()
		pending[0](jsonResponse({ version: '1.9.2', dbVersion: '1', rowCount: 42, pendingStats: {} }))
		await p

		expect(status.value.rowCount).toBe(42)
		expect(statusLoading.value).toBe(false)
	})

	it('asks for the whole-instance view of the rules', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ rules: [] }))

		await useAdminSettings().loadRules()

		expect(String(fetchMock.mock.calls[0][0])).toContain('scope=all')
	})

})
