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
 */
import { computed, onMounted, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { OCS_DUPLICATES } from '../../routes'
import type { DuplicateScope } from '../composables/useDuplicates'

interface Option {
	id: string
	label: string
	/** Which list the id belongs to; the server authorises them differently. */
	kind: 'all' | 'group' | 'user'
}

const emit = defineEmits<{ (e: 'update:scope', value: DuplicateScope): void }>()

const ALL: Option = { id: '\0all', label: 'All accounts', kind: 'all' }

const options = ref<Option[]>([])
const selected = ref<Option[]>([])
const prefill = ref(true)
const loading = ref(false)
const failed = ref(false)

/** With no prefilled list the control is useless until something is typed. */
const noOptionsText = computed(
	() => prefill.value ? 'Nobody to show' : 'Type to search',
)

async function fetchOptions(search: string | null = null): Promise<void> {
	loading.value = true
	try {
		const url = new URL(generateOcsUrl(OCS_DUPLICATES.selectable), window.location.origin)
		if (search) {
			url.searchParams.set('search', search)
		}
		const response = await fetch(url.toString().replace(window.location.origin, ''))
		if (!response.ok) throw new Error(`HTTP ${response.status}`)
		const data = (await response.json()) as {
			prefill?: boolean
			all?: boolean
			groups?: Array<{ id: string, label: string }>
			users?: Array<{ id: string, label: string }>
		}

		prefill.value = data.prefill !== false
		options.value = [
			...(data.all ? [ALL] : []),
			// A group and an account can carry the same name — "admin" is both
			// on a stock instance — so the kind is said, not implied.
			...(data.groups ?? []).map((g) => ({ id: g.id, label: `${g.label} (Group)`, kind: 'group' as const })),
			...(data.users ?? []).map((u) => ({ id: u.id, label: u.label, kind: 'user' as const })),
		]
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

	// "All accounts" is not a target among others — it is the whole
	// instance, so it wins and the rest is ignored.
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

onMounted(() => fetchOptions())
</script>

<template>
	<div class="db-field db-field--targets" data-testid="fcias-target-picker">
		<span class="db-label">
			<label for="fcias-xaccount-targets">Whose files</label>
		</span>
		<NcSelect
			v-model="selected"
			input-id="fcias-xaccount-targets"
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

<style scoped>
/* The listing's field styles are scoped to it, so the picker carries its
   own: label above its control, like every other field on the page. */
/* Full width: several accounts and groups can be selected at once, and the
   chips need the room. Uncapped, unlike the fixed-width filters below it. */
.db-field--targets {
	display: flex;
	flex-direction: column;
	gap: 2px;
	width: 100%;
	margin-bottom: 12px;
}

.db-label {
	display: flex;
	align-items: center;
	gap: 2px;
	font-weight: 600;
}

.db-field--targets :deep(.v-select.select) {
	min-width: 0;
	width: 100%;
}
</style>
