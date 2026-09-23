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
