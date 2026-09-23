<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Root component for the duplicates page.
 *
 * The page is a shell around three tabs. Two of them show the same listing
 * ({@see DuplicateListing}) and differ only in whose files it asks for —
 * one's own, or the accounts the picker names. Others is a tab of its
 * own rather than a switch inside the ordinary listing: it shows other
 * people's files, and that is not a state an ordinary view should slip into.
 */

import { computed, onMounted, ref, watch } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import DuplicateListing from './components/DuplicateListing.vue'
import TargetPicker from './components/TargetPicker.vue'
import DocsViewer from '../docs-vue/DocsViewer.vue'
import { OCS_ADMIN } from '../routes'
import { fetchAlgorithms } from '../algorithms'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import type { DuplicateScope } from './composables/useDuplicates'
import {
	fragmentFor,
	listingFromParams,
	parseFragment,
	scopeFromParams,
	type ListingParams,
	type Tab,
} from './urlState'

// The filter offers what the instance computes, read from the server: a
// list carried here would be wrong the day an administrator enabled an
// algorithm, and it was — this page listed seven of the eight the app
// always supported, and `adler32` could not be browsed for duplicates.
const algorithmIds = ref<string[]>([])

fetchAlgorithms()
	.then(({ algorithms }) => {
		algorithmIds.value = algorithms
	})
	.catch(() => {
		// The filter stays at "All algorithms"; the page still works.
	})

/** Whether the viewer may look across accounts; the ordinary listing says. */
const canSudo = ref(false)

// The address bar is the page's state: the tab, then its filters, page and
// — on Others — scope, as `#others?hash=…&all=1`. Read here before the
// listings mount, so the first load is already the one the URL asks for,
// and written back on every change, so the address can be shared.
const initial = parseFragment(window.location.hash)

/** What each listing is told to show; changed only by the URL. */
const mineParams = ref<ListingParams | null>(initial.tab === 'mine' ? listingFromParams(initial.params) : null)
const othersParams = ref<ListingParams | null>(initial.tab === 'others' ? listingFromParams(initial.params) : null)

/** What each listing last reported showing; what the address bar says. */
const mineState = ref<ListingParams | null>(null)
const othersState = ref<ListingParams | null>(null)

/** What the picker, or the URL, has named. */
const crossScope = ref<DuplicateScope>(
	initial.tab === 'others' ? scopeFromParams(initial.params) : { all: false, users: [], groups: [] },
)

/** Set once the password has been confirmed for this window. */
const confirmed = ref(false)

const activeTab = ref<Tab>('mine')

function fragmentOf(tab: Tab): string {
	if (tab === 'mine') {
		return fragmentFor('mine', mineState.value)
	}
	if (tab === 'others') {
		return fragmentFor('others', othersState.value, crossScope.value)
	}
	return fragmentFor('help')
}

/**
 * The address bar follows the active tab. Replaced, not pushed: a keystroke
 * in a filter is not a place to go back to. A tab change is, and pushes.
 */
function writeUrl(push = false): void {
	const next = fragmentOf(activeTab.value)
	if (next === window.location.hash) {
		return
	}
	if (push) {
		history.pushState(null, '', next)
	} else {
		history.replaceState(null, '', next)
	}
}

function onMineParams(params: ListingParams): void {
	mineState.value = params
	if (activeTab.value === 'mine') {
		writeUrl()
	}
}

function onOthersParams(params: ListingParams): void {
	othersState.value = params
	if (activeTab.value === 'others') {
		writeUrl()
	}
}

const tabs = computed<Array<{ id: Tab, label: string }>>(() => [
	{ id: 'mine', label: 'Mine' },
	...(canSudo.value ? [{ id: 'others' as Tab, label: 'Others' }] : []),
	{ id: 'help', label: 'Help' },
])

/**
 * Entering the Others tab costs the password, once per window — the
 * same confirmation the switch used to ask for. A dismissed dialog leaves
 * the viewer where they were, and puts the address back so it does not
 * claim a tab that is not open.
 *
 * A change the viewer clicked pushes, so the tabs walk back; one that came
 * from the address bar only normalises what is already there.
 */
async function setTab(tab: Tab, fromUrl = false): Promise<void> {
	if (tab === 'others' && !confirmed.value) {
		try {
			await confirmPassword()
			confirmed.value = true
		} catch (e) {
			writeUrl()
			return
		}
	}
	activeTab.value = tab
	writeUrl(!fromUrl)
}

