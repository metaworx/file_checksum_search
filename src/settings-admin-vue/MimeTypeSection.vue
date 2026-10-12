<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The MIME types, on the Advanced tab: detection switched on and off, the
 * hashed files queued for their type, and every type deleted.
 *
 * The server's rules are the section's: switching on is refused while a
 * deletion runs and where PHP cannot detect, a refresh while detection is
 * off, a deletion while it is on. The section offers none of those, and
 * says why; a refusal that comes anyway, from a page out of date or a second
 * tab, is shown as the server words it. How many types are stored is the
 * status's to say: the section asks for the status again after each change,
 * and the server counts the types anew while a deletion runs, the kept
 * count otherwise.
 */
import { computed, onMounted, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import HelpPopover from '../components/HelpPopover.vue'
import { OCS_SETTINGS } from '../routes'
import { toastError, toastSaved } from '../toast'
import { t } from '../l10n'

declare const OC: { requestToken: string }

const emit = defineEmits<{
	/** The state changed: the status counts again. */
	(e: 'changed'): void
}>()

type State = 'enabled' | 'disabled' | 'unavailable' | 'deleting'

interface Answer {
	state?: State
	available?: boolean
	deleteRequestedAt?: number | null
	error?: string
}

const HELP = t('file_checksum_search', 'The read that computes a file\'s checksums names its MIME type from its first 64 KiB, and the type is kept beside the checksums. Nextcloud\'s own type comes from the file\'s name, so a renamed file can disagree with itself.')

const TEXTS = {
	unavailable: t('file_checksum_search', 'MIME types cannot be detected on this server: PHP\'s Fileinfo extension is not loaded.'),
	deleting: t('file_checksum_search', 'The MIME types are being deleted. Detection can be switched on again once that is done.'),
	deleteWhileOn: t('file_checksum_search', 'Switch detection off to delete the MIME types, or refresh them to bring them up to date.'),
	refreshMessage: t('file_checksum_search', 'Queue the files that have checksums to have their MIME type detected: only those without one, or all of them, for instance after PHP was upgraded. The background job then reads the first 64 KiB of each.'),
	deleteMessage: t('file_checksum_search', 'Delete every stored MIME type? The checksums stay. Detection can be switched on again once the deletion is done.'),
}

const state = ref<State | null>(null)
const available = ref(true)
const busy = ref(false)
const choosingRefresh = ref(false)
const confirmingDelete = ref(false)

const enabled = computed(() => state.value === 'enabled' || state.value === 'unavailable')

/**
 * Why the switch cannot be moved, or nothing. Off it stays while a deletion
 * runs and where PHP cannot detect; on, it can always be switched off.
 */
const switchReason = computed<string>(() => {
	if (state.value === 'deleting') {
		return TEXTS.deleting
	}
	if (!available.value) {
		return TEXTS.unavailable
	}
	return ''
})

const switchDisabled = computed(() => busy.value || (!enabled.value && switchReason.value !== ''))

const refreshButtons = computed(() => [
	{ label: t('file_checksum_search', 'Cancel') },
	{
		// TRANSLATORS: a button in the dialog that refreshes the MIME types: only the files that have none yet
		label: t('file_checksum_search', 'Those without one'),
		callback: () => { refresh(false) },
	},
	{
		// TRANSLATORS: a button in the dialog that refreshes the MIME types: every file that has checksums
		label: t('file_checksum_search', 'All of them'),
		variant: 'primary' as const,
		callback: () => { refresh(true) },
	},
])

const deleteButtons = computed(() => [
	{ label: t('file_checksum_search', 'Cancel') },
	{
		label: t('file_checksum_search', 'Delete'),
		variant: 'error' as const,
		callback: () => { remove() },
	},
])

/** Take the server's answer: the state it now has. */
function take(answer: Answer): void {
	if (answer.state) {
		state.value = answer.state
	}
	if (typeof answer.available === 'boolean') {
		available.value = answer.available
	}
}

async function load(): Promise<void> {
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.mimeTypes))
		if (response.ok) {
			take((await response.json()) as Answer)
		}
	} catch (e) {
		// Unknown, the section offers nothing it cannot know is allowed.
	}
}

