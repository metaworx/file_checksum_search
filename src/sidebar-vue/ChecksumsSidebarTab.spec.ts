/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ChecksumsSidebarTab from './ChecksumsSidebarTab.vue'

vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...await importOriginal<typeof import('@nextcloud/l10n')>(),
	translate: (app: string, text: string) => text,
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url: string) => `/index.php${url}`,
	generateOcsUrl: (url: string, params?: Record<string, unknown>) => {
		let out = url
		for (const [key, value] of Object.entries(params ?? {})) {
			out = out.replace(`{${key}}`, String(value))
		}
		return out
	},
}))

// The picker's list is another request; not this spec's business.
vi.mock('../algorithms', async (importOriginal) => ({
	...await importOriginal<typeof import('../algorithms')>(),
	fetchAlgorithms: () => Promise.resolve({ algorithms: ['sha1', 'sha256'], default: 'sha1' }),
}))

;(globalThis as unknown as { OC: { requestToken: string } }).OC = { requestToken: 'token' }

const SHA1 = { algo: 'sha1', hash: '0beec7b5ea3f0fdbc95d0dd47f3c5bc275da8a33' }
const SHA256 = { algo: 'sha256', hash: '2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae' }

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

async function mountWith(hashesResponse: unknown) {
	vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse(hashesResponse))
	const wrapper = mount(ChecksumsSidebarTab, {
		props: { node: { fileid: 42, type: 'file' }, active: true },
	})
	await flushPromises()
	return wrapper
}

describe('ChecksumsSidebarTab, the way across accounts', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('is not offered to a viewer who may not look across accounts', async () => {
		const wrapper = await mountWith({ hashes: [SHA1], canSudo: false })
		expect(wrapper.find('[data-testid="fcias-dup-across"]').exists()).toBe(false)
	})

	it('is not offered for a file with nothing to look for', async () => {
		const wrapper = await mountWith({ hashes: [], canSudo: true })
		expect(wrapper.find('[data-testid="fcias-dup-across"]').exists()).toBe(false)
	})

	it('opens the Others tab on the preferred algorithm\'s hash, over the whole reach', async () => {
		const wrapper = await mountWith({ hashes: [SHA1, SHA256], preferred: 'sha256', default: 'sha1', canSudo: true })
		const link = wrapper.find('[data-testid="fcias-dup-across"]')
		expect(link.attributes('href')).toBe(
			`/index.php/apps/file_checksum_search/duplicates#others?hash=${SHA256.hash}&algo=sha256&all=1`,
		)
		expect(link.attributes('target')).toBe('_blank')
	})

	it('falls back to the first hash the file has', async () => {
		const wrapper = await mountWith({ hashes: [SHA1, SHA256], preferred: 'md5', default: 'md5', canSudo: true })
		expect(wrapper.find('[data-testid="fcias-dup-across"]').attributes('href')).toContain(`hash=${SHA1.hash}&algo=sha1`)
	})
})
