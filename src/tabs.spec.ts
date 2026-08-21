import { beforeEach, describe, expect, it } from 'vitest'
import { activateTab, tabFromHash } from './tabs'

describe('tabFromHash', () => {
	it('returns the default tab for an unrelated hash', () => {
		window.location.hash = '#settings'
		expect(tabFromHash('settings', 'docs')).toBe('settings')
	})

	it('returns the alternate tab when the hash matches', () => {
		window.location.hash = '#docs'
		expect(tabFromHash('settings', 'docs')).toBe('docs')
	})
})

describe('activateTab', () => {
	beforeEach(() => {
		document.body.innerHTML = `
			<button class="fcias-tab is-active" data-tab="settings">Settings</button>
			<button class="fcias-tab" data-tab="docs">Docs</button>
			<div class="fcias-tab-panel" id="fcias-tab-panel-settings">S</div>
			<div class="fcias-tab-panel" id="fcias-tab-panel-docs" hidden>D</div>
		`
	})

	it('activates the target tab and hides the other panel', () => {
		activateTab('docs')

		const docsBtn = document.querySelector<HTMLButtonElement>('.fcias-tab[data-tab="docs"]')
		const settingsBtn = document.querySelector<HTMLButtonElement>('.fcias-tab[data-tab="settings"]')
		expect(docsBtn?.classList.contains('is-active')).toBe(true)
		expect(settingsBtn?.classList.contains('is-active')).toBe(false)

		const docsPanel = document.getElementById('fcias-tab-panel-docs') as HTMLElement
		const settingsPanel = document.getElementById('fcias-tab-panel-settings') as HTMLElement
		expect(docsPanel.hidden).toBe(false)
		expect(settingsPanel.hidden).toBe(true)
	})
})
