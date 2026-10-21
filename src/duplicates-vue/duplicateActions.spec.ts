/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { deleteRows, restoreRow } from './duplicateActions'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...await importOriginal<typeof import('@nextcloud/router')>(),
	generateOcsUrl: (url: string) => url,
}))

const GROUP = { algo: 'sha1', hash_value: 'aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d' }

function jsonResponse(body: unknown, status = 200): Response {
	return new Response(JSON.stringify(body), { status })
}

/** The body the last request sent. */
function sent(fetchMock: ReturnType<typeof vi.fn>): Record<string, unknown> {
	const options = fetchMock.mock.calls.at(-1)?.[1] as RequestInit
	return JSON.parse(String(options.body)) as Record<string, unknown>
}

describe('duplicateActions', () => {
	let fetchMock: ReturnType<typeof vi.fn>

	beforeEach(() => {
		(globalThis as unknown as { OC: unknown }).OC = { requestToken: 'token' }
		fetchMock = vi.fn()
		vi.stubGlobal('fetch', fetchMock)
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	// Each row is held to the group's hash on the server: the page sends it.
	it('deletes rows of a group, held to its hash, with the request token', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ results: [{ fileid: 7, kind: 'file', status: 'ready', action: 'trash' }] }))

		const results = await deleteRows(GROUP, [7, 9], { forGood: false, dryRun: true })

		expect(results).toEqual([{ fileid: 7, kind: 'file', status: 'ready', action: 'trash' }])
		expect(fetchMock.mock.calls[0][0]).toMatch(/\/api\/v1\/duplicates\/delete$/)
		expect((fetchMock.mock.calls[0][1] as RequestInit).headers).toMatchObject({ requesttoken: 'token' })
		expect(sent(fetchMock)).toEqual({ fileIds: [7, 9], algo: 'sha1', hash: GROUP.hash_value, forGood: false, dryRun: true })
	})

	it('names the file that takes the versions only where there is one', async () => {
		fetchMock.mockImplementation(() => Promise.resolve(jsonResponse({ results: [] })))

		await deleteRows(GROUP, [7], { forGood: true, dryRun: false, mergeInto: 5 })
		expect(sent(fetchMock)).toMatchObject({ forGood: true, dryRun: false, mergeInto: 5 })

		await deleteRows(GROUP, [7], { forGood: true, dryRun: false, mergeInto: null })
		expect(sent(fetchMock)).not.toHaveProperty('mergeInto')
	})

	it('restores one row the way asked', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ result: { fileid: 7, kind: 'trash', status: 'restored', restored: { fileid: 7, path: '/a.txt' } } }))

		const result = await restoreRow(GROUP, 7, 'original')

		expect(result.restored).toEqual({ fileid: 7, path: '/a.txt' })
		expect(fetchMock.mock.calls[0][0]).toMatch(/\/api\/v1\/duplicates\/restore$/)
		expect(sent(fetchMock)).toEqual({ fileId: 7, algo: 'sha1', hash: GROUP.hash_value, as: 'original' })
	})

	// A refusal of the request itself, a 400 or a 429, is no answer per row.
	it('throws on a request the server refused', async () => {
		fetchMock.mockImplementation(() => Promise.resolve(jsonResponse({ success: false }, 429)))

		await expect(deleteRows(GROUP, [7], { forGood: false, dryRun: true })).rejects.toThrow('HTTP 429')
		await expect(restoreRow(GROUP, 7, 'file')).rejects.toThrow('HTTP 429')
	})
})
