/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Composable for copy-to-clipboard with an auto-resetting "copied" flag.
 */
import { ref } from 'vue'

export function useClipboard(resetAfterMs = 2000) {
	const copied = ref(false)
	let timer: number | undefined

	async function copyToClipboard(text: string): Promise<void> {
		try {
			await navigator.clipboard.writeText(text)
			copied.value = true
			window.clearTimeout(timer)
			timer = window.setTimeout(() => {
				copied.value = false
			}, resetAfterMs)
		} catch {
			// Clipboard unavailable — ignore.
		}
	}

	return { copied, copyToClipboard }
}
