<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The instance's tunables, on the Advanced tab beside the diagnostics.
 *
 * One so far: how many accounts and groups the cross-account picker holds
 * before it stops prefilling and searches as you type instead. Saved
 * through the same global-options endpoint every other section uses, which
 * leaves absent fields alone — so this section saves its own part without
 * resetting anyone else's.
 */
import { onMounted, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import HelpPopover from '../components/HelpPopover.vue'
import { OCS_SETTINGS } from '../routes'
import { toastError, toastSaved } from '../toast'

const OC = window.OC as unknown as { requestToken: string }

const HELP = 'Below this many, the picker opens with every account and group it may offer already in '
	+ 'the list. Above it, the list would be unwieldy, so the picker asks the server as you type. '
	+ 'Applies to administrators and group leaders alike.'

const prefillLimit = ref(21)
const saved = ref(21)
const loaded = ref(false)
const saving = ref(false)

const dirty = () => prefillLimit.value !== saved.value

async function load(): Promise<void> {
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.getGlobal))
		if (response.ok) {
			const data = (await response.json()) as { crossAccountPrefillLimit?: number }
			if (typeof data.crossAccountPrefillLimit === 'number') {
				prefillLimit.value = data.crossAccountPrefillLimit
				saved.value = data.crossAccountPrefillLimit
			}
		}
	} catch (e) {
		// The field keeps its default; saving still works.
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
			body: JSON.stringify({ crossAccountPrefillLimit: prefillLimit.value }),
		})
		if (!response.ok) throw new Error(`HTTP ${response.status}`)
		saved.value = prefillLimit.value
		toastSaved('Options saved.')
	} catch (e) {
		toastError('Could not save the options.')
	} finally {
		saving.value = false
	}
}

function bounded(value: string | number): number {
	const n = Math.trunc(Number(value))
	return Number.isFinite(n) ? Math.min(500, Math.max(5, n)) : 21
}

onMounted(load)
</script>

<template>
	<div v-if="loaded" id="fcias-tunables" class="fcias-section">
		<h4>Tunables</h4>
		<p class="fcias-hint">
			Numbers that shape how the interface behaves. The defaults suit most instances.
		</p>
		<div class="fcias-field-row">
			<span class="fcias-label">
				<label for="fcias-prefill-limit">Cross-account picker: prefill up to</label>
				<HelpPopover :text="HELP" label="Prefill limit" />
			</span>
		</div>
		<div class="fcias-field-row">
			<NcTextField
				id="fcias-prefill-limit"
				:model-value="prefillLimit"
				type="number"
				label="Prefill up to"
				label-outside
				min="5"
				max="500"
				:disabled="saving"
				@update:model-value="prefillLimit = bounded($event)" />
			<NcButton
				id="fcias-btn-save-tunables"
				:variant="dirty() ? 'warning' : 'secondary'"
				:disabled="saving || !dirty()"
				@click="save">
				Save
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.fcias-label {
	display: flex;
	align-items: center;
	gap: 2px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

#fcias-tunables .fcias-field-row {
	max-width: 410px;
}
</style>
