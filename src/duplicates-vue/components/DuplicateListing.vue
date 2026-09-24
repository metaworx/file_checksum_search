<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * One duplicates listing: the filters, the groups, the pages.
 *
 * Written once and rendered twice — the Duplicates tab passes no scope and
 * gets one's own files; the Others tab passes the accounts the
 * picker named. Everything below the picker is identical between them, so
 * it lives here rather than being copied per tab, and each instance owns
 * its own {@see useDuplicates} state so switching tabs does not reset the
 * other's filters.
 */
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AlgorithmSelect from '../../components/AlgorithmSelect.vue'
import HelpPopover from '../../components/HelpPopover.vue'
import DuplicateGroup from './DuplicateGroup.vue'
import { useDuplicates, type DuplicateScope, type DuplicateGroup as GroupType } from '../composables/useDuplicates'
import { sameParams, type ListingParams } from '../urlState'
import { type AlgoOption } from '../../algorithms'

const props = withDefaults(
	defineProps<{
		/** Whose files to list; null is the viewer's own. */
		scope?: DuplicateScope | null
		/** Algorithm ids the instance computes, loaded once by the page. */
		algorithmIds: string[]
		/** Shown in place of the listing while no target is named. */
		emptyScopeText?: string
		/**
		 * Prefix for this instance's control ids. Two listings render at
		 * once, and an id may not repeat in a document — the labels point at
		 * their own fields through it.
		 */
		idPrefix?: string
		/**
		 * The filters and the page to show, from the URL. Applied when
		 * given and whenever it changes; what the controls then do is
		 * reported back through `update:params`, so the URL can follow.
		 */
		params?: ListingParams | null
	}>(),
	{
		scope: null,
		emptyScopeText: '',
		idPrefix: 'fcias-duplicates',
		params: null,
	},
)

const emit = defineEmits<{
	(e: 'canSudo', value: boolean): void
	(e: 'update:params', value: ListingParams): void
}>()

const ALL_ALGORITHMS: AlgoOption = { id: '', label: 'All algorithms' }

/** What each control decides, for the help button beside its label. */
const HELP = {
	algo: 'Only groups of this algorithm, or every algorithm at once. The list is what this '
		+ 'server computes; an algorithm nobody has enabled is not offered.',
	min: 'The smallest group to list: how many files must share a checksum before they count '
		+ 'as duplicates. Two is every duplicate; a higher number finds the widely copied ones.',
	limit: 'How many groups one page shows. Nothing here is read from disk until you ask a group '
		+ 'or a file to verify, so a larger page costs a longer query, not longer reads.',
	hash: 'Show only groups whose checksum this names. Whole values come first, then those that '
		+ 'start with what you typed. Tick Search anywhere to match it in the middle of a hash too. '
		+ 'Upper case is fine.',
}

const {
	canSudo,
	hash,
	anywhere,
	// Renamed: the prop is the caller's wish, this ref is what the composable
	// currently asks the server for. The watcher below copies one to the other.
	scope: activeScope,
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
	verifyFile,
	fileUrl,
	resetOffset,
	prevPage,
	nextPage,
} = useDuplicates()

/**
 * The hash field reloads as it is typed, but not per keystroke: a hash is
 * long, and each character would otherwise cost a query whose answer is
 * thrown away by the next one.
 */
let hashTimer: ReturnType<typeof setTimeout> | undefined

function onHashInput(value: string | number): void {
	hash.value = String(value)
	clearTimeout(hashTimer)
	hashTimer = setTimeout(() => {
		resetOffset()
		load()
	}, 300)
}

function onAnywhere(value: boolean): void {
	anywhere.value = value
	// Only worth re-asking when there is a term for it to change.
	if (hash.value.trim()) {
		resetOffset()
		load()
	}
}

// NcTextField emits string | number; the composable wants a bounded integer.
function bounded(value: string | number, min: number, max: number, fallback: number): number {
	const n = Math.trunc(Number(value))
	return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : fallback
}

/** A scoped listing with nothing named yet has nothing to ask for. */
const awaitingScope = computed(
	() => props.emptyScopeText !== ''
		&& props.scope !== null
		&& !props.scope.all
		&& props.scope.users.length === 0
		&& props.scope.groups.length === 0,
)

