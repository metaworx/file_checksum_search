<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Which hash algorithms this instance computes, and which one is the default.
 *
 * The choice is what PHP offers, narrowed to what the administrator allows;
 * this section edits the second half, and designates one of it as the
 * default used wherever no algorithm is named. Every picker in the app reads
 * the result from the server, so nothing here needs a release to take effect.
 */
import { onMounted, ref, watch } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import AlgorithmSelect from '../components/AlgorithmSelect.vue'
import HelpPopover from '../components/HelpPopover.vue'
import { OCS_SETTINGS } from '../routes'
import { toastError, toastSaved } from '../toast'

const OC = window.OC as unknown as { requestToken: string }

const availableIds = ref<string[]>([])
const selectedIds = ref<string[]>([])
const defaultId = ref('')
const saving = ref(false)
const loaded = ref(false)

const HELP = {
	allowed: 'The algorithms rules may compute and pickers may offer, chosen from what this server\'s '
		+ 'PHP provides. Removing one does not delete hashes already stored under it — they stay '
		+ 'searchable — it only stops new ones being computed.',
	default: 'The algorithm used wherever none is named: new rules, the command line, the API, and the '
		+ 'sidebar\'s first button for users who have not chosen one of their own. Only an allowed '
		+ 'algorithm can be the default; removing the default from the list above moves it to the '
		+ 'first remaining.',
}

/** The default follows the allowlist: dropped from it, it moves to the first remaining. */
watch(selectedIds, (ids) => {
	if (!ids.includes(defaultId.value)) {
		defaultId.value = ids[0] ?? ''
	}
})

function takeDefault(wanted: string | undefined): void {
	defaultId.value = wanted && selectedIds.value.includes(wanted) ? wanted : (selectedIds.value[0] ?? '')
}

async function load(): Promise<void> {
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.getGlobal))
		const data = (await response.json()) as {
			allowedAlgorithms?: string[]
			availableAlgorithms?: string[]
			defaultAlgorithm?: string
		}
		availableIds.value = data.availableAlgorithms ?? []
		selectedIds.value = (data.allowedAlgorithms ?? []).filter((id) => availableIds.value.includes(id))
		takeDefault(data.defaultAlgorithm)
	} catch (e) {
		toastError('Failed to load the algorithm list.')
	} finally {
		loaded.value = true
	}
}

async function save(): Promise<void> {
	saving.value = true
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.saveGlobal), {
			method: 'PUT',
			headers: {
				requesttoken: OC.requestToken,
				'Content-Type': 'application/json',
			},
			// Only this section's fields: absent fields are left untouched.
			body: JSON.stringify({ allowedAlgorithms: selectedIds.value, defaultAlgorithm: defaultId.value }),
		})
		const data = (await response.json()) as {
			success?: boolean
			error?: string
			allowedAlgorithms?: string[]
			defaultAlgorithm?: string
		}
		if (data.success) {
			toastSaved('Algorithms saved.')
			if (data.allowedAlgorithms) {
				selectedIds.value = data.allowedAlgorithms
			}
			takeDefault(data.defaultAlgorithm)
		} else {
			toastError(data.error || 'Save failed.')
		}
	} catch (e) {
		toastError('Request failed.')
	} finally {
		saving.value = false
	}
}

onMounted(load)
</script>

<template>
	<div>
		<div v-if="loaded" class="fcias-field-row">
			<AlgorithmSelect
				v-model="selectedIds"
				:algorithms="availableIds"
				multiple
				input-id="fcias-allowed-algorithms"
				label="Allowed algorithms"
				placeholder="Add an algorithm…" />
			<HelpPopover :text="HELP.allowed" label="Allowed algorithms" />
		</div>
		<div v-if="loaded" class="fcias-field-row">
			<AlgorithmSelect
				v-model="defaultId"
				:algorithms="selectedIds"
				input-id="fcias-default-algorithm"
				label="Default algorithm" />
			<HelpPopover :text="HELP.default" label="Default algorithm" />
		</div>
		<div class="fcias-rule-form-actions fcias-rule-form-actions--start">
			<button id="fcias-btn-save-algorithms"
				class="fcias-btn"
				:disabled="saving || selectedIds.length === 0 || !defaultId"
				@click="save">
				{{ saving ? 'Saving…' : 'Save' }}
			</button>
		</div>
	</div>
</template>
