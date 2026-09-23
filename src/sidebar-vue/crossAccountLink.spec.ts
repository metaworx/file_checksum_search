/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { crossAccountUrl, hashForLink } from './crossAccountLink'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url: string) => `/index.php${url}`,
}))

const SHA1 = { algo: 'sha1', hash: '0beec7b5ea3f0fdbc95d0dd47f3c5bc275da8a33' }
const SHA256 = { algo: 'sha256', hash: '2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae' }

describe('hashForLink', () => {
	it('is nothing for a file with no hashes', () => {
		expect(hashForLink([], 'sha1')).toBeNull()
	})

	it('takes the preferred algorithm where the file has it', () => {
		expect(hashForLink([SHA1, SHA256], 'sha256')).toBe(SHA256)
	})

	it('falls back to the first row', () => {
		expect(hashForLink([SHA1, SHA256], 'md5')).toBe(SHA1)
		expect(hashForLink([SHA1, SHA256], '')).toBe(SHA1)
	})
})

describe('crossAccountUrl', () => {
	it('opens the Others tab on the hash, over the whole reach', () => {
		expect(crossAccountUrl(SHA1)).toBe(
			`/index.php/apps/file_checksum_search/duplicates#others?hash=${SHA1.hash}&algo=sha1&all=1`,
		)
	})
})
