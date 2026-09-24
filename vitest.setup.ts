/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What the runner's DOM must not do for the real Nextcloud components.
 *
 * happy-dom ships a ResizeObserver whose callbacks fire on their own clock.
 * NcSelect positions its menu through floating-ui's `autoUpdate`, which
 * subscribes to one and recomputes the position — a promise that reads the
 * control's toggle when it resolves. In a spec the menu is closed, or the
 * page unmounted, long before that clock ticks, and the promise then rejects
 * on a ref that is gone: an unhandled rejection the suite reports and no
 * assertion caused. No spec asserts on where a menu is placed, so the
 * observer here observes nothing; the first placement still runs, once,
 * from `autoUpdate`'s own initial call.
 */
import { afterEach } from 'vitest'
import { config, enableAutoUnmount } from '@vue/test-utils'

class QuietResizeObserver {
	observe(): void {}

	unobserve(): void {}

	disconnect(): void {}
}

Object.defineProperty(globalThis, 'ResizeObserver', {
	configurable: true,
	writable: true,
	value: QuietResizeObserver,
})

// A dialog's content is teleported to the body, where `wrapper.find()` does
// not look. Rendered in place instead, it is found where the spec mounted
// it, and a spec that reads a form inside NcDialog reads it as before. The
// popovers NcActions and NcPopover open do not go through Teleport and
// still land in the body; the helpers in src/test-utils/ know where.
config.global.stubs = { ...config.global.stubs, teleport: true }

// Every wrapper a spec mounts is unmounted after its test: the real
// components register document listeners and append menus to the body, and
// a page left mounted keeps answering events the next test raises. What a
// component cached in the session store — NcSettingsSelectGroup keeps the
// group list there — goes with it.
enableAutoUnmount(afterEach)

afterEach(() => {
	try {
		window.sessionStorage.clear()
	} catch {
		// A runner without one; nothing to clear.
	}
})
