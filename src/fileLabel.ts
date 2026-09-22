/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What to call a file in a list that may hold other people's.
 */

/** The part of a file row the label is made from; every API row carries it. */
export interface LabelledFile {
	path?: string
	name?: string
	/** The uid a home file belongs to; null for a group folder or external storage. */
	owner?: string | null
	/** Where the file really lives — `/uid/files/…`, `groupfolder:3/…`, `storage:…`. */
	location?: string
}

/**
 * The account this page is being shown to.
 *
 * Nextcloud writes it on `<head data-user>` for every page, which is where
 * `@nextcloud/auth` reads it from too; asking the DOM directly saves a
 * dependency for one attribute.
 */
export function currentUid(): string | null {
	return document.head?.dataset?.user || null
}

/**
 * A file's own path where it is the viewer's, and its location where it is
 * not.
 *
 * A path is one viewer's name for a file: three people's copies of
 * `Templates/Certificate.odt` all read the same, so a cross-account list of
 * them says nothing about whose each is. The location says — and for the
 * viewer's own files it would only be noise, so they keep the path they know.
 * A file with no owner (a group folder, an external storage) is nobody's own,
 * and shows its location too.
 */
export function fileLabel(file: LabelledFile, uid: string | null = currentUid()): string {
	const own = file.owner !== undefined && file.owner !== null && file.owner === uid

	if (!own && file.location) {
		return file.location
	}

	return file.path || file.name || ''
}
