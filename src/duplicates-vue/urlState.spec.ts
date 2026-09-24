/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import {
	LISTING_DEFAULTS,
	fragmentFor,
	listingFromParams,
	parseFragment,
	sameParams,
	sameScope,
	scopeFromParams,
} from './urlState'

describe('parseFragment', () => {
	it('names the tab, mine when nothing or nonsense does', () => {
		expect(parseFragment('').tab).toBe('mine')
		expect(parseFragment('#').tab).toBe('mine')
		expect(parseFragment('#mine').tab).toBe('mine')
		expect(parseFragment('#others').tab).toBe('others')
		expect(parseFragment('#help').tab).toBe('help')
		expect(parseFragment('#elsewhere').tab).toBe('mine')
	})

	it('keeps what follows the question mark as the query', () => {
		const { tab, params } = parseFragment('#others?hash=abc&all=1')
		expect(tab).toBe('others')
		expect(params.get('hash')).toBe('abc')
		expect(params.get('all')).toBe('1')
	})

	it('reads an old-style #others/anything as the tab alone', () => {
		expect(parseFragment('#others/x').tab).toBe('others')
	})
})

describe('listingFromParams', () => {
	it('is the defaults for an empty query', () => {
		expect(listingFromParams(new URLSearchParams())).toEqual(LISTING_DEFAULTS)
	})

	// `limit=` with nothing after it was read as 0 and clamped to 1: a
	// page of one group from an address that named no limit at all.
	it('reads an empty value as missing, not as zero', () => {
		const params = new URLSearchParams('limit=&minCount=&offset=%20')
		expect(listingFromParams(params)).toEqual(LISTING_DEFAULTS)
	})

	it('reads every field, trimmed and typed', () => {
		const params = new URLSearchParams('hash=%20ABC%20&anywhere=1&algo=sha256&minCount=3&limit=10&offset=20')
		expect(listingFromParams(params)).toEqual({
			hash: 'ABC',
			anywhere: true,
			algo: 'sha256',
			minCount: 3,
			limit: 10,
			offset: 20,
		})
	})

	it('clamps the numbers to what the fields accept and falls back on nonsense', () => {
		const params = new URLSearchParams('minCount=0&limit=9999&offset=-5')
		expect(listingFromParams(params)).toMatchObject({ minCount: 2, limit: 500, offset: 0 })
		expect(listingFromParams(new URLSearchParams('minCount=x&limit=y&offset=z')))
			.toMatchObject({ minCount: 2, limit: 50, offset: 0 })
	})

	it('drops anywhere without a term', () => {
		expect(listingFromParams(new URLSearchParams('anywhere=1')).anywhere).toBe(false)
	})

	it('keeps an algorithm the instance computes and drops one it does not', () => {
		expect(listingFromParams(new URLSearchParams('algo=sha1'), ['sha1', 'md5']).algo).toBe('sha1')
		expect(listingFromParams(new URLSearchParams('algo=whirlpool'), ['sha1', 'md5']).algo).toBe('')
		// The list not known yet: kept, for the listing to judge later.
		expect(listingFromParams(new URLSearchParams('algo=whirlpool')).algo).toBe('whirlpool')
	})
})

describe('scopeFromParams', () => {
	it('is nobody for an empty query', () => {
		expect(scopeFromParams(new URLSearchParams())).toEqual({ all: false, users: [], groups: [] })
	})

	it('reads repeated users and groups, deduplicated and trimmed', () => {
		const params = new URLSearchParams('users=alice&users=%20bob%20&users=alice&groups=team&groups=')
		expect(scopeFromParams(params)).toEqual({ all: false, users: ['alice', 'bob'], groups: ['team'] })
	})

	it('lets all win over names', () => {
		expect(scopeFromParams(new URLSearchParams('all=1&users=alice'))).toEqual({ all: true, users: [], groups: [] })
	})
})

describe('fragmentFor', () => {
	it('is the bare tab for the defaults', () => {
		expect(fragmentFor('mine', { ...LISTING_DEFAULTS })).toBe('#mine')
		expect(fragmentFor('help')).toBe('#help')
		expect(fragmentFor('others', { ...LISTING_DEFAULTS }, { all: false, users: [], groups: [] })).toBe('#others')
	})

	it('writes only what differs from the defaults', () => {
		expect(fragmentFor('mine', { ...LISTING_DEFAULTS, hash: 'abc', limit: 10 })).toBe('#mine?hash=abc&limit=10')
		expect(fragmentFor('mine', { ...LISTING_DEFAULTS, anywhere: true })).toBe('#mine')
		expect(fragmentFor('mine', { ...LISTING_DEFAULTS, hash: 'abc', anywhere: true })).toBe('#mine?hash=abc&anywhere=1')
	})

	it('writes the scope after the filters', () => {
		expect(fragmentFor('others', { ...LISTING_DEFAULTS, hash: 'abc' }, { all: true, users: [], groups: [] }))
			.toBe('#others?hash=abc&all=1')
		expect(fragmentFor('others', null, { all: false, users: ['alice', 'bob'], groups: ['team'] }))
			.toBe('#others?users=alice&users=bob&groups=team')
	})

	it('round-trips through parse', () => {
		const listing = { hash: 'DEADBEEF', anywhere: true, algo: 'sha256', minCount: 3, limit: 25, offset: 50 }
		const scope = { all: false, users: ['alice'], groups: ['team', 'other'] }
		const { tab, params } = parseFragment(fragmentFor('others', listing, scope))
		expect(tab).toBe('others')
		expect(listingFromParams(params)).toEqual(listing)
		expect(scopeFromParams(params)).toEqual(scope)
	})
})

describe('sameParams', () => {
	it('compares every field', () => {
		expect(sameParams({ ...LISTING_DEFAULTS }, { ...LISTING_DEFAULTS })).toBe(true)
		expect(sameParams({ ...LISTING_DEFAULTS }, { ...LISTING_DEFAULTS, offset: 1 })).toBe(false)
	})
})

describe('sameScope', () => {
	it('is order-sensitive equality of the three parts', () => {
		const a = { all: false, users: ['alice', 'bob'], groups: ['team'] }
		expect(sameScope(a, { all: false, users: ['alice', 'bob'], groups: ['team'] })).toBe(true)
		expect(sameScope(a, { all: false, users: ['bob', 'alice'], groups: ['team'] })).toBe(false)
		expect(sameScope(a, { all: false, users: ['alice'], groups: ['team'] })).toBe(false)
		expect(sameScope(a, { all: true, users: [], groups: [] })).toBe(false)
		expect(sameScope({ all: true, users: [], groups: [] }, { all: true, users: [], groups: [] })).toBe(true)
	})
})
