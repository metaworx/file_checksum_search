/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What the server offers a caller to ask for, as `/api/v1/status` tells
 * every account in `features`.
 */

import { generateOcsUrl } from '@nextcloud/router'
import { OCS_API_V1 } from './routes'

export interface Features {
	/** Whether the routes give a file's MIME type when asked, `?mimetype=1`. */
	mimetype: boolean
}

/** What a server says when it says nothing: an older one, or one that failed. */
const NONE: Features = { mimetype: false }

let features: Promise<Features> | null = null

/**
 * The features, read once per page and shared by every part of it.
 *
 * Asking for what the server does not offer is refused with a 400, so a
 * page asks for a MIME type only while this says it may. A failed read
 * answers none, and is not kept: the next caller asks again.
 */
export function fetchFeatures(): Promise<Features> {
	if (features === null) {
		features = fetch(generateOcsUrl(OCS_API_V1.getStatus))
			.then(async (response) => {
				if (!response.ok) {
					throw new Error(`status: HTTP ${response.status}`)
				}
				const data = (await response.json()) as { features?: Partial<Features> }
				return { mimetype: data.features?.mimetype === true }
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
 * request reads them again; the caller then asks again without, rather
 * than failing until the page is reloaded.
 */
export async function mimeTypeSwitchedOff(response: Response, asked: boolean): Promise<boolean> {
	if (!asked || response.status !== 400) {
		return false
	}
	features = null
	return !(await fetchFeatures()).mimetype
}

/** Test seam: forget the features read. */
export function resetFeatureCache(): void {
	features = null
}
