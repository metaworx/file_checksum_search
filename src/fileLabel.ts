/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What to call a file in a list that may hold other people's.
 */

import { formatDateTime, t } from './l10n'

/** What a version's path and location begin with, before its file's. */
export const VERSION_SCHEME = 'versions://'

/** What a trashed file's path and location begin with, before the place it had. */
export const TRASH_SCHEME = 'trash://'

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
	/**
	 * Present on an earlier version of a file: the file's id, and when it
	 * held this content. Its path and location are the file's, after
	 * {@link VERSION_SCHEME}.
	 */
	version?: { fileid: number, timestamp: string }
	/**
	 * Present on a file in the trash: when it was deleted. Its path and
	 * location are those it had before, after {@link TRASH_SCHEME}.
	 */
	trash?: { deletedAt: string }
}

/**
 * The row as its file is, where it was: a version's and a trashed file's
 * path and location without their schemes.
 */
function asItsFile(file: LabelledFile): LabelledFile {
	if (!file.version && !file.trash) {
		return file
	}

	const strip = (value?: string): string | undefined => {
		let stripped = value
		for (const scheme of [VERSION_SCHEME, TRASH_SCHEME]) {
			if (stripped?.startsWith(scheme)) {
				stripped = stripped.slice(scheme.length)
			}
		}
		return stripped
	}

	return { ...file, path: strip(file.path), location: strip(file.location) }
}

/** What a file in the trash is called beside its label; null for one outside it. */
export function trashLabel(file: LabelledFile): string | null {
	if (!file.trash) {
		return null
	}

	// TRANSLATORS: beside a file in a list, marking it as in the trash; {date} is when it was deleted
	return t('file_checksum_search', 'Deleted {date}', { date: formatDateTime(new Date(file.trash.deletedAt)) })
}

/** What a version is called beside its file's label; null for a file. */
export function versionLabel(file: LabelledFile): string | null {
	if (!file.version) {
		return null
	}

	// TRANSLATORS: beside a file in a list, marking an earlier version of it; {date} is when the file had that content
	return t('file_checksum_search', 'Version of {date}', { date: formatDateTime(new Date(file.version.timestamp)) })
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
 *
 * A version is labelled as its file is, and a trashed file as it was;
 * {@link versionLabel} and {@link trashLabel} say which.
 */
export function fileLabel(row: LabelledFile, uid: string | null = currentUid()): string {
	const file = asItsFile(row)

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
export function labelKind(row: LabelledFile, uid: string | null = currentUid()): FileLocationKind | null {
	const file = asItsFile(row)

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
