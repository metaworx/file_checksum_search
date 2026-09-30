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
import { formatDateTime, t } from '../l10n'

interface TokenRow {
	id: number
	name: string
	last_activity: number
	filesystem: boolean
	granted: boolean
	granted_by: string
	granted_at: number
}

declare const OC: { requestToken: string }

interface Listing {
	tokens?: TokenRow[]
	canUseApi?: boolean
	/** False when the server could not read the token table; the list is then no answer. */
	available?: boolean
}

const tokens = ref<TokenRow[]>([])
const canUseApi = ref(true)
const loaded = ref(false)
/** What went wrong, in the reader's terms — or null while nothing has. */
const failure = ref<string | null>(null)
const busy = ref<number | null>(null)

// TRANSLATORS: "Security" and "Allow filesystem access" are Nextcloud's own names; use Nextcloud's translations. Keep /api/v1/sudo/ as it is
const HELP = t('file_checksum_search', 'A granted app password may read across accounts through the /api/v1/sudo/ routes without anyone typing a password. A grant stays in force until it is revoked, and every administrator can see it. Create the app password under "Security" first, with "Allow filesystem access" on. Whether you may look across accounts at all is still up to your administrator; a grant replaces the password prompt, not the permission.')

function take(data: Listing): void {
	tokens.value = data.tokens ?? []
	if (typeof data.canUseApi === 'boolean') {
		canUseApi.value = data.canUseApi
	}
	failure.value = data.available === false
		? t('file_checksum_search', 'Could not list your app passwords: they could not be read.')
		: null
}

/** The switch's caption: whether, and since when, the password is granted. */
function switchLabel(token: TokenRow): string {
	return token.granted
		// TRANSLATORS: {time} is the date and time the app password was granted
		? t('file_checksum_search', 'Granted {time}', { time: when(token.granted_at) })
		: t('file_checksum_search', 'Not granted')
}

/** Why a switch is disabled, on hover; nothing while it is not. */
function switchTitle(token: TokenRow): string {
	return token.filesystem ? '' : t('file_checksum_search', 'This app password has no filesystem access, so it cannot be granted.')
}

async function load(): Promise<void> {
	try {
		// A GET, but from a session: the request token is what lets core's
		// CSRF check pass — the same header the grant switch sends.
		const response = await fetch(generateOcsUrl(OCS_SETTINGS.mySudoTokens), {
			headers: { requesttoken: OC.requestToken },
		})
		if (!response.ok) throw new Error(`HTTP ${response.status}`)
		take((await response.json()) as Listing)
	} catch (e) {
		// Not "no app passwords yet": that would be an answer, and this is not.
		failure.value = t('file_checksum_search', 'Could not list your app passwords ({error}).', { error: (e as Error).message })
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
		const data = (await response.json()) as Listing & { error?: string }
		if (response.ok) {
			take(data)
			toastSaved(granted ? t('file_checksum_search', 'App password granted.') : t('file_checksum_search', 'Grant revoked.'))
		} else {
			toastError(data.error || t('file_checksum_search', 'Could not change the grant.'))
		}
	} catch (e) {
		toastError(t('file_checksum_search', 'Could not complete the request.'))
	} finally {
		busy.value = null
	}
}

function when(seconds: number): string {
	// TRANSLATORS: when an app password was last used, or granted: not at all
	return seconds > 0 ? formatDateTime(new Date(seconds * 1000)) : t('file_checksum_search', 'never')
}

onMounted(load)
</script>

<template>
	<div v-if="loaded && canUseApi" id="fcias-personal-sudo-tokens" class="fcias-section">
		<h4>
			{{ t('file_checksum_search', 'Sudo tokens') }}
			<HelpPopover :text="HELP" :label="t('file_checksum_search', 'Sudo tokens')" />
		</h4>
		<p v-if="failure" class="fcias-error" data-testid="fcias-sudo-tokens-error">
			{{ failure }}
		</p>
		<p v-else-if="tokens.length === 0" class="fcias-hint" data-testid="fcias-sudo-tokens-empty">
			<!-- TRANSLATORS: "Security" is Nextcloud's personal settings section; use Nextcloud's own translation -->
			{{ t('file_checksum_search', 'No app passwords yet. Create one under "Security", then grant it here.') }}
		</p>
		<table v-else class="grid fcias-rules-table" data-testid="fcias-sudo-tokens">
			<thead>
				<tr>
					<th>{{ t('file_checksum_search', 'App password') }}</th>
					<th>{{ t('file_checksum_search', 'Last used') }}</th>
					<th>{{ t('file_checksum_search', 'Cross-account reads') }}</th>
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
							:title="switchTitle(token)"
							@update:model-value="toggle(token, $event as boolean)">
							{{ switchLabel(token) }}
						</NcCheckboxRadioSwitch>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>
