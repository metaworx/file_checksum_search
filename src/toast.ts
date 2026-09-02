/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The app's toasts, in one place.
 *
 * `OC.Notification.showTemporary()` is gone from Nextcloud 34's core bundles,
 * so calling it is a TypeError after the request has already succeeded — a
 * toast nobody sees and an error nobody reports. `@nextcloud/dialogs` is the
 * supported way; core ships the toast styles, so nothing is imported here
 * but the functions. One module so specs mock one module.
 */
import { showError, showSuccess } from '@nextcloud/dialogs'

/** Long enough to read, short enough not to need dismissing. */
const SUCCESS_TIMEOUT = 5000

/** A setting was saved: fired once the server has confirmed it, never before. */
export function toastSaved(text = 'Saved.'): void {
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
