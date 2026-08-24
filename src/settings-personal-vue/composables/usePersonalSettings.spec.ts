import { afterEach, describe, expect, it, vi } from 'vitest'
import { usePersonalSettings } from './usePersonalSettings'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => url,
}))

;(globalThis as unknown as { OC: { requestToken: string } }).OC = { requestToken: 'token' }

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200 })
}

describe('usePersonalSettings', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('asks for the caller\'s own view, never the whole instance', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ rules: [] }))

		await usePersonalSettings().loadRules()

		// This is what keeps the personal page personal even for an
		// administrator: the capability exists, but this page never asks for
		// it, so the server returns their own rules and marks the rest
		// read-only.
		const url = String(fetchMock.mock.calls[0][0])
		expect(url).toContain('scope=own')
		expect(url).not.toContain('scope=all')
	})

	it('loads rules and the create-capability flag', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValue(
			jsonResponse({ rules: [{ id: 1, path: '/docs' }], canCreate: false, supportedAlgos: ['sha1', 'md5'] }),
		)

		const { rules, canEditAny, supportedAlgos, loadRules } = usePersonalSettings()
		await loadRules()

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
})
