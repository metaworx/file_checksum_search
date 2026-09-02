/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Shared algorithm option helpers for the FCIAS frontend.
 */

import { generateOcsUrl } from '@nextcloud/router'
import { OCS_API_V1 } from './routes'

export interface AlgoOption {
	id: string
	label: string
}

export interface AlgorithmCatalogue {
	algorithms: string[]
	default: string
}

let catalogue: Promise<AlgorithmCatalogue> | null = null

/**
 * The algorithms this instance computes, and the one used when none is named.
 *
 * Read from the server rather than carried here: the set is what PHP offers
 * narrowed to what the administrator allows, and a picker that shipped its
 * own copy would be wrong the day an administrator changed it. Fetched once
 * per page load and shared by every picker on the page; a failed fetch is
 * not cached, so the next caller tries again.
 */
export function fetchAlgorithms(): Promise<AlgorithmCatalogue> {
	if (catalogue === null) {
		catalogue = fetch(generateOcsUrl(OCS_API_V1.getAlgorithms))
			.then(async (response) => {
				if (!response.ok) {
					throw new Error(`algorithms: HTTP ${response.status}`)
				}
				const data = (await response.json()) as Partial<AlgorithmCatalogue>
				return {
					algorithms: Array.isArray(data.algorithms) ? data.algorithms : [],
					default: typeof data.default === 'string' ? data.default : '',
				}
			})
			.catch((error) => {
				catalogue = null
				throw error
			})
	}
	return catalogue
}

/** Test seam: forget the cached catalogue. */
export function resetAlgorithmCache(): void {
	catalogue = null
}

/**
 * Convert a list of algorithm ids into `{ id, label }` options for NcSelect.
 */
export function toAlgoOptions(ids: string[]): AlgoOption[] {
	return ids.map((id) => ({ id, label: id.toUpperCase() }))
}
