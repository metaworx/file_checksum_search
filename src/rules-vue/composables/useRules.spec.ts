import { afterEach, describe, expect, it, vi } from 'vitest'
import { useRules } from './useRules'

vi.mock('@nextcloud/router', () => ({
	// Mirrors the real router: {tokens} are substituted from params — and a
	// token nobody substitutes stays visibly in the URL, as it would live.
	generateOcsUrl: (url: string, params?: Record<string, unknown>) =>
		url.replace(/\{(\w+)\}/g, (whole, token) => (params && token in params ? String(params[token]) : whole)),
}))

;(globalThis as unknown as { OC: { requestToken: string } }).OC = { requestToken: 'token' }

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200 })
}

/** [url, method, parsed body] of the nth fetch call. */
function call(n: number): { url: string; method?: string; body?: unknown } {
	const [input, init] = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[n]
	const raw = (init as RequestInit | undefined)?.body
	return {
		url: String(input),
		method: (init as RequestInit | undefined)?.method,
		body: typeof raw === 'string' ? JSON.parse(raw) : undefined,
	}
}

describe('useRules', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('requests the view it was created for', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ rules: [] }))

		await useRules('own').load()
		expect(call(0).url).toContain('scope=own')

		fetchMock.mockClear()
		await useRules('all').load()
		expect(call(0).url).toContain('scope=all')
	})

	it('loads rules and the capability flag', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValue(
			jsonResponse({
				rules: [{ id: 'r1', band: 4, position: 1 }],
				canCreate: true,
				supportedAlgos: ['sha1'],
				modes: ['auto'],
				types: ['include', 'ignore', 'exclude'],
			}),
		)

		const { rules, canCreate, supportedAlgos, types, load } = useRules('own')
		await load()

		expect(rules.value).toEqual([{ id: 'r1', band: 4, position: 1 }])
		expect(canCreate.value).toBe(true)
		expect(supportedAlgos.value).toEqual(['sha1'])
		expect(types.value).toEqual(['include', 'ignore', 'exclude'])
	})

	it('creates with POST and updates with PUT on the rule URL', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ success: true, rules: [] }))
		const { saveRule } = useRules('all')

		await saveRule({ path: '/a', mode: 'auto', algos: ['sha1'], userScope: 'all', admin_enforced: false })
		expect(call(0).method).toBe('POST')
		expect(call(0).url).toBe('/apps/file_checksum_search/api/v1/rules')

		vi.mocked(globalThis.fetch).mockClear()
		await saveRule({ id: 'abc', path: '/a', mode: 'auto', algos: ['sha1'], userScope: 'all', admin_enforced: false })
		expect(call(0).method).toBe('PUT')
		expect(call(0).url).toBe('/apps/file_checksum_search/api/v1/rules/abc')
	})

	it('deletes with DELETE and no body', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ success: true, rules: [] }))

		await useRules('own').deleteRule('abc')

		expect(call(0).method).toBe('DELETE')
		expect(call(0).url).toBe('/apps/file_checksum_search/api/v1/rules/abc')
		expect(call(0).body).toBeUndefined()
	})

	it('addresses one rule by its substituted id, never the literal placeholder', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ success: true, rules: [] }))
		const { toggleRule, applyRule } = useRules('all')

		await toggleRule('r7', false)
		await applyRule('r7')

		// Regression: substituting by hand after generateOcsUrl() missed,
		// because the router percent-encodes the braces — every rule PUT
		// went to the literal "{id}" and 404ed.
		const mutations = fetchMock.mock.calls.filter((call) => !String(call[0]).includes('?scope='))
		expect(mutations.length).toBeGreaterThanOrEqual(2)
		for (const call of mutations) {
			expect(String(call[0])).toContain('/rules/r7')
			expect(String(call[0])).not.toContain('{id}')
			expect(String(call[0])).not.toContain('%7B')
		}
	})

	it('reports the HTTP status when the server answers with a non-JSON error', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValue(
			new Response('<?xml version="1.0"?><ocs>…</ocs>', { status: 404, statusText: 'Not Found' }),
		)
		const { toggleRule } = useRules('all')

		const result = await toggleRule('r1', true)

		expect(result.success).toBe(false)
		expect(result.error).toContain('404')
	})

	it('toggles by updating only `enabled`', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ success: true, rules: [] }))

		await useRules('own').toggleRule('abc', false)

		// There is no toggle endpoint — the server fills the rest from the
		// stored rule, so a minimal payload cannot erase it.
		expect(call(0).method).toBe('PUT')
		expect(call(0).body).toEqual({ enabled: false })
	})

	it('reorders one segment partition', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ success: true, rules: [] }))
		const { reorderSegment } = useRules('all')

		await reorderSegment('home:*', false, ['b', 'a'])

		const call = fetchMock.mock.calls[0]!
		// The URL assertion is the regression guard: this request once went
		// to `undefined` because it read a route key that did not exist.
		expect(String(call[0])).toContain('/api/v1/rules/order')
		expect((call[1] as RequestInit).method).toBe('PUT')
		expect(JSON.parse((call[1] as RequestInit).body as string)).toEqual({
			selector: 'home:*',
			defaults: false,
			orderedIds: ['b', 'a'],
		})
	})

	it('queues a re-apply on the rule apply endpoint without reloading', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ success: true, queued: true }))
		const { applyRule } = useRules('all')

		const result = await applyRule('r1')

		expect(result.success).toBe(true)
		// Applying changes no rule, so exactly one request: the
		// apply POST itself, no follow-up list reload.
		expect(fetchMock.mock.calls).toHaveLength(1)
		const call = fetchMock.mock.calls[0]!
		expect(String(call[0])).toContain('/api/v1/rules/r1/apply')
		expect((call[1] as RequestInit).method).toBe('POST')
	})

	it('reloads after a successful mutation but not after a failed one', async () => {
		const fetchMock = vi
			.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({ success: true }))
			.mockResolvedValueOnce(jsonResponse({ rules: [{ id: 'r1' }] }))

		const { rules, deleteRule } = useRules('own')
		await deleteRule('r1')

		expect(fetchMock).toHaveBeenCalledTimes(2)
		expect(rules.value).toEqual([{ id: 'r1' }])

		// A failure leaves the view intact rather than blanking it.
		fetchMock.mockClear()
		fetchMock.mockResolvedValueOnce(jsonResponse({ success: false, error: 'nope' }))
		const result = await deleteRule('r1')

		expect(result).toEqual({ success: false, error: 'nope' })
		expect(fetchMock).toHaveBeenCalledTimes(1)
	})

	it('discards a stale response when a newer load() supersedes it', async () => {
		const pending: Array<(response: Response) => void> = []
		vi.spyOn(globalThis, 'fetch').mockImplementation((_url, options) => {
			let resolveFn!: (value: Response) => void
			const promise = new Promise<Response>((resolve, reject) => {
				resolveFn = resolve
				;(options as RequestInit | undefined)?.signal
					?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))
			})
			pending.push(resolveFn)
			return promise
		})

		const { rules, load } = useRules('own')
		const first = load()
		const second = load()

		pending[1](jsonResponse({ rules: [{ id: 'new' }] }))
		await second
		pending[0](jsonResponse({ rules: [{ id: 'stale' }] }))
		await first

		expect(rules.value).toEqual([{ id: 'new' }])
	})
})
