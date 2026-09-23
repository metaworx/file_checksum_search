/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Driving a real NcActions menu from a spec, the way a person does.
 *
 * NcActions renders a toggle button and, once that is clicked, a menu the
 * popover appends to `document.body` on the next turn of the queue — never
 * inside the component's own markup. The toggle names the menu in
 * `aria-controls`, so the menu of one row is never mistaken for another's.
 * The app marks each entry with `data-action` on the `NcActionButton`,
 * which lands on the `<li>`; the button is inside it. An inline button the
 * app puts beside the menu — the pen — carries `data-action` itself.
 *
 * Every spec that used to click a `<button>` a stand-in menu had rendered
 * flat drives the menu through these instead.
 */
import type { DOMWrapper, VueWrapper } from '@vue/test-utils'
import { flushPromises } from '@vue/test-utils'

type AnyWrapper = VueWrapper | DOMWrapper<Element>

/** One turn of the macrotask queue: when the popover has placed the menu. */
async function nextTurn(): Promise<void> {
	await flushPromises()
	await new Promise((resolve) => setTimeout(resolve, 0))
}

/** Open the menu behind this wrapper's toggle and return it. */
export async function openActions(wrapper: AnyWrapper): Promise<HTMLElement> {
	const toggle = wrapper.find('.action-item__menutoggle')
	if (!toggle.exists()) {
		throw new Error('no NcActions toggle in this wrapper')
	}
	if (toggle.attributes('aria-expanded') !== 'true') {
		await toggle.trigger('click')
		await nextTurn()
	}
	const id = toggle.attributes('aria-controls')
	const menu = id ? document.getElementById(id) : null
	if (!menu) {
		throw new Error('the NcActions menu did not open')
	}
	return menu
}

/** Whether the row offers this action at all — inline, or in its menu. */
export async function hasAction(wrapper: AnyWrapper, action: string): Promise<boolean> {
	if (wrapper.find(`button[data-action="${action}"]`).exists()) {
		return true
	}
	if (!wrapper.find('.action-item__menutoggle').exists()) {
		return false
	}
	const menu = await openActions(wrapper)
	return menu.querySelector(`[data-action="${action}"] button, button[data-action="${action}"]`) !== null
}

/** Click this action: the inline button where there is one, else the menu's entry. */
export async function clickAction(wrapper: AnyWrapper, action: string): Promise<void> {
	const inline = wrapper.find(`button[data-action="${action}"]`)
	if (inline.exists()) {
		await inline.trigger('click')
		return
	}
	const menu = await openActions(wrapper)
	const button = menu.querySelector<HTMLElement>(`[data-action="${action}"] button, button[data-action="${action}"]`)
	if (!button) {
		throw new Error(`no action "${action}" in the open menu`)
	}
	button.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
	await flushPromises()
}
