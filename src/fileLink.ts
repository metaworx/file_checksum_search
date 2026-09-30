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
