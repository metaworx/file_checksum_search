<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The one preference a user has: which algorithm the files sidebar offers
 * first. Stored through `/api/v1/preferences/preferred_algorithm`; the
 * instance default is what applies when nothing is stored, and the select
 * says so in its first entry rather than hiding the default behind a blank.
 * The select shows what is active; a line appears only when the stored
 * choice is not in force.
 */
import { computed, onMounted, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import AlgorithmSelect from '../components/AlgorithmSelect.vue'
import HelpPopover from '../components/HelpPopover.vue'
import { OCS_API_V1 } from '../routes'
import type { AlgoOption } from '../algorithms'

defineProps<{
	/** The algorithms in force on this instance, from the rules payload. */
	algorithms: string[]
}>()

const OC = window.OC as unknown as {
	requestToken: string
	Notification: { showTemporary: (msg: string) => void }
}

const KEY = 'preferred_algorithm'
const stored = ref('')
const defaultAlgo = ref('')
const active = ref('')
const loaded = ref(false)
const saving = ref(false)

const HELP = 'The algorithm the Checksums tab in the file sidebar offers as its first button. '
	+ 'Leave it on the default to follow the server; pick one to have it first for every file, '
	+ 'with the file\'s own rule providing the second button.'

/** The first entry: what an empty choice means, spelled out. */
const defaultEntry = computed<AlgoOption>(() => ({
	id: '',
	label: `Default (${defaultAlgo.value.toUpperCase() || '…'})`,
}))

function endpoint(): string {
	return generateOcsUrl(OCS_API_V1.preference, { key: KEY })
}

function take(data: { value?: string, default?: string, active?: string }): void {
	stored.value = typeof data.value === 'string' ? data.value : ''
	defaultAlgo.value = typeof data.default === 'string' ? data.default : ''
	active.value = typeof data.active === 'string' ? data.active : ''
}

async function load(): Promise<void> {
	try {
		const response = await fetch(endpoint())
		if (response.ok) {
			take(await response.json())
		}
	} catch (e) {
		// The select then shows the default entry; nothing else is lost.
	} finally {
		loaded.value = true
	}
}

async function save(value: string): Promise<void> {
	saving.value = true
	try {
		const response = await fetch(endpoint(), {
			method: 'PUT',
			headers: {
				requesttoken: OC.requestToken,
				'Content-Type': 'application/json',
			},
			body: JSON.stringify({ value }),
		})
		const data = (await response.json()) as { error?: string, value?: string, default?: string, active?: string }
		if (response.ok) {
			take(data)
		} else {
			OC.Notification.showTemporary(data.error || 'Could not save the preference.')
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
	<div id="fcias-personal-preference" class="fcias-section">
		<h4>Your preferred algorithm</h4>
		<div class="fcias-field-row">
			<AlgorithmSelect
				v-if="loaded"
				:model-value="stored"
				:algorithms="algorithms"
				:leading="defaultEntry"
				:disabled="saving"
				input-id="fcias-preferred-algorithm"
				label="Preferred algorithm"
				@update:model-value="save(String($event))" />
			<HelpPopover :text="HELP" label="Preferred algorithm" />
		</div>
		<p v-if="stored && stored !== active" class="fcias-hint" data-testid="fcias-preference-stale">
			{{ stored.toUpperCase() }} is not available on this server at the moment, so the default
			({{ active.toUpperCase() }}) applies.
		</p>
	</div>
</template>
