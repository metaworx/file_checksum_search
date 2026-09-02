<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Which hash algorithms this instance computes.
 *
 * The choice is what PHP offers, narrowed to what the administrator allows;
 * this section edits the second half. Every picker in the app reads the
 * result from the server, so nothing here needs a release to take effect.
 */
import { computed, onMounted, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import AlgorithmSelect from '../components/AlgorithmSelect.vue'
import HelpPopover from '../components/HelpPopover.vue'
import { OCS_SETTINGS } from '../routes'

const OC = window.OC as unknown as {
	requestToken: string
	Notification: { showTemporary: (msg: string) => void }
}

const availableIds = ref<string[]>([])
const selectedIds = ref<string[]>([])
const defaultAlgorithm = ref('')
const saving = ref(false)
const loaded = ref(false)

const HELP = {
	allowed: 'The algorithms rules may compute and pickers may offer, chosen from what this server\'s '
		+ 'PHP provides. Removing one does not delete hashes already stored under it — they stay '
		+ 'searchable — it only stops new ones being computed. The first in the list is the default, '
		+ 'used wherever no algorithm is named.',
}

/** The first selected algorithm is the default; say so beside the picker. */
const defaultLabel = computed(() => (selectedIds.value[0] ?? defaultAlgorithm.value).toUpperCase())

async function load(): Promise<void> {
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.getGlobal))
		const data = (await response.json()) as {
			allowedAlgorithms?: string[]
			availableAlgorithms?: string[]
			defaultAlgorithm?: string
		}
		availableIds.value = data.availableAlgorithms ?? []
		// The allowlist's own order: it decides which is the default.
		selectedIds.value = (data.allowedAlgorithms ?? []).filter((id) => availableIds.value.includes(id))
		defaultAlgorithm.value = data.defaultAlgorithm ?? ''
	} catch (e) {
		OC.Notification.showTemporary('Failed to load the algorithm list.')
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
			// Only this section's field: absent fields are left untouched.
			body: JSON.stringify({ allowedAlgorithms: selectedIds.value }),
		})
		const data = (await response.json()) as { success?: boolean; error?: string; allowedAlgorithms?: string[] }
		if (data.success) {
			OC.Notification.showTemporary('Algorithms saved.')
			if (data.allowedAlgorithms) {
				defaultAlgorithm.value = data.allowedAlgorithms[0] ?? ''
			}
		} else {
			OC.Notification.showTemporary(data.error || 'Save failed.')
		}
	} catch (e) {
		OC.Notification.showTemporary('Request failed.')
	} finally {
		saving.value = false
	}
}

onMounted(load)
</script>

<template>
	<div>
		<div v-if="loaded" class="fcias-permission-row">
			<AlgorithmSelect
				v-model="selectedIds"
				:algorithms="availableIds"
				multiple
				input-id="fcias-allowed-algorithms"
				label="Allowed algorithms"
				placeholder="Add an algorithm…" />
			<HelpPopover :text="HELP.allowed" label="Allowed algorithms" />
		</div>
		<p v-if="loaded" class="fcias-hint" data-testid="fcias-default-algorithm">
			Default: <strong>{{ defaultLabel }}</strong> — the first in the list. Drag is not
			supported here; remove and re-add to change the order.
		</p>
		<div class="fcias-rule-form-actions fcias-rule-form-actions--start">
			<button id="fcias-btn-save-algorithms"
				class="fcias-btn"
				:disabled="saving || selectedIds.length === 0"
				@click="save">
				{{ saving ? 'Saving…' : 'Save' }}
			</button>
		</div>
	</div>
</template>
