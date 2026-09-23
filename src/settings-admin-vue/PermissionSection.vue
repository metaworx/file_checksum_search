<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * One permission's section: allow everyone, or a selection of groups and
 * users. The same component behind every permission the app has — which
 * one is a prop, and so are the words — so a fourth permission is a fourth
 * mount, not a fourth copy.
 */

import { computed, onMounted, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSelectGroup from '@nextcloud/vue/components/NcSettingsSelectGroup'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcButton from '@nextcloud/vue/components/NcButton'
import HelpPopover from '../components/HelpPopover.vue'
import { OCS_SETTINGS } from '../routes'
import { toastError, toastSaved } from '../toast'

interface UserOption {
	id: string
	label: string
}

const props = defineProps<{
	/** The permission key, as `PermissionService` names it. */
	permission: string
	/** The switch's caption: "Allow all users to …". */
	switchLabel: string
	/** Help texts for the switch and the two pickers. */
	help: { allowAll: string, groups: string, users: string }
}>()

const OC = window.OC as unknown as { requestToken: string }

const allowAll = ref(false)
const allowedGroups = ref<string[]>([])
const selectedUsers = ref<UserOption[]>([])
const userOptions = ref<UserOption[]>([])
const saving = ref(false)
/**
 * The group/user selects stay hidden until the saved options are in. Rendering
 * them from the default allowAll=false and hiding them once the response lands
 * made them flash on every page load.
 */
const loaded = ref(false)

/**
 * What the server last confirmed, as the body a save would send. Anything
 * else on screen means there is something to save, and the button says so.
 */
const baseline = ref('')

function payload(): string {
	return JSON.stringify({
		permissions: {
			[props.permission]: {
				allowAll: allowAll.value,
				groups: allowedGroups.value,
				users: selectedUsers.value.map((u) => u.id),
			},
		},
	})
}

const dirty = computed(() => loaded.value && payload() !== baseline.value)

async function load(): Promise<void> {
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.getGlobal))
		const data = (await response.json()) as {
			permissions?: Record<string, { allowAll?: boolean, groups?: string[], users?: string[] }>
			availableUsers?: { id: string; displayName: string }[]
		}
		const mine = data.permissions?.[props.permission] ?? {}
		allowAll.value = mine.allowAll === true
		allowedGroups.value = mine.groups || []
		userOptions.value = (data.availableUsers || []).map((u) => ({ id: u.id, label: u.displayName }))
		const selectedIds = mine.users || []
		selectedUsers.value = userOptions.value.filter((u) => selectedIds.includes(u.id))
	} catch (e) {
		toastError('Failed to load permission options.')
	} finally {
		loaded.value = true
		baseline.value = payload()
	}
}

async function save(): Promise<void> {
	saving.value = true
	const sent = payload()
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.saveGlobal), {
			method: 'PUT',
			headers: {
				requesttoken: OC.requestToken,
				'Content-Type': 'application/json',
			},
			// Snapshotted before the request, so a change made while it is in
			// flight still counts as unsaved when the answer arrives.
			body: sent,
		})
		const data = (await response.json()) as { success?: boolean; error?: string }
		if (data.success) {
			baseline.value = sent
			toastSaved('Permissions saved.')
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
		<div class="fcias-permission-switch">
			<NcCheckboxRadioSwitch v-model="allowAll" type="switch" :data-testid="`fcias-permission-${permission}-all`">
				{{ switchLabel }}
			</NcCheckboxRadioSwitch>
			<HelpPopover :text="help.allowAll" :label="switchLabel" />
		</div>

		<div v-if="loaded && !allowAll" class="fcias-permission-selects">
			<div class="fcias-field-row">
				<NcSettingsSelectGroup
					v-model="allowedGroups"
					label="Groups"
					placeholder="Select groups…" />
				<HelpPopover :text="help.groups" label="Groups" />
			</div>

			<div class="fcias-field-row">
				<NcSelect
					v-model="selectedUsers"
					:multiple="true"
					:options="userOptions"
					input-label="Users"
					placeholder="Search users…"
					label-outside />
				<HelpPopover :text="help.users" label="Users" />
			</div>
		</div>

		<div class="fcias-rule-form-actions fcias-rule-form-actions--start">
			<!-- Yellow while there is something to save, and nothing to press
			     when there is not. Red is the destructive variant. -->
			<NcButton :id="`fcias-btn-save-${permission}`"
				:variant="dirty ? 'warning' : 'secondary'"
				:disabled="saving || !dirty"
				@click="save">
				{{ saving ? 'Saving…' : 'Save' }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.fcias-permission-switch {
	display: flex;
	align-items: center;
	gap: 4px;
}

.fcias-permission-selects {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 12px 0;
}
</style>
