/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Shared types for the checksums sidebar tab.
 */

export interface HashEntry {
	algo: string
	hash: string
}

/** A file's hashes, keyed by algorithm, as the hashes route answers them. */
export type HashMap = Record<string, HashEntry>

export interface DuplicateFile {
	fileid: number
	path: string
	/** The uid a home file belongs to; null for a group folder or external storage. */
	owner?: string | null
	/** Where the file really lives; shown instead of the path when it is not the viewer's. */
	location?: string
	/** Whether the viewer could open it in the Files app; absent on own listings, where they always can. */
	openable?: boolean
}

export interface DuplicateGroup {
	algo: string
	hash_value: string
	/** The empty files' group: its hash is its algorithm's checksum of no input. */
	empty?: boolean
	files: DuplicateFile[]
}

export interface FileNode {
	fileid?: number
	attributes?: { fileid?: number }
	type?: string
	source?: string
	path?: string
	/** In bytes, as the Files app's Node carries it; absent counts as not empty. */
	size?: number
}
