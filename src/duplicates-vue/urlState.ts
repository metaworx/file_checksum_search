/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The Duplicates page's state as a URL fragment, both ways.
 *
 * `#others?hash=…&algo=sha1&all=1`: the tab, then what the tab shows. In the
 * fragment rather than the query string, because a fragment never reaches
 * the server or its logs — this one may name accounts — and because the tab
 * already lived there. Only what differs from the defaults is written, so a
 * bare `#mine` stays bare, and what is read is clamped to what the fields
 * accept: a shared link should open, not refuse.
 */
import type { DuplicateScope } from './composables/useDuplicates'

export type Tab = 'mine' | 'others' | 'help'

/** What one listing shows: the filters and the page. */
export interface ListingParams {
	hash: string
	anywhere: boolean
	algo: string
	minCount: number
	limit: number
	offset: number
}

export const LISTING_DEFAULTS: Readonly<ListingParams> = Object.freeze({
	hash: '',
	anywhere: false,
	algo: '',
	minCount: 2,
	limit: 50,
	offset: 0,
})

/** The tab a fragment names, and whatever follows its `?`. */
export function parseFragment(fragment: string): { tab: Tab, params: URLSearchParams } {
	const bare = fragment.replace(/^#/, '')
	const at = bare.indexOf('?')
	const head = (at === -1 ? bare : bare.slice(0, at)).split('/')[0]
	const tab: Tab = head === 'others' || head === 'help' ? head : 'mine'
	return { tab, params: new URLSearchParams(at === -1 ? '' : bare.slice(at + 1)) }
}

function bounded(value: string | null, min: number, max: number, fallback: number): number {
	// `limit=` with nothing after it is missing, not zero: Number('') is 0,
	// and clamped that was the minimum — a page of one group.
	if (value === null || value.trim() === '') {
		return fallback
	}
	const n = Math.trunc(Number(value))
	return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : fallback
}

/**
 * A listing's parameters from a fragment's query, each within the range
 * its field accepts. An algorithm the instance does not compute falls back
 * to all of them, once the list is known; before that it is kept, and the
 * listing drops it when the list arrives.
 */
export function listingFromParams(params: URLSearchParams, algorithmIds: readonly string[] | null = null): ListingParams {
	const hash = (params.get('hash') ?? '').trim()
	const algo = params.get('algo') ?? ''
	const known = algorithmIds === null || algorithmIds.length === 0 || algorithmIds.includes(algo)

	return {
		hash,
		// Meaningless without a term, and written only with one.
		anywhere: hash !== '' && params.get('anywhere') === '1',
		algo: known ? algo : '',
		minCount: bounded(params.get('minCount'), 2, 100, LISTING_DEFAULTS.minCount),
		limit: bounded(params.get('limit'), 1, 500, LISTING_DEFAULTS.limit),
		offset: bounded(params.get('offset'), 0, Number.MAX_SAFE_INTEGER, 0),
	}
}

/**
 * Whose files, from a fragment's query: `all=1`, or `users` and `groups`
 * repeated. `all` wins over names, as it does in the picker.
 */
export function scopeFromParams(params: URLSearchParams): DuplicateScope {
	if (params.get('all') === '1') {
		return { all: true, users: [], groups: [] }
	}
	const clean = (values: string[]): string[] => [...new Set(values.map((v) => v.trim()).filter((v) => v !== ''))]
	return { all: false, users: clean(params.getAll('users')), groups: clean(params.getAll('groups')) }
}

export function sameScope(a: DuplicateScope, b: DuplicateScope): boolean {
	const same = (x: string[], y: string[]): boolean => x.length === y.length && x.every((v, i) => v === y[i])
	return a.all === b.all && same(a.users, b.users) && same(a.groups, b.groups)
}

export function sameParams(a: ListingParams, b: ListingParams): boolean {
	return a.hash === b.hash
		&& a.anywhere === b.anywhere
		&& a.algo === b.algo
		&& a.minCount === b.minCount
		&& a.limit === b.limit
		&& a.offset === b.offset
}

/** The fragment for a tab and what it shows; defaults are left unsaid. */
export function fragmentFor(tab: Tab, listing: ListingParams | null = null, scope: DuplicateScope | null = null): string {
	const params = new URLSearchParams()

	if (listing) {
		const hash = listing.hash.trim()
		if (hash) {
			params.set('hash', hash)
			if (listing.anywhere) {
				params.set('anywhere', '1')
			}
		}
		if (listing.algo) {
			params.set('algo', listing.algo)
		}
		if (listing.minCount !== LISTING_DEFAULTS.minCount) {
			params.set('minCount', String(listing.minCount))
		}
		if (listing.limit !== LISTING_DEFAULTS.limit) {
			params.set('limit', String(listing.limit))
		}
		if (listing.offset > 0) {
			params.set('offset', String(listing.offset))
		}
	}

	if (scope) {
		if (scope.all) {
			params.set('all', '1')
		} else {
			scope.users.forEach((uid) => params.append('users', uid))
			scope.groups.forEach((gid) => params.append('groups', gid))
		}
	}

	const query = params.toString()
	return `#${tab}${query ? `?${query}` : ''}`
}
