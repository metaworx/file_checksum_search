/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What the Duplicates page does to the rows it lists, through the server:
 * deleting them, and restoring them. The server checks each row again —
 * its hash, the account's reach, its permissions — and answers per row.
 */
import { generateOcsUrl } from '@nextcloud/router'
import { OCS_API_V1 } from '../routes'

declare const OC: {
	requestToken: string
}

/** What the listing says a row allows, with `actions=1`. */
export type RowAction = 'trash' | 'delete' | 'mergeInto' | 'restore' | 'restoreAsVersion' | 'saveAsFile'

/** How a row is restored: a trashed file to its place, a trashed version into the file there, any version as a new file. */
export type RestoreAs = 'original' | 'version' | 'file'

/** An earlier version that goes along with a file deleted. */
export interface GoingVersion {
	/** When the file held it, in seconds since the epoch. */
	timestamp: number
	size: number
	/** The name it was given, where it has one. */
	label: string | null
}

/** The server's answer for one row. */
export interface RowResult {
	fileid: number
	kind: 'file' | 'version' | 'trash' | 'trashedVersion' | null
	/** `ready` is a dry run's: it would act. `refused` and `failed` carry a reason. */
	status: 'ready' | 'trashed' | 'deleted' | 'refused' | 'failed' | 'restored'
	action?: 'trash' | 'delete'
	reason?: string
	versions?: GoingVersion[]
	merge?: { imported: number, skipped: number, failed: boolean }
	restored?: { fileid: number, path: string }
}

/** The group the rows are of: each must still hold its hash. */
export interface GroupKey {
	algo: string
	hash_value: string
}

export interface DeleteOptions {
	/** Past the trash; a file only, every other kind goes for good anyway. */
	forGood: boolean
	/** Act on nothing, and say what would happen. */
	dryRun: boolean
	/** The file of the group that takes the deleted files' versions. */
	mergeInto?: number | null
}

async function post<T>(route: string, body: object): Promise<T> {
	const response = await fetch(generateOcsUrl(route), {
		method: 'POST',
		headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
		body: JSON.stringify(body),
	})
	if (!response.ok) {
		throw new Error(`HTTP ${response.status}`)
	}
	return (await response.json()) as T
}

/** Delete rows of one group, or, as a dry run, say what deleting them would do. */
export async function deleteRows(group: GroupKey, fileIds: number[], options: DeleteOptions): Promise<RowResult[]> {
	const data = await post<{ results?: RowResult[] }>(OCS_API_V1.deleteDuplicates, {
		fileIds,
		algo: group.algo,
		hash: group.hash_value,
		forGood: options.forGood,
		dryRun: options.dryRun,
		...(options.mergeInto ? { mergeInto: options.mergeInto } : {}),
	})
	return data.results ?? []
}

/** Restore one row of a group, the way `as` names. */
export async function restoreRow(group: GroupKey, fileId: number, as: RestoreAs): Promise<RowResult> {
	const data = await post<{ result?: RowResult }>(OCS_API_V1.restoreDuplicate, {
		fileId,
		algo: group.algo,
		hash: group.hash_value,
		as,
	})
	if (!data.result) {
		throw new Error('no result')
	}
	return data.result
}
