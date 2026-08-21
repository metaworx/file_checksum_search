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
	})

	it('loads hashes for the selected file', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
			jsonResponse({ hashes: [{ algo: 'sha1', hash: 'abc' }] }),
		)

		const { loading, hashes, loadHashes } = useSidebarHashes(() => fileNode(123))
		await loadHashes()

		expect(loading.value).toBe(false)
		expect(hashes.value).toEqual([{ algo: 'sha1', hash: 'abc' }])
		expect(fetchMock).toHaveBeenCalledWith(
			'/apps/file_checksum_search/api/v1/file/123/hashes',
			expect.anything(),
		)
	})

	it('recalculates an algorithm and reloads the hashes', async () => {
		const fetchMock = vi.spyOn(globalThis, 'fetch')
			.mockResolvedValueOnce(jsonResponse({ success: true }))
			.mockResolvedValueOnce(jsonResponse({ hashes: [{ algo: 'md5', hash: 'def' }] }))

		const { hashes, recalc } = useSidebarHashes(() => fileNode(123))
		await recalc('md5')

		expect(hashes.value).toEqual([{ algo: 'md5', hash: 'def' }])
		expect(fetchMock).toHaveBeenNthCalledWith(
			1,
			'/apps/file_checksum_search/api/v1/file/123/recalc?algo=md5',
			expect.objectContaining({ method: 'POST' }),
		)
	})

	it('sets recalcError when recalculation fails', async () => {
		vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('network'))

		const { recalcError, recalc } = useSidebarHashes(() => fileNode(123))
		await recalc('sha256')

		expect(recalcError.value).toBe('sha256')
	})

	it('finds duplicate groups', async () => {
		vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
			jsonResponse({ duplicates: [{ algo: 'sha1', hash_value: 'abc', files: [] }] }),
		)

		const { duplicates, toggleDuplicates } = useSidebarHashes(() => fileNode(123))
		await toggleDuplicates()

		expect(duplicates.value).toEqual([{ algo: 'sha1', hash_value: 'abc', files: [] }])
	})
})
