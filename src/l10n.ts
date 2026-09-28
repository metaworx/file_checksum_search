/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The app's t() and n(): @nextcloud/l10n's, for text that Vue renders as text.
 *
 * The library escapes the values it puts into a text and sanitises the result
 * as HTML, both for `v-html`; in a text binding that shows as "Index &amp;
 * Search". Vue escapes what it renders, so here neither is done, and no
 * translated text goes into `v-html` (AGENTS.md §3.7).
 *
 * The app id stays the first argument although it never changes: Nextcloud's
 * translation tool finds a text as t()'s second argument and n()'s second and
 * third, and only in calls spelled out this way.
 */
import { translate, translatePlural } from '@nextcloud/l10n'

type Values = Record<string, string | number>

/** The text in the user's language, with `{name}` placeholders filled from `values`. */
export function t(app: string, text: string, values?: Values): string {
	return translate(app, text, values, undefined, { escape: false, sanitize: false })
}

/** The singular or plural text for `count`, which fills `%n`; `{name}` placeholders as in t(). */
export function n(app: string, singular: string, plural: string, count: number, values?: Values): string {
	return translatePlural(app, singular, plural, count, values, { escape: false, sanitize: false })
}
