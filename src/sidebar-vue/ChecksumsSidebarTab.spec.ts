/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ChecksumsSidebarTab from './ChecksumsSidebarTab.vue'

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

async function mountWith(hashesResponse: unknown, size?: number, duplicatesResponse: unknown = { duplicates: [] }) {
	// By route: the hashes when the tab opens, the duplicates on the button.
	vi.spyOn(globalThis, 'fetch').mockImplementation((input) => Promise.resolve(jsonResponse(
		String(input).endsWith('/duplicates') ? duplicatesResponse : hashesResponse,
	)))
	const wrapper = mount(ChecksumsSidebarTab, {
		props: { node: { fileid: 42, type: 'file', size }, active: true },
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

	it('asks for the empty files when the file is empty, and only then', async () => {
		const empty = await mountWith({ hashes: [SHA1], canSudo: true }, 0)
		expect(empty.find('[data-testid="fcias-dup-across"]').attributes('href')).toContain('includeEmpty=1')

		vi.restoreAllMocks()
		const full = await mountWith({ hashes: [SHA1], canSudo: true }, 12)
		expect(full.find('[data-testid="fcias-dup-across"]').attributes('href')).not.toContain('includeEmpty')
	})
})

describe('ChecksumsSidebarTab, the duplicates in place', () => {
	const EMPTY_SHA1 = 'da39a3ee5e6b4b0d3255bfef95601890afd80709'
	const file = (fileid: number) => ({ fileid, path: `/f${fileid}.txt`, name: `f${fileid}.txt` })

	afterEach(() => {
		vi.restoreAllMocks()
	})

	async function findDuplicates(duplicates: unknown[]) {
		const wrapper = await mountWith({ hashes: [SHA1] }, undefined, { duplicates })
		await wrapper.find('.fcias-dup-btn').trigger('click')
		await flushPromises()
		return wrapper
	}

	it('says the empty files\' group in one line, with the way to all of it', async () => {
		const wrapper = await findDuplicates([
			{ algo: 'sha1', hash_value: EMPTY_SHA1, empty: true, files: [file(7), file(8)] },
		])

		const line = wrapper.find('[data-testid="fcias-dup-empty"]')
		expect(line.exists()).toBe(true)
		expect(wrapper.find('.fcias-dup-list').exists()).toBe(false)
		const link = line.find('a')
		expect(link.attributes('href')).toBe(
			`/index.php/apps/file_checksum_search/duplicates#mine?hash=${EMPTY_SHA1}&algo=sha1&includeEmpty=1`,
		)
		expect(link.attributes('target')).toBe('_blank')
	})

	it('still lists any other group\'s files', async () => {
		const wrapper = await findDuplicates([
			{ algo: 'sha1', hash_value: SHA1.hash, empty: false, files: [file(7), file(8)] },
		])

		expect(wrapper.find('[data-testid="fcias-dup-empty"]').exists()).toBe(false)
		expect(wrapper.findAll('.fcias-dup-item')).toHaveLength(2)
	})
})
