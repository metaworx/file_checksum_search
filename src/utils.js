/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Shared utility functions for FCIAS frontend components.
 */

/**
 * Escape HTML entities in a string for safe insertion into DOM.
 *
 * @param {*} str - Value to escape (non-strings are coerced)
 * @returns {string} HTML-escaped string
 */
export function escapeHtml( str ) {
	const div = document.createElement( 'div' )
	div.appendChild( document.createTextNode( String( str ?? '' ) ) )
	return div.innerHTML
}
