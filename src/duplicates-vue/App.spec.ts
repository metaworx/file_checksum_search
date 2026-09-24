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
 * put a click before it, and can say no.
 */
interface Server {
	requests: string[]
	releaseOwnListing: () => void
}

interface ServerOptions {
	holdOwnListing?: boolean
	canSudo?: boolean
	/** What the cross-account listing answers. */
	sudoGroups?: unknown[]
}

function serve({ holdOwnListing = false, canSudo = true, sudoGroups = [] }: ServerOptions = {}): Server {
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
			return jsonResponse({ duplicates: sudoGroups })
		}
		if (url.includes('/api/v1/duplicates')) {
			if (holdOwnListing) {
				await held
			}
			return jsonResponse({ duplicates: [], canSudo })
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
	async function open(hash: string, options: ServerOptions = {}): Promise<Server> {
		const server = serve(options)
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
		const { releaseOwnListing } = await open('#others?hash=abc', { holdOwnListing: true })

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
		const { requests, releaseOwnListing } = await open('#others?hash=abc&algo=bogus&all=1', { holdOwnListing: true })
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

	// The defect: an Others address held for a viewer who may not cross was
	// held for good — the listing reported `canSudo` only when it changed,
	// and false is where it starts. The address stayed `#others?…` over the
	// Mine tab. A "no" now releases it and puts the address back.
	it('puts the address back to Mine when the viewer may not cross', async () => {
		await open('#others?hash=abc&all=1', { canSudo: false })

		expect(wrapper!.find('[data-tab="others"]').exists()).toBe(false)
		expect(wrapper!.find('[data-tab="mine"]').attributes('aria-selected')).toBe('true')
		expect(confirmPassword).not.toHaveBeenCalled()
		expect(written.at(-1)).toBe('replace #mine')
	})

	// The defect: every `hashchange` on Others assigned a new scope object,
	// equal or not, and the listing reloaded from the first page for it.
	it('does not reload Others for a navigation to the scope it already shows', async () => {
		const { requests } = await open('#others?hash=abc&all=1')
		const listed = () => requests.filter((url) => url.includes('/api/v1/sudo/duplicates')).length
		const before = listed()

		expect(before).toBeGreaterThan(0)

		await navigate('#others?hash=abc&all=1&offset=0')

		expect(listed()).toBe(before)
	})

	// The defect: a limit typed over the same limit — or one clamped back
	// to it — reloaded the listing all the same.
	it('does not reload for a limit that clamps to what it already is', async () => {
		const { requests } = await open('#mine?limit=500')
		const listed = () => requests.filter((url) => url.includes('/api/v1/duplicates')).length
		const before = listed()

		await wrapper!.find('#fcias-duplicates-limit').setValue('9999')
		await flushPromises()

		expect(listed()).toBe(before)

		await wrapper!.find('#fcias-duplicates-limit').setValue('10')
		await flushPromises()

		expect(listed()).toBe(before + 1)
	})

	// "Not in your files" was a `title` alone: a pointer's fact, and a
	// keyboard's or a screen reader's never.
	it('says a file is not the viewer\'s in text, not only in a title', async () => {
		await open('#others?hash=abc&all=1', {
			sudoGroups: [{
				algo: 'sha1',
				hash_value: 'abc123',
				file_count: 2,
				files: [
					{ fileid: 1, path: 'Docs', name: 'a.pdf', owner: 'me', location: '/me/files/Docs/a.pdf', openable: true },
					{ fileid: 2, path: 'Docs', name: 'a.pdf', owner: 'bob', location: '/bob/files/Docs/a.pdf', openable: false },
				],
			}],
		})

		await wrapper!.find('[data-testid="fcias-others"] .db-group-header').trigger('click')

		const unopenable = wrapper!.find('.db-file-unopenable')

		expect(unopenable.attributes('title')).toBe('Not in your files')
		expect(unopenable.find('.hidden-visually').text()).toContain('not in your files')
	})
})
