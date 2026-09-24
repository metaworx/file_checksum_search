/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import HelpPopover from './HelpPopover.vue'

/**
 * The real NcPopover renders its content only once opened, and puts it in
 * the body on the next turn of the queue — where a person reads it after a
 * click on the trigger, and where this spec reads it too.
 */
async function openHelp(wrapper: VueWrapper): Promise<HTMLElement | null> {
	await wrapper.find('button').trigger('click')
	await flushPromises()
	await new Promise((resolve) => setTimeout(resolve, 0))
	return document.body.querySelector<HTMLElement>('.fcias-help-body')
}

describe('HelpPopover', () => {
	let wrapper: VueWrapper | null = null

	afterEach(() => {
		wrapper = null
	})

	it('renders a labelled trigger, and the help text once opened', async () => {
		wrapper = mount(HelpPopover, { props: { text: 'What this does.', label: 'Mode' } })
		expect(wrapper.find('button').attributes('aria-label')).toBe('Help: Mode')
		expect(document.body.querySelector('.fcias-help-body')).toBeNull()

		const body = await openHelp(wrapper)
		expect(body?.textContent).toContain('What this does.')
	})

	it('renders nothing without a help text', () => {
		wrapper = mount(HelpPopover, { props: { label: 'Mode' } })
		expect(wrapper.find('button').exists()).toBe(false)
	})

	it('escapes the help text instead of treating it as markup', async () => {
		wrapper = mount(HelpPopover, { props: { text: '<b>bold</b>', label: 'Path' } })
		const body = await openHelp(wrapper)
		expect(body).not.toBeNull()
		expect(body?.childElementCount).toBe(0)
		expect(body?.textContent).toBe('<b>bold</b>')
	})
})
