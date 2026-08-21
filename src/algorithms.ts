/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Shared algorithm option helpers for the FCIAS frontend.
 */

export interface AlgoOption {
	id: string
	label: string
}

/**
 * Canonical algorithm list, mirroring
 * `HashCalculationService::SUPPORTED_ALGOS` (lib/Service/HashCalculationService.php).
 */
export const SUPPORTED_ALGOS: readonly string[] = [
	'sha1',
	'md5',
	'adler32',
	'crc32',
	'sha256',
	'sha512',
	'sha3-256',
	'sha3-512',
]

/**
 * Convert a list of algorithm ids into `{ id, label }` options for NcSelect.
 */
export function toAlgoOptions(ids: string[]): AlgoOption[] {
	return ids.map((id) => ({ id, label: id.toUpperCase() }))
}
