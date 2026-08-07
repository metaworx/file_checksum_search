/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Shared utility functions for FCIAS frontend components.
 */

/**
 * Escape HTML entities in a value for safe insertion into DOM.
 *
 * @param val - Value to escape (non-strings are coerced)
 * @returns HTML-escaped string
 */
export function escapeHtml(val: unknown): string {
	const div = document.createElement('div')
	div.appendChild(document.createTextNode(String(val ?? '')))
	return div.innerHTML
}
