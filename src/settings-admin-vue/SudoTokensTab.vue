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
import { t } from '../l10n'

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

interface Listing {
	grants?: GrantRow[]
	/** False when the server could not read the token table; the list is then no answer. */
	available?: boolean
}

const grants = ref<GrantRow[]>([])
const loaded = ref(false)
/** What went wrong, in the reader's terms — or null while nothing has. */
const failure = ref<string | null>(null)
const busy = ref<string | null>(null)

const key = (g: GrantRow) => `${g.uid}/${g.id}`

const HINT = t('file_checksum_search', 'App passwords granted the cross-account routes without a password prompt. Each is a standing authorisation: this is where every one of them is visible, and where any can be taken back. Who may look across accounts at all is decided under “Who may look across accounts”; a grant replaces the prompt, not the permission.')

function take(data: Listing): void {
	grants.value = data.grants ?? []
	failure.value = data.available === false
		? t('file_checksum_search', 'The grant listing is unavailable: the token table could not be read.')
		: null
}

async function load(): Promise<void> {
	try {
		// A GET, but from a session: the request token is what lets core's
		// CSRF check pass — the same header every write in the app sends.
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.allSudoTokens), {
			headers: { requesttoken: OC.requestToken },
		})
		if (!response.ok) throw new Error(`HTTP ${response.status}`)
		take((await response.json()) as Listing)
	} catch (e) {
		failure.value = t('file_checksum_search', 'The grant listing could not be loaded ({error}).', { error: (e as Error).message })
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
		take((await response.json()) as Listing)
		toastSuccess(t('file_checksum_search', 'Grant revoked.'))
	} catch (e) {
		toastError(t('file_checksum_search', 'Could not revoke the grant.'))
	} finally {
		busy.value = null
	}
}

function when(seconds: number): string {
	// TRANSLATORS: when an app password was last used, or granted: not at all
	return seconds > 0 ? new Date(seconds * 1000).toLocaleString() : t('file_checksum_search', 'never')
}

onMounted(load)
</script>

<template>
	<div>
		<p class="fcias-hint">
			{{ HINT }}
		</p>
		<p v-if="loaded && failure" class="fcias-error" data-testid="fcias-sudo-grants-error">
			{{ failure }}
		</p>
		<p v-else-if="loaded && grants.length === 0" class="fcias-hint" data-testid="fcias-sudo-grants-empty">
			{{ t('file_checksum_search', 'No grants.') }}
		</p>
		<table v-else-if="loaded" class="grid fcias-rules-table" data-testid="fcias-sudo-grants">
			<thead>
				<tr>
					<th>{{ t('file_checksum_search', 'Account') }}</th>
					<th>{{ t('file_checksum_search', 'App password') }}</th>
					<th>{{ t('file_checksum_search', 'Last used') }}</th>
					<th>{{ t('file_checksum_search', 'Granted') }}</th>
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
						<span v-else class="fcias-muted">{{ t('file_checksum_search', 'token deleted — grant left behind') }}</span>
					</td>
					<td>{{ when(grant.last_activity) }}</td>
					<!-- TRANSLATORS: when a grant was made, and by which account -->
					<td>{{ t('file_checksum_search', '{time} by {account}', { time: when(grant.granted_at), account: grant.granted_by }) }}</td>
					<td class="fcias-rules-actions">
						<NcButton variant="error" :disabled="busy === key(grant)" @click="revoke(grant)">
							{{ t('file_checksum_search', 'Revoke') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>
