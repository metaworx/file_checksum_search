import { afterEach, describe, expect, it, vi } from 'vitest'
import { useSidebarHashes } from './useSidebarHashes'
import type { FileNode } from '../types'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string, params?: Record<string, unknown>) => {
		let out = url
		if (params) {
			for (const [key, value] of Object.entries(params)) {
				out = out.replace(`{${key}}`, String(value))
			}
		}
		return out
	},
}))

// What the server offers, as /api/v1/status says: no MIME types unless a
// test says otherwise.
const features = { mimetype: false }
vi.mock('../../features', () => ({
	fetchFeatures: () => Promise.resolve({ ...features }),
	// A 400 to a request that asked reads the features again: these, by then.
	mimeTypeSwitchedOff: (response: Response, asked: boolean) => Promise.resolve(asked && response.status === 400 && !features.mimetype),
}))

;(globalThis as unknown as { OC: { requestToken: string } }).OC = { requestToken: 'token' }

function fileNode(fileid: number): FileNode {
	return { fileid, type: 'file' }
}

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200 })
}

describe('useSidebarHashes', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		features.mimetype = false
	})

	// Asked for only while the server detects types: asked otherwise, the
	// route refuses.
	it('asks for the MIME type while the server detects types, and keeps it', async () => {
		features.mimetype = true
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
			jsonResponse({ hashes: { sha1: { algo: 'sha1', hash: 'abc' } }, mimetype: 'application/pdf' }),
		)

		const { mimeType, mimeTypeShown, loadHashes } = useSidebarHashes(() => fileNode(123))
		await loadHashes()

		expect(fetchMock).toHaveBeenCalledWith(
			'/apps/file_checksum_search/api/v1/file/123/hashes?mimetype=1',
			expect.anything(),
		)
		expect(mimeTypeShown.value).toBe(true)
		expect(mimeType.value).toBe('application/pdf')
	})

	// The features are read once per page; switched off since, the route
	// refuses the request that asks, and the tab asks again without.
	it('asks again without the MIME type once detection was switched off', async () => {
		features.mimetype = true
		const fetchMock = vi.spyOn(globalThis, 'fetch')
			.mockImplementationOnce(() => {
				features.mimetype = false
				return Promise.resolve(new Response(JSON.stringify({ success: false }), { status: 400 }))
			})
			.mockResolvedValueOnce(jsonResponse({ hashes: { sha1: { algo: 'sha1', hash: 'abc' } } }))

		const { error, hashes, mimeTypeShown, loadHashes } = useSidebarHashes(() => fileNode(123))
		await loadHashes()

		expect(fetchMock).toHaveBeenCalledTimes(2)
		expect(fetchMock.mock.calls[1][0]).toBe('/apps/file_checksum_search/api/v1/file/123/hashes')
		expect(error.value).toBe('')
		expect(mimeTypeShown.value).toBe(false)
		expect(hashes.value).toEqual({ sha1: { algo: 'sha1', hash: 'abc' } })
	})

	it('shows no MIME type while the server detects none', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValue(
			jsonResponse({ hashes: { sha1: { algo: 'sha1', hash: 'abc' } } }),
		)

		const { mimeType, mimeTypeShown, loadHashes } = useSidebarHashes(() => fileNode(123))
		await loadHashes()

		expect(mimeTypeShown.value).toBe(false)
		expect(mimeType.value).toBeNull()
	})

	it('loads hashes for the selected file', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
			jsonResponse({ hashes: { sha1: { algo: 'sha1', hash: 'abc' } } }),
		)

		const { loading, hashes, loadHashes } = useSidebarHashes(() => fileNode(123))
		await loadHashes()

		expect(loading.value).toBe(false)
		expect(hashes.value).toEqual({ sha1: { algo: 'sha1', hash: 'abc' } })
		expect(fetchMock).toHaveBeenCalledWith(
			'/apps/file_checksum_search/api/v1/file/123/hashes',
			expect.anything(),
		)
	})

	// The hashes are keyed by algorithm; a list, which is how PHP encodes an
	// empty map, is read as none rather than as entries keyed 0, 1, 2.
	it('reads a list as no hashes', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
			jsonResponse({ hashes: [{ algo: 'sha1', hash: 'abc' }] }),
		)

		const { hashes, loadHashes } = useSidebarHashes(() => fileNode(123))
		await loadHashes()

		expect(hashes.value).toEqual({})
	})

	// The permission rides on the hashes response; only an explicit "no"
	// hides the buttons, so an answer that does not mention it changes nothing.
	it('takes from the server whether recalculation may be offered', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(jsonResponse({ hashes: {}, canRecalc: false }))
		const { canRecalc, loadHashes } = useSidebarHashes(() => fileNode(123))

		expect(canRecalc.value).toBe(true)
		await loadHashes()

		expect(canRecalc.value).toBe(false)
	})

	// The other flag on the same response. The opposite default: nothing is
	// offered across accounts until the server says the viewer may.
	it('takes from the server whether the way across accounts may be offered', async () => {
		vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({ hashes: {}, canSudo: true }))
			.mockResolvedValueOnce(jsonResponse({ hashes: {} }))
		const { canSudo, loadHashes } = useSidebarHashes(() => fileNode(123))

		expect(canSudo.value).toBe(false)
		await loadHashes()
		expect(canSudo.value).toBe(true)

		// An answer that does not say is a no.
		await loadHashes()
		expect(canSudo.value).toBe(false)
	})

	it('recalculates an algorithm and reloads the hashes', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({ success: true }))
			.mockResolvedValueOnce(jsonResponse({ hashes: { md5: { algo: 'md5', hash: 'def' } } }))

		const { hashes, recalc } = useSidebarHashes(() => fileNode(123))
		await recalc('md5')

		expect(hashes.value).toEqual({ md5: { algo: 'md5', hash: 'def' } })
		expect(fetchMock).toHaveBeenNthCalledWith(
			1,
			'/apps/file_checksum_search/api/v1/file/123/recalc?algo=md5',
			expect.objectContaining({ method: 'POST' }),
		)
	})

	it('sets recalcError when recalculation fails', async () => {
		vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('network'))

		const { recalcError, recalcErrorMessage, recalc } = useSidebarHashes(() => fileNode(123))
		await recalc('sha256')

		expect(recalcError.value).toBe('sha256')
		expect(recalcErrorMessage.value).toBe('Could not recalculate the checksum.')
	})

	it('keeps the server\'s reason for a refused recalculation', async () => {
		// An exclude rule refusing the read is policy, not breakage. Reporting
		// it as a bare "Error" reads as a malfunction worth retrying.
		vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(jsonResponse({
			success: false,
			error: 'Hashing is excluded for this path by a rule.',
			excluded: true,
			ruleId: 'abc123',
			ruleOwner: 'admin',
		}))

		const { recalcError, recalcErrorMessage, recalc } = useSidebarHashes(() => fileNode(123))
		await recalc('sha1')

		expect(recalcError.value).toBe('sha1')
		expect(recalcErrorMessage.value).toBe('Hashing is excluded for this path by an administrator\'s rule.')
	})

	// The defect: every refusal said "an administrator rule", of the
	// reader's own rule as well, and sent them to the wrong page for it.
	it('says whose rule refused: the reader\'s own, or an administrator\'s', async () => {
		document.head.dataset.user = 'alice'
		vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({ success: false, excluded: true, ruleId: 'r1', ruleOwner: 'alice' }))
			.mockResolvedValueOnce(jsonResponse({ success: false, excluded: true, ruleId: 'r2', ruleOwner: 'bob' }))
			.mockResolvedValueOnce(jsonResponse({ success: false, excluded: true, ruleId: 'r3' }))

		const { recalcErrorMessage, recalc } = useSidebarHashes(() => fileNode(123))

		await recalc('sha1')
		expect(recalcErrorMessage.value).toBe('Hashing is excluded for this path by your own rule.')

		await recalc('sha1')
		expect(recalcErrorMessage.value).toBe('Hashing is excluded for this path by bob\'s rule.')

		// An older server that names no owner.
		await recalc('sha1')
		expect(recalcErrorMessage.value).toBe('Hashing is excluded for this path by a rule.')
		delete document.head.dataset.user
	})

	it('clears a previous reason when a recalculation is retried', async () => {
		vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({ success: false, error: 'Nope.' }))
			.mockResolvedValueOnce(jsonResponse({ success: true }))
			.mockResolvedValueOnce(jsonResponse({ hashes: {} }))

		const { recalcErrorMessage, recalc } = useSidebarHashes(() => fileNode(123))
		await recalc('sha1')
		expect(recalcErrorMessage.value).toBe('Nope.')

		await recalc('sha1')
		expect(recalcErrorMessage.value).toBe('')
	})

	it('finds duplicate groups', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
			jsonResponse({ duplicates: [{ algo: 'sha1', hash_value: 'abc', files: [] }] }),
		)

		const { duplicates, toggleDuplicates } = useSidebarHashes(() => fileNode(123))
		await toggleDuplicates()

		expect(duplicates.value).toEqual([{ algo: 'sha1', hash_value: 'abc', files: [] }])
	})

	// The quick buttons are composed from three things the hashes response
	// now carries; a response from an older server carries none, and the
	// composable must not invent them.
	it('carries the composition inputs from the response, or nothing', async () => {
		vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({ hashes: {}, algos: ['sha256', 'sha1'], preferred: 'md5', default: 'sha1' }))
			.mockResolvedValueOnce(jsonResponse({ hashes: {} }))

		const { ruleAlgos, preferredAlgo, defaultAlgo, loadHashes } = useSidebarHashes(() => fileNode(123))

		await loadHashes()
		expect(ruleAlgos.value).toEqual(['sha256', 'sha1'])
		expect(preferredAlgo.value).toBe('md5')
		expect(defaultAlgo.value).toBe('sha1')

		await loadHashes()
		expect(ruleAlgos.value).toEqual([])
		expect(preferredAlgo.value).toBe('')
		expect(defaultAlgo.value).toBe('')
	})
})
