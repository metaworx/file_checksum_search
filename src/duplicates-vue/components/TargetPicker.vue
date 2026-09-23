<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Whose duplicates to browse: groups and accounts in one control.
 *
 * The server decides what may be offered — every account for a sudoer,
 * only the members of their own groups for a sub-admin — so this asks
 * rather than guessing, and never expands a group itself: membership is not
 * the viewer's to enumerate. When the server says the list is longer than
 * the picker holds (`prefill: false`) it searches as the user types instead.
 *
 * The scope is the page's, not the picker's: it arrives as a prop — from
 * the URL as well as from here — and the control shows whatever it says,
 * so a shared link opens with its accounts already named.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { OCS_API_V1 } from '../../routes'
import type { DuplicateScope } from '../composables/useDuplicates'

interface Option {
	id: string
	label: string
	/** Which list the id belongs to; the server authorises them differently. */
	kind: 'all' | 'group' | 'user'
}

const props = withDefaults(
	defineProps<{
		/** What is named now; the control reflects it. */
		scope?: DuplicateScope | null
	}>(),
	{ scope: null },
)

const emit = defineEmits<{ (e: 'update:scope', value: DuplicateScope): void }>()

const ALL_ID = '\0all'

/**
 * Whose reach "all" is: every account for a sudoer, their groups' members
 * for a leader. The server says; the label follows.
 */
const reach = ref<'everyone' | 'groups'>('everyone')

const allOption = computed<Option>(() => ({
	id: ALL_ID,
	label: reach.value === 'groups' ? 'All my groups' : 'All accounts',
	kind: 'all',
}))

const options = ref<Option[]>([])
const selected = ref<Option[]>([])
const prefill = ref(true)
const loading = ref(false)
const failed = ref(false)

/** With no prefilled list the control is useless until something is typed. */
const noOptionsText = computed(
	() => prefill.value ? 'Nobody to show' : 'Type to search',
)

/**
 * The option for an id, from the list where it is there and made up where
 * it is not — a name from a URL on an instance past the prefill threshold,
 * or one the list has not answered yet. The label is the id until the list
 * says better; {@see relabel}.
 */
function optionFor(id: string, kind: 'group' | 'user'): Option {
	return options.value.find((o) => o.kind === kind && o.id === id)
		?? { id, label: kind === 'group' ? `${id} (Group)` : id, kind }
}

/** Swap a made-up option for the list's own once the list holds it. */
function relabel(): void {
	selected.value = selected.value.map((o) => (
		o.kind === 'all'
			? allOption.value
			: options.value.find((p) => p.kind === o.kind && p.id === o.id) ?? o
	))
}

async function fetchOptions(search: string | null = null): Promise<void> {
	loading.value = true
	try {
		const url = new URL(generateOcsUrl(OCS_API_V1.sudoSelectable), window.location.origin)
		if (search) {
			url.searchParams.set('search', search)
		}
		const response = await fetch(url.toString().replace(window.location.origin, ''))
		if (!response.ok) throw new Error(`HTTP ${response.status}`)
		const data = (await response.json()) as {
			prefill?: boolean
			all?: boolean
			reach?: 'everyone' | 'groups'
			groups?: Array<{ id: string, label: string }>
			users?: Array<{ id: string, label: string }>
		}

		// `prefill` describes the list the server just sent, and only the
		// opening list — nobody's search — says anything about the instance.
		// A search that finds one account also comes back `prefill: true`,
		// and taking that at face value flipped the control into filtering
		// its last answer: the second name typed found nothing.
		if (search === null) {
			prefill.value = data.prefill !== false
		}
		if (data.reach === 'groups' || data.reach === 'everyone') {
			reach.value = data.reach
		}
		options.value = [
			...(data.all ? [allOption.value] : []),
			// A group and an account can carry the same name — "admin" is both
			// on a stock instance — so the kind is said, not implied.
			...(data.groups ?? []).map((g) => ({ id: g.id, label: `${g.label} (Group)`, kind: 'group' as const })),
			...(data.users ?? []).map((u) => ({ id: u.id, label: u.label, kind: 'user' as const })),
		]
		relabel()
		failed.value = false
	} catch (e) {
		failed.value = true
		options.value = []
	} finally {
		loading.value = false
	}
}

/**
 * Only when the server said the list is too long to hold: otherwise every
 * option is already here and NcSelect filters them without a round trip.
 */
function onSearch(search: string): void {
	if (!prefill.value) {
		fetchOptions(search)
	}
}

function onSelect(value: Option[] | Option | null): void {
	const chosen = Array.isArray(value) ? value : (value ? [value] : [])
	selected.value = chosen

	// "All" is not a target among others — it is the whole reach, so it
	// wins and the rest is ignored.
	if (chosen.some((o) => o.kind === 'all')) {
		emit('update:scope', { all: true, users: [], groups: [] })
		return
	}

	emit('update:scope', {
		all: false,
		users: chosen.filter((o) => o.kind === 'user').map((o) => o.id),
		groups: chosen.filter((o) => o.kind === 'group').map((o) => o.id),
	})
}

// The page's scope is the truth, wherever it came from; the control shows it.
watch(
	() => props.scope,
	(scope) => {
		if (!scope) {
			selected.value = []
			return
		}
		selected.value = scope.all
			? [allOption.value]
			: [
				...scope.groups.map((gid) => optionFor(gid, 'group')),
				...scope.users.map((uid) => optionFor(uid, 'user')),
			]
	},
	{ immediate: true, deep: true },
)

onMounted(() => fetchOptions())
</script>

<template>
	<div class="db-field db-field--targets" data-testid="fcias-target-picker">
		<span class="db-label">
			<label for="fcias-others-targets">Whose files</label>
		</span>
		<NcSelect
			v-model="selected"
			input-id="fcias-others-targets"
			:options="options"
			:multiple="true"
			:loading="loading"
			:filterable="prefill"
			:no-options="noOptionsText"
			label="label"
			track-by="id"
			placeholder="Choose accounts or groups"
			@search="onSearch"
			@update:model-value="onSelect" />
		<p v-if="failed" class="fcias-error" data-testid="fcias-target-picker-error">
			The list of accounts could not be loaded.
		</p>
	</div>
</template>
