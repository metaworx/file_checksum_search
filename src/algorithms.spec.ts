import { describe, expect, it } from 'vitest'
import { SUPPORTED_ALGOS, toAlgoOptions } from './algorithms'

describe('algorithms', () => {
	it('maps algorithm ids to uppercase label options', () => {
		expect(toAlgoOptions(['sha1', 'md5', 'sha3-256'])).toEqual([
			{ id: 'sha1', label: 'SHA1' },
			{ id: 'md5', label: 'MD5' },
			{ id: 'sha3-256', label: 'SHA3-256' },
		])
	})

	it('contains the default and common algorithms', () => {
		expect(SUPPORTED_ALGOS).toContain('sha256')
		expect(SUPPORTED_ALGOS).toContain('sha1')
		expect(SUPPORTED_ALGOS).toContain('md5')
	})

	// Canary for FCIAS Review §2, Finding 7: this list is mirrored by
	// hand from HashCalculationService::SUPPORTED_ALGOS (PHP), with no
	// automated cross-language check. If this test forces you to update
	// it, update the PHP source (and its own canary test in
	// tests/Unit/Service/HashCalculationServiceTest.php) in the same commit.
	it('matches the PHP backend mirror exactly', () => {
		expect(SUPPORTED_ALGOS).toEqual([
			'sha1',
			'md5',
			'adler32',
			'crc32',
			'sha256',
			'sha512',
			'sha3-256',
			'sha3-512',
		])
	})
})