/**
 * Send one action, and take the state it leaves. A refusal is shown as the
 * server words it, anything else as $failed.
 */
async function send(url: string, method: string, body: Record<string, unknown> | null, done: string, failed: string): Promise<boolean> {
	busy.value = true
	try {
		const response = await fetch(url, {
			method,
			headers: {
				requesttoken: OC.requestToken,
				'Content-Type': 'application/json',
			},
			body: body === null ? undefined : JSON.stringify(body),
		})
		const answer = (await response.json().catch(() => ({}))) as Answer
		if (!response.ok) {
			toastError(answer.error || failed)
			await load()
			return false
		}
		take(answer)
		toastSaved(done)
		emit('changed')
		return true
	} catch (e) {
		toastError(failed)
		return false
	} finally {
		busy.value = false
	}
}

async function switchDetection(value: boolean): Promise<void> {
	await send(
		generateOcsUrl(OCS_SETTINGS.mimeTypes),
		'PUT',
		{ enabled: value },
		t('file_checksum_search', 'Options saved.'),
		t('file_checksum_search', 'Could not save the options.'),
	)
}

async function refresh(all: boolean): Promise<void> {
	await send(
		generateOcsUrl(OCS_SETTINGS.mimeTypesRefresh),
		'POST',
		{ all },
		t('file_checksum_search', 'The files are queued to have their MIME type detected.'),
		t('file_checksum_search', 'Could not refresh the MIME types.'),
	)
}

async function remove(): Promise<void> {
	await send(
		generateOcsUrl(OCS_SETTINGS.mimeTypesDelete),
		'POST',
		null,
		t('file_checksum_search', 'The MIME types are being deleted.'),
		t('file_checksum_search', 'Could not delete the MIME types.'),
	)
}

onMounted(load)
</script>

<template>
	<div v-if="state !== null" id="fcias-mimetypes" class="fcias-section">
		<h4>{{ t('file_checksum_search', 'MIME types') }}</h4>
		<p class="fcias-hint">
			{{ t('file_checksum_search', 'Each file\'s MIME type, detected from its content while its checksums are computed.') }}
		</p>
		<div class="fcias-switch-row">
			<NcCheckboxRadioSwitch
				id="fcias-mimetype-detection"
				:model-value="enabled"
				type="switch"
				:disabled="switchDisabled"
				@update:model-value="switchDetection">
				{{ t('file_checksum_search', 'Detect MIME types') }}
			</NcCheckboxRadioSwitch>
			<HelpPopover :text="HELP" :label="t('file_checksum_search', 'Detect MIME types')" />
		</div>
		<p v-if="switchReason" id="fcias-mimetype-reason" class="fcias-hint">
			{{ switchReason }}
		</p>
		<div class="fcias-mimetype-actions">
			<NcButton
				id="fcias-btn-mimetype-refresh"
				variant="secondary"
				:disabled="busy || state !== 'enabled'"
				@click="choosingRefresh = true">
				{{ t('file_checksum_search', 'Refresh MIME types') }}
			</NcButton>
			<NcButton
				id="fcias-btn-mimetype-delete"
				variant="error"
				:disabled="busy || state !== 'disabled'"
				@click="confirmingDelete = true">
				{{ t('file_checksum_search', 'Delete MIME types') }}
			</NcButton>
		</div>
		<p v-if="enabled" id="fcias-mimetype-delete-hint" class="fcias-hint">
			{{ TEXTS.deleteWhileOn }}
		</p>

		<NcDialog
			v-if="choosingRefresh"
			:open="choosingRefresh"
			:name="t('file_checksum_search', 'Refresh MIME types')"
			:message="TEXTS.refreshMessage"
			:buttons="refreshButtons"
			@update:open="choosingRefresh = $event" />
		<NcDialog
			v-if="confirmingDelete"
			:open="confirmingDelete"
			:name="t('file_checksum_search', 'Delete MIME types')"
			:message="TEXTS.deleteMessage"
			:buttons="deleteButtons"
			@update:open="confirmingDelete = $event" />
	</div>
</template>

<style scoped>
/* The switch's label runs as wide as the section, its help icon right after
   it, and it reads as the Tunables' labels do. */
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

.fcias-mimetype-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-block: 8px 4px;
}
</style>
