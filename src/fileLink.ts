/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The link that opens a file in Files, from the Duplicates page and from the
 * sidebar's duplicate list alike.
 */
import { generateUrl } from '@nextcloud/router'
import { FRONTEND } from './routes'

/**
 * Files, opened on the file's folder with its details pane, the file itself
 * not opened.
 *
 * Core's `/f/{fileid}` finds the file among the viewer's own files, received
 * shares and team folders included, and redirects to its folder. Core's
 * `/apps/files/files/{fileid}` works the folder out only when a `dir` comes
 * along; sent none, it lists the root folder and says the file could not be
 * found. `openfile=false`, because for a file `/f/` would otherwise open it
 * in the viewer rather than show its details.
 */
export function fileLink(fileid: number): string {
	return `${generateUrl(FRONTEND.fileLink, { fileid })}?opendetails=true&openfile=false`
}

/**
 * The link for a row of a list: a version opens its file, as Files keeps
 * a file's versions in the file's details and opens none by itself; a file
 * in the trash, or a version of one, opens the trash, where it can be
 * restored.
 */
export function rowLink(row: { fileid: number, version?: { fileid: number }, trash?: { deletedAt: string } }): string {
	if (row.trash) {
		return generateUrl(FRONTEND.trashLink, { fileid: row.version?.fileid ?? row.fileid })
	}

	return fileLink(row.version?.fileid ?? row.fileid)
}

/** Twice the side a group's thumbnail is shown at, so a dense screen shows it sharp. */
const PREVIEW_SIZE = 64

/**
 * One thumbnail for a group of duplicates. Its files share their content,
 * so any of them shows it: core's preview of the first file the viewer can
 * open, falling back to its type's icon; else the trash's preview of the
 * first file in the trash. Null for a group of versions only, and for one
 * whose files the viewer cannot open, whose previews would be refused.
 */
export function groupPreviewUrl(files: Array<{ fileid: number, version?: object, trash?: object, openable?: boolean }>): string | null {
	const size = `x=${PREVIEW_SIZE}&y=${PREVIEW_SIZE}&a=1`
	const file = files.find((row) => !row.version && !row.trash && row.openable !== false)

	if (file) {
		return `${generateUrl(FRONTEND.preview)}?fileId=${file.fileid}&${size}&mimeFallback=1`
	}

	const trashed = files.find((row) => !row.version && row.trash && row.openable !== false)

	return trashed
		? `${generateUrl(FRONTEND.trashPreview)}?fileId=${trashed.fileid}&${size}`
		: null
}
