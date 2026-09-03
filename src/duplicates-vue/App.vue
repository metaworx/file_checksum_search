<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Root component for the duplicates page.
 *
 * The page is a shell around three tabs. Two of them show the same listing
 * ({@see DuplicateListing}) and differ only in whose files it asks for —
 * one's own, or the accounts the picker names. Cross-account is a tab of its
 * own rather than a switch inside the ordinary listing: it shows other
 * people's files, and that is not a state an ordinary view should slip into.
 */

import { computed, onMounted, ref } from 'vue'
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

type Tab = 'duplicates' | 'crossaccount' | 'help'

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

/** What the picker has named. Never persisted — a reload starts over. */
const crossScope = ref<DuplicateScope>({ all: false, users: [], groups: [] })

/** Set once the password has been confirmed for this window. */
const confirmed = ref(false)

const activeTab = ref<Tab>('duplicates')

const tabs = computed<Array<{ id: Tab, label: string }>>(() => [
	{ id: 'duplicates', label: 'Duplicates' },
	...(canSudo.value ? [{ id: 'crossaccount' as Tab, label: 'Cross-account' }] : []),
	{ id: 'help', label: 'Help' },
])

function tabFromHash(): Tab {
	const tab = window.location.hash.replace(/^#/, '').split('/')[0]
	return tab === 'help' || tab === 'crossaccount' ? tab : 'duplicates'
}

/**
 * Entering the cross-account tab costs the password, once per window — the
 * same confirmation the switch used to ask for. A dismissed dialog leaves
 * the viewer where they were.
 */
async function setTab(tab: Tab): Promise<void> {
	if (tab === 'crossaccount' && !confirmed.value) {
		try {
			await confirmPassword()
			confirmed.value = true
		} catch (e) {
			return
		}
	}
	activeTab.value = tab
	window.location.hash = tab
}

function onScope(scope: DuplicateScope): void {
	crossScope.value = scope
}

onMounted(() => {
	const wanted = tabFromHash()
	// A reload straight into the cross-account tab still asks: the hash is
	// not a credential.
	if (wanted === 'crossaccount') {
		activeTab.value = 'duplicates'
	} else {
		activeTab.value = wanted
	}
	window.addEventListener('hashchange', () => {
		const next = tabFromHash()
		if (next !== 'crossaccount' || confirmed.value) {
			activeTab.value = next
		}
	})
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
					v-show="activeTab === 'duplicates'"
					:algorithm-ids="algorithmIds"
					id-prefix="fcias-duplicates"
					@can-sudo="canSudo = $event" />

				<div
					v-if="activeTab === 'crossaccount'"
					class="db-xaccount"
					data-testid="fcias-crossaccount">
					<p class="db-xaccount-note">
						These are other people's files. Everything below is shown because you
						asked for it by name — leave this tab to go back to your own.
					</p>
					<TargetPicker @update:scope="onScope" />
					<DuplicateListing
						:scope="crossScope"
						:algorithm-ids="algorithmIds"
						id-prefix="fcias-xaccount"
						empty-scope-text="Choose an account or a group above to see its duplicates." />
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

/* One row where there is room, wrapping where there is not; everything
   sits on the fields' bottom edge, the labels above their fields. */

/* NcSelect's own minimum is 260px; a narrower wrapper is overflowed, and the
   chevron ends up under the next field. */

/* The help button is sized for a settings row; beside a small label it is
   trimmed to the label's height so the row does not grow around it. */

/* The instance-wide view, while it is on: red, so a page of everyone's
   files is never mistaken for one's own. On the rounded content box inside
   the switch — the one that carries the radius and the hover background —
   not on the square outer box, whose corners showed behind it. The tripled
   class outweighs the component's own checked-and-hovered rule, which
   stacks two :not() on the outer box. */

/* The toggle's track is a path filled from a colour the icon component
   sets on itself, so the wrapper's colour never reaches it; the fill is
   set on the path. The knob keeps its own fill and stays readable. */

/* Cross-account: other people's files, on an amber ground so the tab can
   never be mistaken for one's own listing. Amber rather than the error red
   the old switch used — this is a state to notice, not a fault. */
.db-xaccount {
	background: var(--color-warning);
	color: var(--color-warning-text);
	border-radius: var(--border-radius-large, 12px);
	padding: 12px;
}

.db-xaccount-note {
	margin: 0 0 12px;
	font-weight: 600;
	color: var(--color-warning-text);
}

.db-xaccount :deep(.db-label) {
	color: var(--color-warning-text);
}

.db-field--targets {
	width: 100%;
	max-width: 420px;
	margin-bottom: 12px;
}
</style>