/**
 * Show whatever the address names — the tab, its filters, its scope —
 * asking for the password if that is the Others tab. The address is safe
 * to honour: every cross-account read is authorised and confirmed
 * server-side, so arriving by URL reveals nothing on its own; it only
 * saves the viewer the clicks, which is what makes it shareable.
 *
 * Others is the one tab whose existence is not known at mount: it
 * appears only once the ordinary listing reports the viewer may look across
 * accounts. An address naming it is therefore held until that answer
 * arrives — its filters and scope are applied at once, so the tab opens
 * on them.
 */
const pendingTab = ref<Tab | null>(null)

function applyHash(): void {
	const { tab, params } = parseFragment(window.location.hash)

	if (tab === 'others') {
		othersParams.value = listingFromParams(params, algorithmIds.value)
		crossScope.value = scopeFromParams(params)
		if (canSudo.value) {
			setTab(tab, true)
		} else {
			// Not known yet, or not allowed. Held; the watcher below decides.
			pendingTab.value = tab
		}
		return
	}

	pendingTab.value = null
	if (tab === 'mine') {
		mineParams.value = listingFromParams(params, algorithmIds.value)
	}
	activeTab.value = tab
	writeUrl()
}

watch(canSudo, (allowed) => {
	if (allowed && pendingTab.value === 'others') {
		pendingTab.value = null
		setTab('others', true)
	}
})

function onScope(scope: DuplicateScope): void {
	crossScope.value = scope
	if (activeTab.value === 'others') {
		writeUrl()
	}
}

onMounted(() => {
	applyHash()
	// Back and forward between fragments, and an address typed by hand;
	// the page's own writes go through history and raise no event.
	window.addEventListener('hashchange', applyHash)
})
</script>

<template>
	<NcContent app-name="file_checksum_search">
		<NcAppContent :class="$style.content">
			<div class="db-wrap">
				<div class="db-tabs" role="tablist">
					<button
						v-for="tab in tabs"
						:key="tab.id"
						type="button"
						class="db-tab"
						:class="{ 'is-active': activeTab === tab.id }"
						role="tab"
						:aria-selected="activeTab === tab.id"
						:data-tab="tab.id"
						@click="setTab(tab.id)">
						{{ tab.label }}
					</button>
				</div>

				<!-- Kept alive rather than re-created: switching tabs should not
				     throw away the filters or the page you were on. -->
				<DuplicateListing
					v-show="activeTab === 'mine'"
					:algorithm-ids="algorithmIds"
					:params="mineParams"
					id-prefix="fcias-duplicates"
					@can-sudo="canSudo = $event"
					@update:params="onMineParams" />

				<div
					v-if="activeTab === 'others'"
					class="db-others"
					data-testid="fcias-others">
					<p class="db-others-note">
						These are other people's files. Everything below is shown because you
						asked for it by name — leave this tab to go back to your own.
					</p>
					<TargetPicker :scope="crossScope" @update:scope="onScope" />
					<DuplicateListing
						:scope="crossScope"
						:algorithm-ids="algorithmIds"
						:params="othersParams"
						id-prefix="fcias-others"
						empty-scope-text="Choose an account or a group above to see its duplicates."
						@update:params="onOthersParams" />
				</div>

				<div v-if="activeTab === 'help'" class="db-help">
					<DocsViewer :endpoint="OCS_ADMIN.getHelp" only="docs/user-guide.md" />
				</div>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style module>
.content {
	display: flex;
	justify-content: center;
	margin: 16px;
}
</style>

<style scoped>
.db-wrap {
	max-width: 960px;
	margin: 0 auto;
	padding: 16px;
	width: 100%;
}

.db-tabs {
	display: flex;
	gap: 4px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 16px;
}

.db-tab {
	background: transparent;
	border: none;
	border-bottom: 2px solid transparent;
	padding: 8px 14px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.db-tab.is-active {
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary);
}

.db-help {
	padding: 8px 0;
}

.db-others {
	background: var(--color-warning);
	color: var(--color-warning-text);
	border-radius: var(--border-radius-large, 12px);
	padding: 12px;
}

.db-others-note {
	margin: 0 0 12px;
	font-weight: 600;
	color: var(--color-warning-text);
}

.db-others :deep(.db-label) {
	color: var(--color-warning-text);
}

/* The picker spans the row: it can hold several accounts and groups at
   once, and each is a name long enough to be worth the width. It renders
   only in this tab, so the Mine tab's own controls keep their sizes. */
.db-field--targets {
	width: 100%;
	margin-bottom: 12px;
}
</style>
