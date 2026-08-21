/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Shared types for the checksums sidebar tab.
 */

export interface HashEntry {
	algo: string
	hash: string
	updated_at?: string
}

export interface DuplicateFile {
	fileid: number
	path: string
}

export interface DuplicateGroup {
	algo: string
	hash_value: string
	files: DuplicateFile[]
}

export interface FileNode {
	fileid?: number
	attributes?: { fileid?: number }
	type?: string
	source?: string
	path?: string
}
