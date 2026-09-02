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
import HelpPopover from '../components/HelpPopover.vue'
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
/**
 * The group/user selects stay hidden until the saved options are in. Rendering
 * them from the default allowAll=false and hiding them once the response lands
 * made them flash on every page load.
 */
const loaded = ref(false)

const HELP = {
	allowAll: 'When on, every user of this instance may create and edit rules for folders they can '
		+ 'write to. When off, that permission is limited to the groups and individual users you select.',
	groups: 'Members of these groups may create and edit rules for folders they can write to. '
		+ 'Selected groups and selected users are combined — being in either is enough.',
	users: 'Individual users who may create and edit rules for folders they can write to, in '
		+ 'addition to the members of any selected groups.',
}

async function load(): Promise<void> {
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.getGlobal))
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
		<div class="fcias-permission-switch">
			<NcCheckboxRadioSwitch v-model="allowAll" type="switch">
				Allow all users to edit rules
			</NcCheckboxRadioSwitch>
			<HelpPopover :text="HELP.allowAll" label="Allow all users to edit rules" />
		</div>

		<div v-if="loaded && !allowAll" class="fcias-permission-selects">
			<div class="fcias-field-row">
				<NcSettingsSelectGroup
					v-model="allowedGroups"
					label="Groups"
					placeholder="Select groups…" />
				<HelpPopover :text="HELP.groups" label="Groups" />
			</div>

			<div class="fcias-field-row">
				<NcSelect
					v-model="selectedUsers"
					:multiple="true"
					:options="userOptions"
					input-label="Users"
					placeholder="Search users…"
					label-outside
					track-by="id" />
				<HelpPopover :text="HELP.users" label="Users" />
			</div>
		</div>

		<div class="fcias-rule-form-actions fcias-rule-form-actions--start">
			<button class="fcias-btn" :disabled="saving" @click="save">
				{{ saving ? 'Saving…' : 'Save' }}
			</button>
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
