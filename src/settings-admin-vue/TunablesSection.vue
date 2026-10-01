<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The instance's tunables, on the Advanced tab beside the diagnostics.
 *
 * How many accounts and groups the cross-account picker holds before it
 * stops prefilling and searches as you type instead; whether a background
 * job keeps the count of indexed checksums, and how old that count may get.
 * Saved through the same global-options endpoint every other section uses,
 * which leaves absent fields alone — so each control saves its own part
 * without resetting anyone else's. The switch saves on click, the numbers
 * on their own Save buttons.
 */
import { onMounted, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import HelpPopover from '../components/HelpPopover.vue'
import { OCS_SETTINGS } from '../routes'
import { toastError, toastSaved } from '../toast'
import { t } from '../l10n'

declare const OC: { requestToken: string }

const HELP = t('file_checksum_search', 'Up to this many, the picker opens with every account and group it may offer already in the list. Above it, the picker asks the server as you type instead. Applies to administrators and group admins alike.')
const HELP_COUNT_BACKGROUND = t('file_checksum_search', 'Counting the indexed checksums reads every one of them, which takes seconds on a large server. Switched on, a background job counts them once per interval, so the status never waits for it. Switched off, the status counts them when it is opened and the last count is older than the interval.')
const HELP_COUNT_INTERVAL = t('file_checksum_search', 'How old the count of indexed checksums may get before it is counted again: by the background job where it is switched on, otherwise when the status is opened.')

const prefillLimit = ref(21)
const saved = ref(21)
const countInBackground = ref(false)
const countInterval = ref(60)
const savedCountInterval = ref(60)
const loaded = ref(false)
const saving = ref(false)

const dirty = () => prefillLimit.value !== saved.value
const countIntervalDirty = () => countInterval.value !== savedCountInterval.value

async function load(): Promise<void> {
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.getGlobal))
		if (response.ok) {
			const data = (await response.json()) as {
				crossAccountPrefillLimit?: number
				checksumCountBackground?: boolean
				checksumCountInterval?: number
			}
			if (typeof data.crossAccountPrefillLimit === 'number') {
				prefillLimit.value = data.crossAccountPrefillLimit
				saved.value = data.crossAccountPrefillLimit
			}
			if (typeof data.checksumCountBackground === 'boolean') {
				countInBackground.value = data.checksumCountBackground
			}
			if (typeof data.checksumCountInterval === 'number') {
				// Seconds on the server, minutes here.
				countInterval.value = Math.round(data.checksumCountInterval / 60)
				savedCountInterval.value = countInterval.value
			}
		}
	} catch (e) {
		// The fields keep their defaults; saving still works.
	} finally {
		loaded.value = true
	}
}

/** Save the fields given, and say whether the server took them. */
async function put(body: Record<string, unknown>): Promise<boolean> {
	saving.value = true
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.saveGlobal), {
			method: 'PUT',
			headers: {
				requesttoken: OC.requestToken,
				'Content-Type': 'application/json',
			},
			body: JSON.stringify(body),
		})
		if (!response.ok) throw new Error(`HTTP ${response.status}`)
		toastSaved(t('file_checksum_search', 'Options saved.'))
		return true
	} catch (e) {
		toastError(t('file_checksum_search', 'Could not save the options.'))
		return false
	} finally {
		saving.value = false
	}
}

async function save(): Promise<void> {
	if (await put({ crossAccountPrefillLimit: prefillLimit.value })) {
		saved.value = prefillLimit.value
	}
}

/** Saved on click; a switch the server refused goes back to where it was. */
async function saveCountInBackground(value: boolean): Promise<void> {
	countInBackground.value = value
	if (!(await put({ checksumCountBackground: value }))) {
		countInBackground.value = !value
	}
}

async function saveCountInterval(): Promise<void> {
	countInterval.value = boundedMinutes(countInterval.value)
	if (await put({ checksumCountInterval: countInterval.value * 60 })) {
		savedCountInterval.value = countInterval.value
	}
}

