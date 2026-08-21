/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Shared tab activation helpers for the settings pages.
 */

/**
 * Activate the tab with the given name, toggling the `fcias-tab` /
 * `fcias-tab-panel` aria/visibility state.
 */
export function activateTab(tab: string): void {
	document.querySelectorAll<HTMLButtonElement>('.fcias-tab').forEach((btn) => {
		const active = btn.dataset.tab === tab
		btn.classList.toggle('is-active', active)
		btn.setAttribute('aria-selected', active ? 'true' : 'false')
	})
	document.querySelectorAll<HTMLElement>('.fcias-tab-panel').forEach((panel) => {
		panel.hidden = panel.id !== `fcias-tab-panel-${tab}`
	})
}

/**
 * Resolve the active tab from `window.location.hash`.
 * Returns `alternateTab` when the hash matches it, otherwise `defaultTab`.
 */
export function tabFromHash(defaultTab: string, alternateTab: string): string {
	const tab = window.location.hash.replace(/^#/, '').split('/')[0]
	return tab === alternateTab ? alternateTab : defaultTab
}
