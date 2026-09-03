<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Root component for the duplicates page.
 */

import { ref, computed, watch, onMounted } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AlgorithmSelect from '../components/AlgorithmSelect.vue'
import HelpPopover from '../components/HelpPopover.vue'
import DuplicateGroup from './components/DuplicateGroup.vue'
import VerifyButton from './components/VerifyButton.vue'
import { useDuplicates } from './composables/useDuplicates'
import type { DuplicateGroup as GroupType } from './composables/useDuplicates'
import DocsViewer from '../docs-vue/DocsViewer.vue'
import { OCS_ADMIN } from '../routes'
import { type AlgoOption, fetchAlgorithms } from '../algorithms'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'

const {
	canSudo,
	showAll,
	algo,
	minCount,
	limit,
	offset,
	groups,
	loading,
	hasMore,
	verifying,
	error,
	load,
	verifyGroups,
	fileUrl,
	resetOffset,
	prevPage,
	nextPage,
} = useDuplicates()

/**
 * The instance-wide view is a thing you switch to, each time. Core's own
 * dialog asks for the password and holds the confirmation for thirty
 * minutes; a dismissed dialog leaves the switch off.
 */
async function onShowAll(on: boolean): Promise<void> {
	if (on) {
		try {
			await confirmPassword()
		} catch (e) {
			return
		}
	}
	showAll.value = on
	resetOffset()
	await load()
}

// The filter offers what the instance computes, read from the server: a
// list carried here would be wrong the day an administrator enabled an
// algorithm, and it was — this page listed seven of the eight the app
// always supported, and `adler32` could not be browsed for duplicates.
const ALL_ALGORITHMS: AlgoOption = { id: '', label: 'All algorithms' }
const algorithmIds = ref<string[]>([])

fetchAlgorithms()
	.then(({ algorithms }) => {
		algorithmIds.value = algorithms
	})
	.catch(() => {
		// The filter stays at "All algorithms"; the page still works.
	})

/** What each control decides, for the help button beside its label. */
const HELP = {
	algo: 'Only groups of this algorithm, or every algorithm at once. The list is what this '
		+ 'server computes; an algorithm nobody has enabled is not offered.',
	min: 'The smallest group to list: how many files must share a checksum before they count '
		+ 'as duplicates. Two is every duplicate; a higher number finds the widely copied ones.',
	limit: 'How many groups one page shows. Verify hashes recomputes every file on the page, '
		+ 'and recomputation is rate limited, so a smaller page verifies in one go.',
}

// NcTextField emits string | number; the composable wants a bounded integer.
function bounded(value: string | number, min: number, max: number, fallback: number): number {
	const n = Math.trunc(Number(value))
	return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : fallback
}

const verifiedOnly = ref(false)
const activeTab = ref<'duplicates' | 'help'>('duplicates')

