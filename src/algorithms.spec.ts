import { afterEach, describe, expect, it, vi } from 'vitest'
import { fetchAlgorithms, resetAlgorithmCache, toAlgoOptions } from './algorithms'

describe('algorithms', () => {
	afterEach(() => {
		resetAlgorithmCache()
		vi.restoreAllMocks()
	})

	it('maps algorithm ids to uppercase label options', () => {
		expect(toAlgoOptions(['sha1', 'md5', 'sha3-256'])).toEqual([
			{ id: 'sha1', label: 'SHA1' },
			{ id: 'md5', label: 'MD5' },
			{ id: 'sha3-256', label: 'SHA3-256' },
		])
	})

	// The list used to live here as a hand-kept mirror of a PHP constant,
	// guarded by a canary on each side. Both are gone: the server is the
	// only copy, and every picker reads it.
	it('reads the catalogue from the server once per page', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
			new Response(JSON.stringify({ algorithms: ['sha1', 'sha384'], default: 'sha1' }), { status: 200 }),
		)

		const first = await fetchAlgorithms()
		const second = await fetchAlgorithms()

		expect(first).toEqual({ algorithms: ['sha1', 'sha384'], default: 'sha1' })
		expect(second).toBe(first)
		expect(fetchMock).toHaveBeenCalledTimes(1)
	})

	it('does not cache a failed fetch', async () => {
		vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(new Response('', { status: 500 }))
			.mockResolvedValueOnce(new Response(JSON.stringify({ algorithms: ['md5'], default: 'md5' }), { status: 200 }))

		await expect(fetchAlgorithms()).rejects.toThrow('HTTP 500')
		await expect(fetchAlgorithms()).resolves.toEqual({ algorithms: ['md5'], default: 'md5' })
	})
})