watch(() => props.scope, (value) => {
	activeScope.value = value
	resetOffset()
	if (!awaitingScope.value) {
		load()
	} else {
		groups.value = []
	}
}, { deep: true })

// Reported after every load rather than on change: the page holds an
// Others address until it hears whether the tab may be offered, and a
// "no" is the answer it never heard while only a change was reported —
// the value starts at false.
watch(loading, (now, before) => {
	if (before && !now) {
		emit('canSudo', canSudo.value)
	}
})

function refresh(): void {
	resetOffset()
	load()
}

// Every control asks for its own reload, rather than a watcher reloading on
// any change of the value: a set of values arriving together from the URL
// is then one load, not one per field.
function onAlgo(value: string | string[] | null): void {
	algo.value = typeof value === 'string' ? value : ''
	refresh()
}

// Clamped first, and reloaded only when the clamped value is new: "1000"
// typed over a limit of 500 is still 500, and not a second query.
function onMinCount(value: string | number): void {
	const next = bounded(value, 2, 100, 2)
	if (next === minCount.value) {
		return
	}
	minCount.value = next
	refresh()
}

function onLimit(value: string | number): void {
	const next = bounded(value, 1, 500, 50)
	if (next === limit.value) {
		return
	}
	limit.value = next
	refresh()
}

function onPrevPage(): void {
	prevPage()
	load()
}

function onNextPage(): void {
	nextPage()
	load()
}

function currentParams(): ListingParams {
	return {
		hash: hash.value,
		anywhere: anywhere.value,
		algo: algo.value,
		minCount: minCount.value,
		limit: limit.value,
		offset: offset.value,
	}
}

/**
 * Show what the URL says. Nothing happens when it already says what is
 * shown, which is what the page's own writes come back as.
 */
function applyParams(next: ListingParams, force = false): void {
	if (!force && sameParams(next, currentParams())) {
		return
	}
	// A hash typed a moment ago must not load over what the address says.
	clearTimeout(hashTimer)
	hash.value = next.hash
	anywhere.value = next.anywhere
	algo.value = next.algo
	minCount.value = next.minCount
	limit.value = next.limit
	offset.value = next.offset
	if (!awaitingScope.value) {
		load()
	} else {
		groups.value = []
	}
}

watch(() => props.params, (next) => {
	if (next) {
		applyParams(next)
	}
}, { deep: true })

// The URL follows the controls. Reported on every change, a keystroke in
// the hash field included; the page replaces the address rather than
// pushing it, so that costs no history.
watch([hash, anywhere, algo, minCount, limit, offset], () => {
	emit('update:params', currentParams())
})

// A URL may name an algorithm before the list of what the instance computes
// has arrived. Once it has, one it does not name falls back to all of them.
watch(() => props.algorithmIds, (ids) => {
	if (ids.length > 0 && algo.value && !ids.includes(algo.value)) {
		onAlgo('')
	}
})

/**
 * $params, less an algorithm the instance's list — when known — does not
 * name. The watcher above covers a list that arrives after the listing;
 * this covers one that arrived before it, which is the Others listing's
 * case on every load from an address: the tab opens once the ordinary
 * listing has answered, and by then the list is in, so the watcher never
 * fires and an unknown `algo` in the address was kept for good.
 */
function withKnownAlgo(params: ListingParams): ListingParams {
	const ids = props.algorithmIds
	return ids.length > 0 && params.algo !== '' && !ids.includes(params.algo)
		? { ...params, algo: '' }
		: params
}

/**
 * Verification is asked for per group or per file, never for the page:
 * reading every file costs time and, on metered storage, money.
 */
async function onVerifyGroup(group: GroupType): Promise<void> {
	await verifyGroups([group])
}

onMounted(() => {
	activeScope.value = props.scope
	if (props.params) {
		applyParams(withKnownAlgo(props.params), true)
	} else if (!awaitingScope.value) {
		load()
	}
})

onBeforeUnmount(() => {
	// The Others listing is destroyed on leaving the tab; a pending hash
	// load would otherwise fire into a component that is gone.
	clearTimeout(hashTimer)
})
</script>