function tabFromHash(): 'duplicates' | 'help' {
	const tab = window.location.hash.replace(/^#/, '').split('/')[0]
	return tab === 'help' ? 'help' : 'duplicates'
}

function setTab(tab: 'duplicates' | 'help'): void {
	activeTab.value = tab
	window.location.hash = tab
}

const filteredGroups = computed<GroupType[]>(() => {
	if (!verifiedOnly.value) return groups.value
	return groups.value.filter((g) => (g.mismatch_count ?? 0) === 0)
})

const hasVerified = computed(() =>
	groups.value.every((g) => g.match_count !== undefined && g.mismatch_count !== undefined),
)

watch([algo, minCount, limit], () => {
	resetOffset()
	load()
})

watch(offset, () => {
	load()
})

function refresh(): void {
	resetOffset()
	load()
}

async function onVerify(): Promise<void> {
	await verifyGroups(groups.value)
}

onMounted(() => {
	activeTab.value = tabFromHash()
	window.addEventListener('hashchange', () => {
		activeTab.value = tabFromHash()
	})
	load()
})
</script>

<template>
	<NcContent app-name="file_checksum_search">
		<NcAppContent :class="$style.content">
			<div class="db-wrap">
				<div class="db-tabs" role="tablist">
					<button
						type="button"
						class="db-tab"
						:class="{ 'is-active': activeTab === 'duplicates' }"
						role="tab"
						:aria-selected="activeTab === 'duplicates'"
						@click="setTab('duplicates')">
						Duplicates
					</button>
					<button
						type="button"
						class="db-tab"
						:class="{ 'is-active': activeTab === 'help' }"
						role="tab"
						:aria-selected="activeTab === 'help'"
						@click="setTab('help')">
						Help
					</button>
				</div>

				<template v-if="activeTab === 'duplicates'">
					<!-- Labelled columns that flow on one row where there is room. The
					     labels are the page's own: NcTextField with label-outside renders
					     none, and drops its class on the input rather than its root, which
					     is why each field is sized by the wrapper around it. -->
					<div class="db-controls">
						<div class="db-field db-field--algo">
							<span class="db-label">
								<label for="fcias-duplicates-algorithm">Algorithm</label>
								<HelpPopover :text="HELP.algo" label="Algorithm" />
							</span>
							<AlgorithmSelect
								v-model="algo"
								:algorithms="algorithmIds"
								:leading="ALL_ALGORITHMS"
								input-id="fcias-duplicates-algorithm"
								label="Algorithm" />
						</div>
						<div class="db-field db-field--narrow">
							<span class="db-label">
								<label for="fcias-duplicates-min">Min</label>
								<HelpPopover :text="HELP.min" label="Min" />
							</span>
							<NcTextField
								id="fcias-duplicates-min"
								:model-value="minCount"
								type="number"
								label="Min"
								label-outside
								min="2"
								max="100"
								title="Smallest group to list: files sharing a checksum, 2 to 100"
								@update:model-value="minCount = bounded($event, 2, 100, 2)" />
						</div>
						<div class="db-field db-field--narrow">
							<span class="db-label">
								<label for="fcias-duplicates-limit">Limit</label>
								<HelpPopover :text="HELP.limit" label="Limit" />
							</span>
							<NcTextField
								id="fcias-duplicates-limit"
								:model-value="limit"
								type="number"
								label="Limit"
								label-outside
								min="1"
								max="500"
								title="Groups per page, 1 to 500"
								@update:model-value="limit = bounded($event, 1, 500, 50)" />
						</div>
						<div class="db-actions">
							<NcButton variant="primary" @click="refresh">
								Refresh
							</NcButton>
							<VerifyButton :verifying="verifying" :has-verified="hasVerified" @verify="onVerify" />
							<!-- The wrapper carries the test hook: the component does not put attributes on an ancestor of its input. -->
							<span data-testid="fcias-only-matching">
								<NcCheckboxRadioSwitch
									v-model="verifiedOnly"
									title="Show only groups where all files were confirmed matching">
									Only matching
								</NcCheckboxRadioSwitch>
							</span>
							<!-- Only for a viewer the listing reports may look across accounts.
							     On costs a password; off, or a reload, is back to one's own files.
							     Red while on: it is the one control that changes whose files are
							     shown, and that must not be missed. -->
							<span
								v-if="canSudo"
								data-testid="fcias-show-all"
								class="db-sudo"
								:class="{ 'db-sudo-on': showAll }">
								<NcCheckboxRadioSwitch
									:model-value="showAll"
									type="switch"
									:title="showAll
										? 'Showing every account\'s duplicates — switch off to see only your own'
										: 'Every account\'s duplicates, after confirming your password'"
									@update:model-value="onShowAll">
									Show all users
								</NcCheckboxRadioSwitch>
							</span>
						</div>
					</div>

					<div class="db-scroll">
						<div v-if="loading" class="db-loading">
							Searching …
						</div>
						<div v-else-if="error" class="db-error">
							{{ error }}
						</div>
						<div v-else-if="filteredGroups.length === 0" class="db-empty">
							{{ groups.length === 0 ? 'No duplicate files found.' : 'No matching duplicate files found.' }}
						</div>
						<DuplicateGroup
							v-for="(group, idx) in filteredGroups"
							:key="`${group.algo}-${group.hash_value}-${idx}`"
							:group="group"
							:file-url="fileUrl" />
					</div>

					<div class="db-pagination">
						<NcButton v-if="offset > 0" @click="prevPage">
							← Previous
						</NcButton>
						<NcButton v-if="hasMore" @click="nextPage">
							Next →
						</NcButton>
					</div>
				</template>

				<div v-else class="db-help">
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
.db-controls {
	display: flex;
	gap: 8px 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
	/* The labels line up along the top; the select is a few pixels taller
	   than a text field, and that difference is better hidden at the bottom. */
	align-items: flex-start;
}

.db-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

/* NcSelect's own minimum is 260px; a narrower wrapper is overflowed, and the
   chevron ends up under the next field. */
.db-field--algo {
	width: 260px;
}

.db-field--algo :deep(.v-select.select) {
	min-width: 0;
	width: 100%;
}

.db-field--narrow {
	width: 100px;
}

.db-label {
	display: flex;
	align-items: center;
	gap: 2px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

/* The help button is sized for a settings row; beside a small label it is
   trimmed to the label's height so the row does not grow around it. */
.db-label :deep(.fcias-help-icon) {
	width: 24px;
	height: 24px;
	min-width: 24px;
	min-height: 24px;
}

.db-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	align-items: center;
	/* On the fields' bottom edge, the labels being above the fields. */
	align-self: flex-end;
}

.db-sudo {
	display: inline-flex;
}

/* The instance-wide view, while it is on: red, so a page of everyone's
   files is never mistaken for one's own. On the rounded content box inside
   the switch — the one that carries the radius and the hover background —
   not on the square outer box, whose corners showed behind it. The tripled
   class outweighs the component's own checked-and-hovered rule, which
   stacks two :not() on the outer box. */
.db-sudo-on :deep(.checkbox-radio-switch__content) {
	background-color: var(--color-error);
}

.db-sudo-on :deep(.checkbox-radio-switch--checked .checkbox-radio-switch__content.checkbox-radio-switch__content.checkbox-radio-switch__content:hover),
.db-sudo-on :deep(.checkbox-radio-switch--checked:focus-within .checkbox-radio-switch__content.checkbox-radio-switch__content.checkbox-radio-switch__content) {
	background-color: var(--color-error-hover, var(--color-error));
}

.db-sudo-on :deep(.checkbox-radio-switch__text),
.db-sudo-on :deep(.checkbox-radio-switch__icon) {
	color: var(--color-error-text);
}

/* The toggle's track is a path filled from a colour the icon component
   sets on itself, so the wrapper's colour never reaches it; the fill is
   set on the path. The knob keeps its own fill and stays readable. */
.db-sudo-on :deep(.checkbox-radio-switch__icon svg path) {
	fill: var(--color-error-text);
}

.db-scroll {
	max-height: calc(100vh - 180px);
	overflow-y: auto;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.db-pagination {
	display: flex;
	gap: 8px;
	justify-content: center;
	margin-top: 16px;
}

.db-empty,
.db-loading {
	text-align: center;
	padding: 32px;
	color: var(--color-text-maxcontrast);
}

.db-error {
	/* The palette's text red; --color-error is the background one. */
	color: var(--color-error-text);
	text-align: center;
	padding: 16px;
}
</style>
