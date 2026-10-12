/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What the server offers a caller to ask for, as `/api/v1/features` tells
 * every account in `features`. The page carries it as initial state, so that
 * reading it costs no request.
 */

import { loadState } from '@nextcloud/initial-state'
import { generateOcsUrl } from '@nextcloud/router'
import { OCS_API_V1 } from './routes'

export interface Features {
	/** Whether the routes give a file's MIME type when asked, `?mimetype=1`. */
	mimetype: boolean
}

/** What a server says when it says nothing: an older one, or one that failed. */
const NONE: Features = { mimetype: false }

let features: Promise<Features> | null = null

/** Whether the page's own state may still answer: once, before the server is asked. */
let fromPage = true

/** The features as the server's answer gives them. */
function featuresOf(answer: { features?: Partial<Features> }): Features {
	return { mimetype: answer.features?.mimetype === true }
}

/**
 * The features the page was rendered with, or null where it carries none:
 * a page this app did not render, or a server older than the state.
 */
function pageFeatures(): Features | null {
	try {
		return featuresOf(loadState<{ features?: Partial<Features> }>('file_checksum_search', 'features'))
	} catch {
		return null
	}
}

/**
 * The features, read once per page and shared by every part of it: from
 * the page's initial state, else from `/api/v1/features`.
 *
 * Asking for what the server does not offer is refused with a 400, so a
 * page asks for a MIME type only while this says it may. A failed read
 * answers none, and is not kept: the next caller asks again.
 */
export function fetchFeatures(): Promise<Features> {
	if (features === null) {
		const given = fromPage ? pageFeatures() : null
		fromPage = false

		features = given !== null
			? Promise.resolve(given)
			: fetch(generateOcsUrl(OCS_API_V1.getFeatures))
				.then(async (response) => {
					if (!response.ok) {
						throw new Error(`features: HTTP ${response.status}`)
					}
					return featuresOf((await response.json()) as { features?: Partial<Features> })
				})
				.catch(() => {
					features = null
					return NONE
				})
	}
	return features
}

/**
 * Whether a request that asked for MIME types was refused because detection
 * was switched off after the page read the features. A 400 to such a
 * request reads them again, from the server, not the page; the caller then
 * asks again without, rather than failing until the page is reloaded.
 */
export async function mimeTypeSwitchedOff(response: Response, asked: boolean): Promise<boolean> {
	if (!asked || response.status !== 400) {
		return false
	}
	features = null
	fromPage = false
	return !(await fetchFeatures()).mimetype
}

/** Test seam: forget the features read, and read the page again. */
export function resetFeatureCache(): void {
	features = null
	fromPage = true
}
