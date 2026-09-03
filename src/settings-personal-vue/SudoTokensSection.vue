<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The caller's app passwords, each with a switch that grants it the
 * cross-account routes without a password prompt — the non-interactive half
 * of sudo, for scripts. Only app passwords are listed: a browser session is
 * made and discarded by a login. Only shown to an account that may use the
 * API at all; a grant would buy anyone else nothing.
 */
import { onMounted, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import HelpPopover from '../components/HelpPopover.vue'
import { OCS_SETTINGS } from '../routes'
import { toastError, toastSaved } from '../toast'

interface TokenRow {
	id: number
	name: string
	last_activity: number
	filesystem: boolean
	granted: boolean
	granted_by: string
	granted_at: number
}

const OC = window.OC as unknown as { requestToken: string }

const tokens = ref<TokenRow[]>([])
const canUseApi = ref(true)
const loaded = ref(false)
const busy = ref<number | null>(null)

const HELP = 'A granted app password may read across accounts through the /api/v1/sudo/ routes without '
	+ 'anyone typing a password — a standing authorisation, listed for every administrator to see. '
	+ 'Create the app password on Security first; only one allowed to access files can be granted. '
	+ 'Who may look across accounts is still decided by the sudoers permission; a grant replaces the '
	+ 'prompt, not the permission.'

function take(data: { tokens?: TokenRow[], canUseApi?: boolean }): void {
	tokens.value = data.tokens ?? []
	if (typeof data.canUseApi === 'boolean') {
		canUseApi.value = data.canUseApi
	}
}

async function load(): Promise<void> {
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.mySudoTokens))
		if (response.ok) {
			take(await response.json())
		}
	} catch (e) {
		// Listing unavailable: the section says so below.
	} finally {
		loaded.value = true
	}
}

/**
 * Granting costs a password — core's own dialog — because it is a standing
 * authorisation; revoking does not, because it never widens anything.
 */
async function toggle(token: TokenRow, granted: boolean): Promise<void> {
	if (granted) {
		try {
			await confirmPassword()
		} catch (e) {
			return
		}
	}
	busy.value = token.id
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.mySudoToken, { id: token.id }), {
			method: 'PUT',
			headers: {
				requesttoken: OC.requestToken,
				'Content-Type': 'application/json',
			},
			body: JSON.stringify({ granted }),
		})
		const data = (await response.json()) as { error?: string, tokens?: TokenRow[] }
		if (response.ok) {
			take(data)
			toastSaved(granted ? 'App password granted.' : 'Grant revoked.')
		} else {
			toastError(data.error || 'Could not change the grant.')
		}
	} catch (e) {
		toastError('Request failed.')
	} finally {
		busy.value = null
	}
}

function when(seconds: number): string {
	return seconds > 0 ? new Date(seconds * 1000).toLocaleString() : 'never'
}

onMounted(load)
</script>

<template>
	<div v-if="loaded && canUseApi" id="fcias-personal-sudo-tokens" class="fcias-section">
		<h4>
			Sudo tokens
			<HelpPopover :text="HELP" label="Sudo tokens" />
		</h4>
		<p v-if="tokens.length === 0" class="fcias-hint">
			No app passwords yet. Create one under <em>Security</em>, then grant it here.
		</p>
		<table v-else class="grid fcias-rules-table" data-testid="fcias-sudo-tokens">
			<thead>
				<tr>
					<th>App password</th>
					<th>Last used</th>
					<th>Cross-account reads</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="token in tokens" :key="token.id" :data-token-id="token.id">
					<td :title="token.name">
						{{ token.name }}
					</td>
					<td>{{ when(token.last_activity) }}</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="token.granted"
							type="switch"
							:disabled="busy === token.id || !token.filesystem"
							:title="token.filesystem ? '' : 'This app password is kept out of the filesystem and cannot be granted file reads.'"
							@update:model-value="toggle(token, $event as boolean)">
							{{ token.granted ? `Granted ${ when(token.granted_at) }` : 'Not granted' }}
						</NcCheckboxRadioSwitch>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>
