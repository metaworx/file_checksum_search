/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What to call a file in a list that may hold other people's.
 */

import { t } from './l10n'

/** The part of a file row the label is made from; every API row carries it. */
export interface LabelledFile {
	path?: string
	name?: string
	/** The uid a home file belongs to; null for a group folder or external storage. */
	owner?: string | null
	/**
	 * Where the file lives — `home:uid//…`, `groupfolder:3//…`, `storage:…//…`,
	 * or `share:42//…` for a file the reach holds through a share.
	 */
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
 * A file's own path where it is the viewer's, its owner and the viewer's
 * path where the viewer holds it through a share, and its location
 * otherwise.
 *
 * A path is one viewer's name for a file: three people's copies of
 * `Templates/Certificate.odt` all read the same, so a cross-account list of
 * them says nothing about whose each is. The owner says whose, and the path
 * where the viewer finds it — `bob: My Projects/Bob-x/a.txt` — with nothing
 * of the owner's above the share, which the location leaves out too. For
 * the viewer's own files the owner would only be noise, so they keep the
 * path they know. A file with no owner (a group folder, an external
 * storage), and every file a sudoer reads across accounts, shows its
 * location.
 */
export function fileLabel(file: LabelledFile, uid: string | null = currentUid()): string {
	if (labelIsLocation(file, uid)) {
		const location = file.location as string

		if (location.startsWith('share:') && file.owner && file.path) {
			// TRANSLATORS: a file someone shared: their account name, then where the reader finds the file, as in "bob: My Projects/report.pdf"
			return t('file_checksum_search', '{owner}: {path}', { owner: file.owner, path: file.path.replace(/^\/+/, '') })
		}

		return location
	}

	return ownPath(file.path || '') || file.name || ''
}

/**
 * The path as the viewer knows it. An older server's duplicates listing
 * gave the filecache's path, which puts every home file under `files/`;
 * nobody's Files app shows that segment, so neither does a list of their
 * own files.
 */
function ownPath(path: string): string {
	return path.startsWith('files/') ? path.slice('files/'.length) : path
}

function labelIsLocation(file: LabelledFile, uid: string | null): boolean {
	const own = file.owner !== undefined && file.owner !== null && file.owner === uid
	return !own && !!file.location
}

/** The kinds of place a label names: the viewer's own file, or a location by its prefix. */
export type FileLocationKind = 'own' | 'home' | 'share' | 'groupfolder' | 'storage'

/**
 * What kind of place the label names, for the glyph before it: `own` for
 * the viewer's own file, else the location's kind. Null only where the
 * row says nothing — no owner and no location, an older server's row —
 * or for a location of a shape this does not know.
 */
export function labelKind(file: LabelledFile, uid: string | null = currentUid()): FileLocationKind | null {
	if (!labelIsLocation(file, uid)) {
		const own = file.owner !== undefined && file.owner !== null && file.owner === uid
		return own ? 'own' : null
	}
	const location = file.location as string
	for (const kind of ['share', 'groupfolder', 'storage', 'home'] as const) {
		if (location.startsWith(kind + ':')) {
			return kind
		}
	}
	return null
}