function bounded(value: string | number): number {
	const n = Math.trunc(Number(value))
	return Number.isFinite(n) ? Math.min(500, Math.max(5, n)) : 21
}

/**
 * From five minutes, the rule job's own period, to a week. Applied on
 * saving rather than on each keystroke, which would turn the 1 of 120 into
 * a 5 before the 2 arrives.
 */
function boundedMinutes(value: string | number): number {
	const n = Math.trunc(Number(value))
	return Number.isFinite(n) ? Math.min(10080, Math.max(5, n)) : 60
}

onMounted(load)
</script>

<template>
	<div v-if="loaded" id="fcias-tunables" class="fcias-section">
		<h4>{{ t('file_checksum_search', 'Tunables') }}</h4>
		<p class="fcias-hint">
			{{ t('file_checksum_search', 'Settings that shape how the app behaves. The defaults suit most servers.') }}
		</p>
		<!-- TRANSLATORS: a heading: the list for picking other accounts and groups, whose settings follow -->
		<h5>{{ t('file_checksum_search', 'Cross-account picker') }}</h5>
		<div class="fcias-field-row">
			<span class="fcias-label">
				<!-- TRANSLATORS: prefill: fill the list of the picker before anything is typed -->
				<label for="fcias-prefill-limit">{{ t('file_checksum_search', 'Prefill up to') }}</label>
				<HelpPopover :text="HELP" :label="t('file_checksum_search', 'Prefill up to')" />
			</span>
		</div>
		<div class="fcias-field-row">
			<NcTextField
				id="fcias-prefill-limit"
				:model-value="prefillLimit"
				type="number"
				:label="t('file_checksum_search', 'Prefill up to')"
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
				{{ t('file_checksum_search', 'Save') }}
			</NcButton>
		</div>
		<h5>{{ t('file_checksum_search', 'Checksum count') }}</h5>
		<div class="fcias-switch-row">
			<NcCheckboxRadioSwitch
				id="fcias-count-background"
				:model-value="countInBackground"
				type="switch"
				:disabled="saving"
				@update:model-value="saveCountInBackground">
				<!-- TRANSLATORS: a switch under the heading "Checksum count": count the indexed checksums in a background job -->
				{{ t('file_checksum_search', 'Count in the background') }}
			</NcCheckboxRadioSwitch>
			<HelpPopover :text="HELP_COUNT_BACKGROUND" :label="t('file_checksum_search', 'Count in the background')" />
		</div>
		<div class="fcias-field-row">
			<span class="fcias-label">
				<!-- TRANSLATORS: under the heading "Checksum count": how many minutes the count may age before it is counted again -->
				<label for="fcias-count-interval">{{ t('file_checksum_search', 'Renew after (minutes)') }}</label>
				<HelpPopover :text="HELP_COUNT_INTERVAL" :label="t('file_checksum_search', 'Renew after (minutes)')" />
			</span>
		</div>
		<div class="fcias-field-row">
			<NcTextField
				id="fcias-count-interval"
				:model-value="countInterval"
				type="number"
				:label="t('file_checksum_search', 'Minutes')"
				label-outside
				min="5"
				max="10080"
				:disabled="saving"
				@update:model-value="countInterval = Number($event)" />
			<NcButton
				id="fcias-btn-save-count-interval"
				:variant="countIntervalDirty() ? 'warning' : 'secondary'"
				:disabled="saving || !countIntervalDirty()"
				@click="saveCountInterval">
				{{ t('file_checksum_search', 'Save') }}
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

/* A group per subject, set apart by space as Nextcloud's own settings are,
   its heading the labels' colour a size up. */
#fcias-tunables h5 {
	margin-top: 20px;
	color: var(--color-text-maxcontrast);
}

/* The switch's label runs as wide as the section, its help icon right after
   it rather than at the far end, and it reads as the labels around it. */
.fcias-switch-row {
	display: flex;
	align-items: center;
	gap: 2px;
	margin-block: 4px 8px;
}

.fcias-switch-row :deep(.checkbox-content) {
	padding-inline-start: 0;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}
</style>
