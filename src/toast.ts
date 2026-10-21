/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The app's toasts, in one place.
 *
 * `OC.Notification.showTemporary()` is gone from Nextcloud 34's core bundles,
 * so calling it is a TypeError after the request has already succeeded — a
 * toast nobody sees and an error nobody reports. `@nextcloud/dialogs` is the
 * supported way. Both stylesheets are imported here rather than in the page
 * entry points, so that anything showing a toast gets the library's own
 * container styles and this app's placement without having to know it needs
 * them. One module, so specs mock one module.
 */
import { showError, showSuccess, showUndo } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/style.css'
import './toast.css'
import { t } from './l10n'

/** Long enough to read, short enough not to need dismissing. */
const SUCCESS_TIMEOUT = 5000

/** A setting was saved: fired once the server has confirmed it, never before. */
export function toastSaved(text = t('file_checksum_search', 'Saved.')): void {
	showSuccess(text, { timeout: SUCCESS_TIMEOUT })
}

/** Something else went well and has a name — "Rule deleted.", "Re-apply queued". */
export function toastSuccess(text: string): void {
	showSuccess(text, { timeout: SUCCESS_TIMEOUT })
}

/** Errors keep the library's timing: they stay while hovered, as Nextcloud's own do. */
export function toastError(text: string): void {
	showError(text)
}

/**
 * Something went well that can be taken back: the library's own Undo
 * button, which calls onUndo, staying twice as long as a success does,
 * since it has to be read before it can be used.
 */
export function toastUndo(text: string, onUndo: () => void): void {
	showUndo(text, () => onUndo(), { timeout: SUCCESS_TIMEOUT * 2 })
}
