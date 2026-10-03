/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { crossAccountUrl, emptyGroupUrl, hashForLink } from './crossAccountLink'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url: string) => `/index.php${url}`,
}))

const SHA1 = { algo: 'sha1', hash: '0beec7b5ea3f0fdbc95d0dd47f3c5bc275da8a33' }
const SHA256 = { algo: 'sha256', hash: '2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae' }

describe('hashForLink', () => {
	it('is nothing for a file with no hashes', () => {
		expect(hashForLink({}, 'sha1')).toBeNull()
	})

	it('takes the preferred algorithm where the file has it', () => {
		expect(hashForLink({ sha1: SHA1, sha256: SHA256 }, 'sha256')).toBe(SHA256)
	})

	it('falls back to the first', () => {
		expect(hashForLink({ sha1: SHA1, sha256: SHA256 }, 'md5')).toBe(SHA1)
		expect(hashForLink({ sha1: SHA1, sha256: SHA256 }, '')).toBe(SHA1)
	})

	it('reads only the file\'s own keys, not an object\'s inherited ones', () => {
		expect(hashForLink({ sha1: SHA1 }, 'constructor')).toBe(SHA1)
	})
})

describe('crossAccountUrl', () => {
	it('opens the Others tab on the hash, over the whole reach', () => {
		expect(crossAccountUrl(SHA1)).toBe(
			`/index.php/apps/file_checksum_search/duplicates#others?hash=${SHA1.hash}&algo=sha1&all=1`,
		)
	})

	it('asks for the empty files for an empty file, whose group they are', () => {
		expect(crossAccountUrl(SHA1, true)).toBe(
			`/index.php/apps/file_checksum_search/duplicates#others?hash=${SHA1.hash}&algo=sha1&includeEmpty=1&all=1`,
		)
	})
})

describe('emptyGroupUrl', () => {
	it('opens the viewer\'s own listing on that group, empty files included', () => {
		const empty = 'da39a3ee5e6b4b0d3255bfef95601890afd80709'
		expect(emptyGroupUrl('sha1', empty)).toBe(
			`/index.php/apps/file_checksum_search/duplicates#mine?hash=${empty}&algo=sha1&includeEmpty=1`,
		)
	})
})
