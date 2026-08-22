import { afterEach, describe, expect, it, vi } from 'vitest'
import { usePersonalSettings } from './usePersonalSettings'

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

describe('usePersonalSettings', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('discards a stale response when a newer loadRules() supersedes it', async () => {
		const { pending } = mockAbortableFetch()
		const { rules, error, loadRules } = usePersonalSettings()

		const first = loadRules()
		const second = loadRules()

		pending[1](jsonResponse({ rules: [{ id: 2, path: '/new' }], canEdit: true, supportedAlgos: [] }))
		await second

		pending[0](jsonResponse({ rules: [{ id: 1, path: '/stale' }], canEdit: true, supportedAlgos: [] }))
		await first

		expect(rules.value).toEqual([{ id: 2, path: '/new' }])
		expect(error.value).toBeNull()
	})

	it('loads rules and the canEdit flag on success', async () => {
		const { pending } = mockAbortableFetch()
		const { rules, canEditAny, supportedAlgos, loadRules } = usePersonalSettings()

		const p = loadRules()
		pending[0](jsonResponse({ rules: [{ id: 1, path: '/docs' }], canEdit: false, supportedAlgos: ['sha1', 'md5'] }))
		await p

		expect(rules.value).toEqual([{ id: 1, path: '/docs' }])
		expect(canEditAny.value).toBe(false)
		expect(supportedAlgos.value).toEqual(['sha1', 'md5'])
	})

	it('sets an error message when the request fails', async () => {
		vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('network'))

		const { rules, error, loadRules } = usePersonalSettings()
		await loadRules()

		expect(error.value).toBe('Failed to load rules.')
		expect(rules.value).toEqual([])
	})

	it('saveRule posts and reloads rules on success', async () => {
		const fetchMock = vi
			.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({ success: true }))
			.mockResolvedValueOnce(jsonResponse({ rules: [{ id: 1, path: '/updated' }] }))

		const { rules, saveRule } = usePersonalSettings()
		const result = await saveRule({ path: '/updated', mode: 'auto', algos: ['sha1'], userScope: 'all', admin_enforced: false })

		expect(result.success).toBe(true)
		expect(rules.value).toEqual([{ id: 1, path: '/updated' }])
		expect(fetchMock).toHaveBeenCalledTimes(2)
	})

	it('toggleRule does not reload rules when the request fails', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(jsonResponse({ success: false, error: 'nope' }))

		const { toggleRule } = usePersonalSettings()
		const result = await toggleRule(1, false)

		expect(result).toEqual({ success: false, error: 'nope' })
		expect(globalThis.fetch).toHaveBeenCalledTimes(1)
	})
})
