<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Every grant on the instance, against the live token table. A grant is a
 * standing authorisation with no password moment, which is exactly why an
 * administrator gets to see all of them in one place — and why a grant whose
 * token is gone is shown as such rather than hidden.
 */
import { onMounted, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import { OCS_SETTINGS } from '../routes'
import { toastError, toastSuccess } from '../toast'

interface GrantRow {
	uid: string
	id: number
	name: string
	last_activity: number
	exists: boolean
	granted_by: string
	granted_at: number
}

const OC = window.OC as unknown as { requestToken: string }

const grants = ref<GrantRow[]>([])
const loaded = ref(false)
const failed = ref(false)
const busy = ref<string | null>(null)

const key = (g: GrantRow) => `${g.uid}/${g.id}`

async function load(): Promise<void> {
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.allSudoTokens))
		if (!response.ok) throw new Error(`HTTP ${response.status}`)
		grants.value = ((await response.json()) as { grants?: GrantRow[] }).grants ?? []
	} catch (e) {
		failed.value = true
	} finally {
		loaded.value = true
	}
}

async function revoke(grant: GrantRow): Promise<void> {
	busy.value = key(grant)
	try {
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.revokeSudoToken, { uid: grant.uid, id: grant.id }), {
			method: 'DELETE',
			headers: { requesttoken: OC.requestToken },
		})
		if (!response.ok) throw new Error(`HTTP ${response.status}`)
		grants.value = ((await response.json()) as { grants?: GrantRow[] }).grants ?? []
		toastSuccess('Grant revoked.')
	} catch (e) {
		toastError('Could not revoke the grant.')
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
	<div>
		<p class="fcias-hint">
			App passwords granted the cross-account routes without a password prompt. Each is a standing
			authorisation: this is where every one of them is visible, and where any can be taken back. Who may
			look across accounts at all is decided under <em>Who may look across accounts</em>; a grant replaces
			the prompt, not the permission.
		</p>
		<p v-if="loaded && failed" class="fcias-error">
			The grant listing is unavailable: the token table could not be read.
		</p>
		<p v-else-if="loaded && grants.length === 0" class="fcias-hint">
			No grants.
		</p>
		<table v-else-if="loaded" class="grid fcias-rules-table" data-testid="fcias-sudo-grants">
			<thead>
				<tr>
					<th>Account</th>
					<th>App password</th>
					<th>Last used</th>
					<th>Granted</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="grant in grants" :key="key(grant)" :data-grant="key(grant)">
					<td>{{ grant.uid }}</td>
					<td :title="grant.name">
						<template v-if="grant.exists">
							{{ grant.name }}
						</template>
						<span v-else class="fcias-muted">token deleted — grant left behind</span>
					</td>
					<td>{{ when(grant.last_activity) }}</td>
					<td>{{ when(grant.granted_at) }} by {{ grant.granted_by }}</td>
					<td class="fcias-rules-actions">
						<NcButton variant="error" :disabled="busy === key(grant)" @click="revoke(grant)">
							Revoke
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>
