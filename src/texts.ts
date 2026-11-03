/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The texts @metaworx/nextcloud-vue shows here, in this app's translations:
 * spelled out in the app's own sources, where the translation tool finds
 * them, and handed over once, in each page's entry, with setTexts().
 *
 * Two of the package's nine: the help popover's name and the toast a saved
 * setting raises. The components that show the other seven are not used
 * here, and keep the package's English.
 *
 * A function, so that the texts are translated when the page starts, not
 * when this module loads (AGENTS.md §3.7).
 */
import { t, type Texts } from '@metaworx/nextcloud-vue'

export function appTexts(): Partial<Texts> {
	return {
		help: (label) => t('file_checksum_search', 'Help: {label}', { label }),
		saved: t('file_checksum_search', 'Saved.'),
	}
}
