/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The one wait the helpers in this directory use.
 *
 * A menu is not where it will be the moment it is asked for. NcSelect
 * places its menu through floating-ui's `computePosition`, a promise that
 * reads the control's toggle when it resolves; NcActions' popover appends
 * its menu to `document.body` on the next turn of the queue. A spec that
 * reads, closes or unmounts before then reads nothing, or leaves a promise
 * to reject on a ref that is gone — harmless in a browser, an unhandled
 * rejection in the runner. Microtasks, one turn of the macrotask queue,
 * microtasks again is what it takes for both.
 */
import { flushPromises } from '@vue/test-utils'

export async function settle(): Promise<void> {
	await flushPromises()
	await new Promise((resolve) => setTimeout(resolve, 0))
	await flushPromises()
}
