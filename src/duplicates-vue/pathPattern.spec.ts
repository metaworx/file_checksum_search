/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The cases are the server's too (PathPatternTest): one table, so that the
 * page selects what the server filters by.
 */
import { describe, expect, it } from 'vitest'
import cases from '../../tests/fixtures/path-patterns.json'
import { compilePattern } from './pathPattern'

describe('a path pattern', () => {
	it.each(cases.map((entry) => [entry.pattern, entry.path, entry.match] as const))('%s on %s: %s', (pattern, path, match) => {
		expect(compilePattern(pattern)!(path)).toBe(match)
	})

	it('is no pattern at all when empty or only slashes', () => {
		expect(compilePattern('')).toBeNull()
		expect(compilePattern('  / ')).toBeNull()
	})
})