<template>
	<div>
		<!-- Labelled columns that flow on one row where there is room. The
		     labels are the page's own: NcTextField with label-outside renders
		     none, and drops its class on the input rather than its root, which
		     is why each field is sized by the wrapper around it. -->
		<div class="db-controls">
			<div class="db-field db-field--algo">
				<span class="db-label">
					<label :for="`${props.idPrefix}-algorithm`">Algorithm</label>
					<HelpPopover :text="HELP.algo" label="Algorithm" />
				</span>
				<AlgorithmSelect
					:model-value="algo"
					:algorithms="algorithmIds"
					:leading="ALL_ALGORITHMS"
					:input-id="`${props.idPrefix}-algorithm`"
					label="Algorithm"
					@update:model-value="onAlgo" />
			</div>
			<div class="db-field db-field--narrow">
				<span class="db-label">
					<label :for="`${props.idPrefix}-min`">Min</label>
					<HelpPopover :text="HELP.min" label="Min" />
				</span>
				<NcTextField
					:id="`${props.idPrefix}-min`"
					:model-value="minCount"
					type="number"
					label="Min"
					label-outside
					min="2"
					max="100"
					title="Smallest group to list: files sharing a checksum, 2 to 100"
					@update:model-value="onMinCount" />
			</div>
			<div class="db-field db-field--narrow">
				<span class="db-label">
					<label :for="`${props.idPrefix}-limit`">Limit</label>
					<HelpPopover :text="HELP.limit" label="Limit" />
				</span>
				<NcTextField
					:id="`${props.idPrefix}-limit`"
					:model-value="limit"
					type="number"
					label="Limit"
					label-outside
					min="1"
					max="500"
					title="Groups per page, 1 to 500"
					@update:model-value="onLimit" />
			</div>
			<div class="db-field db-field--hash">
				<span class="db-label">
					<label :for="`${props.idPrefix}-hash`">Hash</label>
					<HelpPopover :text="HELP.hash" label="Hash" />
					<!-- Beside the field it governs: it changes how the hash is
					     matched and does nothing on its own. -->
					<span :data-testid="`${props.idPrefix}-anywhere`" class="db-anywhere">
						<NcCheckboxRadioSwitch
							:model-value="anywhere"
							type="switch"
							title="Match the term anywhere in the hash, not only at its start"
							@update:model-value="onAnywhere">
							Search anywhere
						</NcCheckboxRadioSwitch>
					</span>
				</span>
				<NcTextField
					:id="`${props.idPrefix}-hash`"
					:model-value="hash"
					label="Hash"
					label-outside
					placeholder="Whole or start of a checksum"
					title="Show only groups whose checksum starts with this"
					@update:model-value="onHashInput" />
			</div>
			<div class="db-actions">
				<NcButton variant="primary" @click="refresh">
					Refresh
				</NcButton>
			</div>
		</div>

		<div class="db-scroll">
			<div v-if="awaitingScope" class="db-empty" data-testid="fcias-awaiting-scope">
				{{ emptyScopeText }}
			</div>
			<div v-else-if="loading" class="db-loading">
				Searching …
			</div>
			<div v-else-if="error" class="db-error">
				{{ error }}
			</div>
			<div v-else-if="groups.length === 0" class="db-empty">
				No duplicate files found.
			</div>
			<DuplicateGroup
				v-for="(group, idx) in groups"
				:key="`${group.algo}-${group.hash_value}-${idx}`"
				:group="group"
				:file-url="fileUrl"
				:verifying="verifying"
				@verify-group="onVerifyGroup"
				@verify-file="verifyFile" />
		</div>

		<div class="db-pagination">
			<NcButton v-if="offset > 0" @click="onPrevPage">
				← Previous
			</NcButton>
			<NcButton v-if="hasMore" @click="onNextPage">
				Next →
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
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

/* Wide enough for a sha1 to be read back, and it wraps with the rest. */
/* Wide enough for a sha1 to be read back, and for the switch that sits in
   its label row. */
.db-field--hash {
	width: 420px;
}

/* The switch is a caption-sized control here, not a settings row. */
.db-anywhere :deep(.checkbox-radio-switch__text) {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.db-label {
	display: flex;
	align-items: center;
	gap: 2px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

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
