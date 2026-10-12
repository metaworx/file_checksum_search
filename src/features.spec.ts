import { loadState } from '@nextcloud/initial-state'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchFeatures, mimeTypeSwitchedOff, resetFeatureCache } from './features'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => url,
}))

// The page's initial state: none unless a test renders one.
vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn(),
}))

describe('features', () => {
	beforeEach(() => {
		vi.mocked(loadState).mockImplementation(() => {
			throw new Error('Could not find initial state features of file_checksum_search')
		})
	})

	afterEach(() => {
		resetFeatureCache()
		vi.restoreAllMocks()
	})

	it('takes the features the page was rendered with, and asks the server nothing', async () => {
		vi.mocked(loadState).mockReturnValue({ version: '1.0.0', features: { mimetype: true } })
		const fetchMock = vi.spyOn(globalThis, 'fetch')

		await expect(fetchFeatures()).resolves.toEqual({ mimetype: true })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('reads what the server offers once per page where the page carries none', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
			new Response(JSON.stringify({ version: '1.0.0', features: { mimetype: true } }), { status: 200 }),
		)

		const first = await fetchFeatures()
		const second = await fetchFeatures()

		expect(first).toEqual({ mimetype: true })
		expect(second).toBe(first)
		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(String(fetchMock.mock.calls[0][0])).toContain('/api/v1/features')
	})

	it('offers nothing a server older than the features does not name', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValue(
			new Response(JSON.stringify({ version: '0.24.0' }), { status: 200 }),
		)

		await expect(fetchFeatures()).resolves.toEqual({ mimetype: false })
	})

	it('offers nothing on a failed read, and asks again next time', async () => {
		vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(new Response('', { status: 500 }))
			.mockResolvedValueOnce(new Response(JSON.stringify({ features: { mimetype: true } }), { status: 200 }))

		await expect(fetchFeatures()).resolves.toEqual({ mimetype: false })
		await expect(fetchFeatures()).resolves.toEqual({ mimetype: true })
	})

	describe('mimeTypeSwitchedOff', () => {
		const refused = () => new Response(JSON.stringify({ success: false }), { status: 400 })
		const status = (mimetype: boolean) => new Response(JSON.stringify({ features: { mimetype } }), { status: 200 })

		it('reads the features again on a refusal to a request that asked, and says detection went off', async () => {
			const fetchMock = vi.spyOn(globalThis, 'fetch')
				.mockResolvedValueOnce(status(true))
				.mockResolvedValueOnce(status(false))
			await fetchFeatures()

			await expect(mimeTypeSwitchedOff(refused(), true)).resolves.toBe(true)
			await expect(fetchFeatures()).resolves.toEqual({ mimetype: false })
			expect(fetchMock).toHaveBeenCalledTimes(2)
		})

		it('asks the server, not the page, which may say what was true when it was rendered', async () => {
			vi.mocked(loadState).mockReturnValue({ features: { mimetype: true } })
			const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(status(false))
			await fetchFeatures()

			await expect(mimeTypeSwitchedOff(refused(), true)).resolves.toBe(true)
			expect(fetchMock).toHaveBeenCalledTimes(1)
		})

		it('says no where detection is still on: the refusal was for something else', async () => {
			vi.spyOn(globalThis, 'fetch').mockImplementation(() => Promise.resolve(status(true)))

			await expect(mimeTypeSwitchedOff(refused(), true)).resolves.toBe(false)
		})

		it('reads nothing for a request that did not ask, or was not refused', async () => {
			const fetchMock = vi.spyOn(globalThis, 'fetch')

			await expect(mimeTypeSwitchedOff(refused(), false)).resolves.toBe(false)
			await expect(mimeTypeSwitchedOff(new Response('{}', { status: 200 }), true)).resolves.toBe(false)
			expect(fetchMock).not.toHaveBeenCalled()
		})
	})
})
