/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The sidebar's ways to the Duplicates page: the Others tab, with the
 * file's hash filled in and the viewer's whole reach named, and the
 * viewer's own listing on one group.
 *
 * The hash travels, not the file id. The hashes route answers only for a
 * file the caller holds, so a link by id would be dead in a colleague's
 * hands; the hash is the thing being shared, and the tab's filter finds
 * exactly its group from a whole value.
 */
import { generateUrl } from '@nextcloud/router'
import { FRONTEND } from '../routes'
import { LISTING_DEFAULTS, fragmentFor } from '../duplicates-vue/urlState'
import type { HashEntry, HashMap } from './types'

/**
 * Which of a file's hashes the link carries: the preferred algorithm's
 * where the file has one, else the first. Null with no hashes, which is
 * when there is nothing to look for.
 */
export function hashForLink(hashes: Readonly<HashMap>, preferred: string): HashEntry | null {
	if (Object.hasOwn(hashes, preferred)) {
		return hashes[preferred]
	}
	return Object.values(hashes)[0] ?? null
}

/**
 * The address of the Others tab, on that hash, over the whole reach. An
 * empty file's hash asks for the empty files too, which the listing leaves
 * out by default: without it the tab would open on nothing.
 */
export function crossAccountUrl(entry: HashEntry, includeEmpty = false): string {
	return generateUrl(FRONTEND.duplicates) + fragmentFor(
		'others',
		{ ...LISTING_DEFAULTS, hash: entry.hash, algo: entry.algo, includeEmpty },
		{ all: true, users: [], groups: [] },
	)
}

/**
 * The address of the viewer's own listing on one group of empty files: the
 * list the sidebar does not show in full, since it would be every empty
 * file they hold.
 */
export function emptyGroupUrl(algo: string, hash: string): string {
	return generateUrl(FRONTEND.duplicates) + fragmentFor(
		'mine',
		{ ...LISTING_DEFAULTS, hash, algo, includeEmpty: true },
	)
}
