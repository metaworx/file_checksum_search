/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Driving a real NcSelect from a spec, the way a person does.
 *
 * NcSelect is vue-select underneath. Its menu opens on a keyboard arrow in
 * the search field — the same path a mouse takes, without the mousedown
 * bookkeeping that a synthetic event in the runner does not carry — and is
 * appended to `document.body`, not rendered beside the control, so that is
 * where the options are found. An option selects on click. What is
 * selected renders as `.vs__selected` inside the control.
 *
 * Every spec that used to drive a stand-in `NcSelect` drives the control
 * through these instead, so the stand-ins could go.
 */
import type { DOMWrapper, VueWrapper } from '@vue/test-utils'
import { flushPromises } from '@vue/test-utils'

type AnyWrapper = VueWrapper | DOMWrapper<Element>

/** The search field of the control, by its input id where several share a page. */
function searchInput(wrapper: AnyWrapper, inputId?: string): DOMWrapper<Element> {
	return inputId ? wrapper.find(`#${inputId}`) : wrapper.find('input.vs__search')
}

/**
 * Open the menu and return its options, wherever vue-select put them.
 *
 * The arrow opens a closed menu and, on an open one, selects the highlighted
 * option — so it is pressed only while the menu is closed. The menu is found
 * by the id the search field names in `aria-controls`, so two controls on
 * one page, or one left open by an earlier test, cannot answer for another.
 */
export async function openSelect(wrapper: AnyWrapper, inputId?: string): Promise<HTMLElement[]> {
	const input = searchInput(wrapper, inputId)
	const listbox = input.attributes('aria-controls')
	if (!listbox) {
		throw new Error('not an NcSelect search field: no aria-controls')
	}
	if (input.attributes('aria-expanded') !== 'true') {
		await input.trigger('keydown', { keyCode: 40 })
		await settle()
	}
	return Array.from(document.body.querySelectorAll<HTMLElement>(`#${CSS.escape(listbox)} li.vs__dropdown-option`))
}

/**
 * Let the menu's positioning land. NcSelect places the menu through
 * floating-ui's `computePosition`, a promise that reads the control's
 * toggle when it resolves; a spec that closes the menu or unmounts before
 * then leaves that promise to reject on a ref that is gone — harmless in a
 * browser, an unhandled rejection in the runner. Two turns of the macrotask
 * queue are what it takes.
 */
async function settle(): Promise<void> {
	await flushPromises()
	await flushPromises()
}

/** The labels the open menu offers. */
export async function optionLabels(wrapper: AnyWrapper, inputId?: string): Promise<string[]> {
	return (await openSelect(wrapper, inputId)).map((li) => li.textContent?.trim() ?? '')
}

/** Open the menu and click the option with this label. */
export async function pickOption(wrapper: AnyWrapper, label: string, inputId?: string): Promise<void> {
	const option = (await openSelect(wrapper, inputId)).find((li) => li.textContent?.trim() === label)
	if (!option) {
		throw new Error(`no option "${label}" in the open NcSelect`)
	}
	option.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
	await settle()
}

/** Type into the search field, as a person searching would. */
export async function typeToSearch(wrapper: AnyWrapper, text: string, inputId?: string): Promise<void> {
	await searchInput(wrapper, inputId).setValue(text)
	await flushPromises()
}

/** What the control shows as selected, in the order it shows it. */
export function selectedLabels(wrapper: AnyWrapper): string[] {
	return wrapper.findAll('.vs__selected').map((el) => el.text().trim())
}
