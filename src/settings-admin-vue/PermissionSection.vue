<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Admin "rule editing permission" section — allow all users, or a
 * selection of groups and users that may edit rules.
 */

import { onMounted, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSelectGroup from '@nextcloud/vue/components/NcSettingsSelectGroup'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { OCS_SETTINGS } from '../routes'

interface UserOption {
	id: string
	label: string
}

const OC = window.OC as unknown as {
	requestToken: string
	Notification: { showTemporary: (msg: string) => void }
}

const allowAll = ref(false)
const allowedGroups = ref<string[]>([])
const selectedUsers = ref<UserOption[]>([])
const userOptions = ref<UserOption[]>([])
const saving = ref(false)

async function load(): Promise<void> {
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.getAdminOptions))
		const data = (await response.json()) as {
			allowAllUsers?: boolean
			groups?: string[]
			users?: string[]
			availableUsers?: { id: string; displayName: string }[]
		}
		allowAll.value = data.allowAllUsers === true
		allowedGroups.value = data.groups || []
		userOptions.value = (data.availableUsers || []).map((u) => ({ id: u.id, label: u.displayName }))
		const selectedIds = data.users || []
		selectedUsers.value = userOptions.value.filter((u) => selectedIds.includes(u.id))
	} catch (e) {
		OC.Notification.showTemporary('Failed to load permission options.')
	}
}

async function save(): Promise<void> {
	saving.value = true
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.saveAdminOptions), {
			method: 'POST',
			headers: {
				requesttoken: OC.requestToken,
				'Content-Type': 'application/json',
			},
			body: JSON.stringify({
				allowAllUsers: allowAll.value,
				groups: allowedGroups.value,
				users: selectedUsers.value.map((u) => u.id),
			}),
		})
		const data = (await response.json()) as { success?: boolean; error?: string }
		if (data.success) {
			OC.Notification.showTemporary('Options saved.')
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
		<NcCheckboxRadioSwitch v-model="allowAll" type="switch">
			Allow all users to edit rules
		</NcCheckboxRadioSwitch>

		<div v-if="!allowAll" class="fcias-permission-selects">
			<NcSettingsSelectGroup
				v-model="allowedGroups"
				label="Groups"
				placeholder="Select groups…" />

			<NcSelect
				v-model="selectedUsers"
				:multiple="true"
				:options="userOptions"
				input-label="Users"
				placeholder="Search users…"
				label-outside
				track-by="id" />
		</div>

		<div class="fcias-cron-form-actions">
			<button class="fcias-btn" :disabled="saving" @click="save">
				{{ saving ? 'Saving…' : 'Save' }}
			</button>
		</div>
	</div>
</template>

<style scoped>
.fcias-permission-selects {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 12px 0;
}
</style>
