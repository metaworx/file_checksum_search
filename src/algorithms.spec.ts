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
})
