/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The page against its address.
 *
 * happy-dom raises `hashchange` on `pushState` and `replaceState`, which a
 * browser does not: with the runner's own history, every address the page
 * wrote would come straight back to it as a navigation and be applied,
 * which hides exactly the defects these cases are about. The history is
 * therefore recorded and never walked, and a navigation is a hash set by
 * hand — which happy-dom, like a browser, announces.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import App from './App.vue'
import { resetAlgorithmCache } from '../algorithms'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...await importOriginal<typeof import('@nextcloud/router')>(),
	generateOcsUrl: (url: string) => url,
	generateUrl: (url: string) => url,
}))

const confirmPassword = vi.fn(() => Promise.resolve())
vi.mock('@nextcloud/password-confirmation', () => ({ confirmPassword: () => confirmPassword() }))
vi.mock('@nextcloud/password-confirmation/style.css', () => ({}))

// The Help tab is a place to click away to here, not a thing under test,
// and the viewer would fetch the guide on mount.
vi.mock('../docs-vue/DocsViewer.vue', () => ({
	default: { name: 'DocsViewer', template: '<div class="docs-stub" />' },
}))

function jsonResponse(body: unknown): Response {
	return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

/**
 * Every request the page makes, answered. The ordinary listing's answer
 * — the one that says whether Others is offered — can be held back, to
 * put a click before it.
 */
interface Server {
	requests: string[]
	releaseOwnListing: () => void
}

function serve(holdOwnListing = false): Server {
	const requests: string[] = []
	let release: () => void = () => {}
	const held = new Promise<void>((resolve) => {
		release = resolve
	})

	vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
		const url = String(input)
		requests.push(url)
		if (url.includes('/api/v1/algorithms')) {
			return jsonResponse({ algorithms: ['sha1', 'md5'], default: 'sha1' })
		}
		if (url.includes('/api/v1/sudo/selectable')) {
			return jsonResponse({ prefill: true, all: true, reach: 'everyone', groups: [], users: [] })
		}
		if (url.includes('/api/v1/sudo/duplicates')) {
			return jsonResponse({ duplicates: [] })
		}
		if (url.includes('/api/v1/duplicates')) {
			if (holdOwnListing) {
				await held
			}
			return jsonResponse({ duplicates: [], canSudo: true })
		}
		return jsonResponse({})
	})

	return { requests, releaseOwnListing: () => release() }
}

describe('duplicates App', () => {
	let wrapper: VueWrapper | null = null
	/** What the page wrote to the address bar, in order. */
	let written: string[] = []

	beforeEach(() => {
		written = []
		vi.spyOn(history, 'pushState').mockImplementation((_state, _unused, url) => {
			written.push(`push ${url}`)
		})
		vi.spyOn(history, 'replaceState').mockImplementation((_state, _unused, url) => {
			written.push(`replace ${url}`)
		})
	})

	afterEach(() => {
		wrapper?.unmount()
		wrapper = null
		window.location.hash = ''
		confirmPassword.mockClear()
		resetAlgorithmCache()
		vi.restoreAllMocks()
	})

	/** A tick of the runner's clock: what happy-dom announces a hash on. */
	const tick = () => new Promise((resolve) => setTimeout(resolve, 0))

	/**
	 * Mount on an address; the listings answer and, if offered, Others
	 * opens. The address is set, and happy-dom's announcement of it let
	 * pass, before the page mounts: a browser raises no `hashchange` for
	 * the address a page loads on, and one arriving late would re-read
	 * the address with the algorithm list already in — the very order the
	 * third case is about.
	 */
	async function open(hash: string, holdOwnListing = false): Promise<Server> {
		const server = serve(holdOwnListing)
		window.location.hash = hash
		await tick()
		wrapper = mount(App)
		await flushPromises()
		await flushPromises()
		return server
	}

	const hashField = () => wrapper!.find<HTMLInputElement>('#fcias-others-hash')

	/** The address bar changed under the page: back, forward, or typed. */
	async function navigate(hash: string): Promise<void> {
		window.location.hash = hash
		await tick()
		await flushPromises()
	}

	// The defect: the Others listing is created on entry from what the
	// address had said, while the address itself was written from what the
	// listing last reported. After a round trip through another tab the
	// page showed the older filters and the address the newer.
	it('reopens Others on the filters it last showed, not the ones the address opened it with', async () => {
		await open('#others?hash=abc')

		expect(hashField().element.value).toBe('abc')

		await hashField().setValue('abd')
		await flushPromises()

		expect(written.at(-1)).toBe('replace #others?hash=abd')

		await wrapper!.find('[data-tab="mine"]').trigger('click')
		await wrapper!.find('[data-tab="others"]').trigger('click')
		await flushPromises()

		expect(hashField().element.value).toBe('abd')
		// The click pushed the newer address. (What follows it in `written`
		// is the remounted listing reporting the same values back, which a
		// browser's address bar — unlike the recorded one — already held.)
		expect(written.filter((w) => w.startsWith('push')).at(-1)).toBe('push #others?hash=abd')
	})

	// The defect: an Others address is held until the listing says the tab
	// may be offered, and a click made in the meantime did not release it.
	// When the answer came, the viewer was pulled into Others and asked for
	// their password, away from the tab they had chosen.
	it('lets a click made before the listing answered stand', async () => {
		const { releaseOwnListing } = await open('#others?hash=abc', true)

		await wrapper!.find('[data-tab="help"]').trigger('click')

		expect(written.at(-1)).toBe('push #help')

		releaseOwnListing()
		await flushPromises()
		await flushPromises()

		expect(wrapper!.find('[data-tab="others"]').exists()).toBe(true)
		expect(wrapper!.find('[data-tab="help"]').attributes('aria-selected')).toBe('true')
		expect(confirmPassword).not.toHaveBeenCalled()
		expect(written.at(-1)).toBe('push #help')
	})

	// The defect: the list of algorithms the instance computes arrives
	// before Others opens, and the listing dropped an unknown one only when
	// the list arrived after it. An address naming `algo=bogus` was asked of
	// the server as it stood and written back as it stood.
	it('drops an algorithm the instance does not compute from an Others address at load', async () => {
		// The order that matters, made certain: the list is in before the
		// listing that says whether Others is offered has answered.
		const { requests, releaseOwnListing } = await open('#others?hash=abc&algo=bogus&all=1', true)
		releaseOwnListing()
		await flushPromises()
		await flushPromises()

		const listed = requests.filter((url) => url.includes('/api/v1/sudo/duplicates'))

		expect(listed.length).toBeGreaterThan(0)
		expect(listed.at(-1)).not.toContain('algo=')
		expect(written.at(-1)).toBe('replace #others?hash=abc&all=1')
	})

	// A navigation — back, forward, an address typed — is honoured: the
	// page reads it, and normalises it rather than pushing it again.
	it('follows the address bar when it changes under it', async () => {
		await open('#mine')
		await navigate('#mine?hash=abc&minCount=3')

		expect(wrapper!.find<HTMLInputElement>('#fcias-duplicates-hash').element.value).toBe('abc')
		expect(wrapper!.find<HTMLInputElement>('#fcias-duplicates-min').element.value).toBe('3')
		expect(written.filter((w) => w.startsWith('push'))).toEqual([])
	})
})
