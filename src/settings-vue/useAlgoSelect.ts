/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Bridge that mounts the AlgoMultiselect Vue island into a vanilla DOM
 * container and exposes get/set helpers for the selected algorithm ids.
 */
import { createApp, type App } from 'vue'
import AlgoMultiselect from './AlgoMultiselect.vue'
import type { AlgoOption } from '../algorithms'

const selection = new Map<string, string[]>()
const apps = new Map<string, App>()

/**
 * Mount (or re-mount) an AlgoMultiselect into the container with the given id.
 */
export function mountAlgoSelect(containerId: string, options: AlgoOption[], initial: string[]): void {
	const container = document.getElementById(containerId)
	if (!container) {
		return
	}

	apps.get(containerId)?.unmount()
	selection.set(containerId, initial.slice())

	const app = createApp(AlgoMultiselect, {
		initial: initial.slice(),
		options,
		onChange: (value: string[]) => {
			selection.set(containerId, value)
		},
	})
	app.mount(container)
	apps.set(containerId, app)
}

/**
 * Read the currently selected algorithm ids for the given container.
 */
export function getAlgoSelection(containerId: string): string[] {
	return selection.get(containerId) ?? []
}
