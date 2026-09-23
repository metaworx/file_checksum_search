/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The sidebar's way to the Duplicates page's Others tab: one link, with
 * the file's hash filled in and the viewer's whole reach named.
 *
 * The hash travels, not the file id. The hashes route answers only for a
 * file the caller holds, so a link by id would be dead in a colleague's
 * hands; the hash is the thing being shared, and the Others tab's filter
 * finds exactly its group from a whole value.
 */
import { generateUrl } from '@nextcloud/router'
import { FRONTEND } from '../routes'
import { LISTING_DEFAULTS, fragmentFor } from '../duplicates-vue/urlState'
import type { HashEntry } from './types'

/**
 * Which of a file's hashes the link carries: the preferred algorithm's
 * where the file has one, else the first row's. Null with no hashes, which
 * is when there is nothing to look for.
 */
export function hashForLink(hashes: readonly HashEntry[], preferred: string): HashEntry | null {
	if (hashes.length === 0) {
		return null
	}
	return hashes.find((entry) => entry.algo === preferred) ?? hashes[0]
}

/** The address of the Others tab, on that hash, over the whole reach. */
export function crossAccountUrl(entry: HashEntry): string {
	return generateUrl(FRONTEND.duplicates) + fragmentFor(
		'others',
		{ ...LISTING_DEFAULTS, hash: entry.hash, algo: entry.algo },
		{ all: true, users: [], groups: [] },
	)
}
