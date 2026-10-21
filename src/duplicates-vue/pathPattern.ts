/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * A path pattern, as the Duplicates page's path filter and its selection
 * read it — the same as the server's `PathPattern`, held to it by the cases
 * in tests/fixtures/path-patterns.json:
 *
 * - `*` matches within a name, `**` across folders, `?` one character;
 * - a pattern without `/` matches a name anywhere: a file of that name, or a
 *   folder of that name and everything in it;
 * - one with `/` matches from the root: the path, or a folder on it and
 *   everything in it; a leading `/` is optional;
 * - a trailing `/` matches folders only;
 * - case is ignored.
 *
 * A version or a trashed row is matched by the path it shows, less its
 * `versions://` or `trash://`.
 */

/** A compiled pattern: whether a row's path matches it. */
export type PathMatcher = (path: string) => boolean

/** The longest pattern taken, as the server takes it. */
export const MAX_PATTERN_LENGTH = 255

/** The pattern as a regular expression's source, or null where it is empty. */
export function patternSource(pattern: string): string | null {
	let text = pattern.trim()
	const foldersOnly = /\/$/.test(text)
	text = text.replace(/\/+$/, '')
	const anchored = text.includes('/')
	text = text.replace(/^\/+/, '')
	if (text === '') {
		return null
	}

	let body = ''
	for (let i = 0; i < text.length; i++) {
		const char = text[i]
		if (char === '*' && text[i + 1] === '*') {
			// `**/` is any folders, none included; a lone `**` anything.
			while (text[i + 1] === '*') {
				i++
			}
			if (text[i + 1] === '/') {
				body += '(?:.*/)?'
				i++
			} else {
				body += '.*'
			}
		} else if (char === '*') {
			body += '[^/]*'
		} else if (char === '?') {
			body += '[^/]'
		} else {
			// The syntax characters and `/`, the only escapes the u flag takes.
			body += char.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')
		}
	}

	const start = anchored ? '^' : '(?:^|/)'
	const end = foldersOnly ? '/.+$' : '(?:/.*)?$'
	return `${start}${body}${end}`
}

/** The path a row is matched by: no scheme, no leading slash. */
export function subjectOf(path: string): string {
	return path.replace(/^[a-z]+:\/\//, '').replace(/^\/+/, '')
}

/** A matcher for the pattern, or null where it is empty: no filter, nothing chosen. */
export function compilePattern(pattern: string): PathMatcher | null {
	const source = patternSource(pattern)
	if (source === null) {
		return null
	}
	const regex = new RegExp(source, 'iu')
	return (path) => regex.test(subjectOf(path))
}
