import { afterEach, describe, expect, it, vi } from 'vitest'
import { useAdminSettings } from './useAdminSettings'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => url,
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

	it('loads status on success', async () => {
		const { pending } = mockAbortableFetch()
		const { status, statusLoading, loadStatus } = useAdminSettings()

		const p = loadStatus()
		pending[0](jsonResponse({ version: '1.9.2', dbVersion: '1', rowCount: 42, pendingStats: {} }))
		await p

		expect(status.value.rowCount).toBe(42)
		expect(statusLoading.value).toBe(false)
	})

	it('discards a stale definitions response when a newer loadDefinitions() supersedes it', async () => {
		const { pending } = mockAbortableFetch()
		const { definitions, loadDefinitions } = useAdminSettings()

		const first = loadDefinitions()
		const second = loadDefinitions()

		pending[1](jsonResponse({ definitions: [{ id: 2, path: '/new' }], supportedAlgos: [], users: [] }))
		await second

		pending[0](jsonResponse({ definitions: [{ id: 1, path: '/stale' }], supportedAlgos: [], users: [] }))
		await first

		expect(definitions.value).toEqual([{ id: 2, path: '/new' }])
	})

	it('splits the global rule (first definition) from the additional rules', async () => {
		const { pending } = mockAbortableFetch()
		const { globalRule, additionalRules, loadDefinitions } = useAdminSettings()

		const p = loadDefinitions()
		pending[0](
			jsonResponse({
				definitions: [{ id: 1, path: '**' }, { id: 2, path: '/a' }, { id: 3, path: '/b' }],
				supportedAlgos: ['sha1'],
				users: ['alice'],
			}),
		)
		await p

		expect(globalRule()).toEqual({ id: 1, path: '**' })
		expect(additionalRules()).toEqual([{ id: 2, path: '/a' }, { id: 3, path: '/b' }])
	})

	it('saveRule posts and reloads definitions on success', async () => {
		const fetchMock = vi
			.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({ success: true }))
			.mockResolvedValueOnce(jsonResponse({ definitions: [{ id: 1, path: '/updated' }] }))

		const { definitions, saveRule } = useAdminSettings()
		const result = await saveRule({ path: '/updated', mode: 'auto', algos: ['sha1'], userScope: 'all', admin_enforced: false })

		expect(result.success).toBe(true)
		expect(definitions.value).toEqual([{ id: 1, path: '/updated' }])
		expect(fetchMock).toHaveBeenCalledTimes(2)
	})

	it('deleteRule does not reload definitions when the request fails', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(jsonResponse({ success: false, error: 'nope' }))

		const { deleteRule } = useAdminSettings()
		const result = await deleteRule(1)

		expect(result).toEqual({ success: false, error: 'nope' })
		expect(globalThis.fetch).toHaveBeenCalledTimes(1)
	})
})
