<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Root component for the admin settings page.
 */

import { computed, ref } from 'vue'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcButton from '@nextcloud/vue/components/NcButton'
import RuleTable from '../rules-vue/RuleTable.vue'
import RuleForm from '../rules-vue/RuleForm.vue'
import type { Rule, RuleDraft } from '../rules-vue/types'
import AlgorithmSection from './AlgorithmSection.vue'
import PermissionSection from './PermissionSection.vue'
import SudoTokensTab from './SudoTokensTab.vue'
import TunablesSection from './TunablesSection.vue'
import DocsViewer from '../docs-vue/DocsViewer.vue'
import { useAdminSettings } from './composables/useAdminSettings'
import { toastSuccess } from '../toast'

/** The words each permission section shows; the component is the same. */
const PERMISSION_HELP = {
	rule_editing: {
		allowAll: 'When on, every user of this instance may create and edit rules for folders they can '
			+ 'write to. When off, that permission is limited to the groups and individual users you select.',
		groups: 'Members of these groups may create and edit rules for folders they can write to. '
			+ 'Selected groups and selected users are combined — being in either is enough.',
		users: 'Individual users who may create and edit rules for folders they can write to, in '
			+ 'addition to the members of any selected groups.',
	},
	manual_recalc: {
		allowAll: 'When on, every user may trigger a computation of their own files by hand. When off, only the '
			+ 'groups and individual users selected here may; everyone still sees the hashes already computed.',
		groups: 'Members of these groups may trigger a computation of their own files by hand. Selected groups '
			+ 'and selected users are combined — being in either is enough.',
		users: 'Individual users who may trigger a computation of their own files by hand, in addition to the '
			+ 'members of any selected groups.',
	},
	api_access: {
		allowAll: 'When on, every user may call the public API with an app password. When off, only the '
			+ 'groups and individual users selected here may; the app\'s own pages keep working for everyone.',
		groups: 'Members of these groups may call the public API with an app password. Selected groups and '
			+ 'selected users are combined — being in either is enough.',
		users: 'Individual users who may call the public API with an app password, in addition to the '
			+ 'members of any selected groups.',
	},
	instance_view: {
		allowAll: 'When on, every user may switch to the instance-wide view after confirming their password. '
			+ 'When off, only members of the admin group and the groups and users selected here may.',
		groups: 'Members of these groups may look across accounts once they have confirmed their password. '
			+ 'Selected groups and selected users are combined — being in either is enough.',
		users: 'Individual users who may look across accounts once they have confirmed their password, in '
			+ 'addition to the members of any selected groups and of the admin group.',
	},
}

declare const OC: {
	dialogs: { confirm: (text: string, title: string, callback: (confirmed: boolean) => void, modal?: boolean) => void }
}

const {
	status,
	lastUpdated,
	supportedAlgos,
	availableUsers,
	availableGroups,
	groupFoldersAvailable,
	groupFoldersLabel,
	availableGroupFolders,
	availableStorages,
	rules,
	loadStatus,
	acknowledgeIdleBanner,
	loadRules,
	saveRule,
	deleteRule,
	toggleRule,
	applyRule,
	reorderSegment,
} = useAdminSettings()

// --- Idle banner (D5) ---
//
// Quiet start means the app computes nothing until an include rule is
// enabled. This banner is how an administrator learns that silence is a
// decision waiting for them, not a malfunction.

/** Closed for this page view only; reappears on the next load. */
const bannerClosed = ref(false)

/** Set once the first rules load completed — no banner flash before it. */
const rulesLoaded = ref(false)

const hashingIdle = computed(
	() => rulesLoaded.value
		&& !rules.value.some((rule) => rule.enabled && (rule.type ?? 'include') === 'include'),
)

const showIdleBanner = computed(
	() => hashingIdle.value
		&& !status.value.idleBannerAcknowledged
		&& !bannerClosed.value,
)

async function handleAcknowledgeBanner(): Promise<void> {
	if (await acknowledgeIdleBanner()) {
		toastSuccess('Noted — the banner stays away until a rule is enabled and disabled again.')
	}
}

type Tab = 'settings' | 'permissions' | 'tokens' | 'advanced' | 'docs'

const TABS: readonly Tab[] = ['settings', 'permissions', 'tokens', 'advanced', 'docs']

function tabFromHash(): Tab {
	const tab = window.location.hash.replace(/^#/, '').split('/')[0]
	return (TABS as readonly string[]).includes(tab) ? (tab as Tab) : 'settings'
}

const activeTab = ref<Tab>(tabFromHash())

function setTab(tab: Tab): void {
	activeTab.value = tab
	window.location.hash = tab
}

window.addEventListener('hashchange', () => {
	activeTab.value = tabFromHash()
})

const ruleMsg = ref('')

const pendingTotal = (stats: Record<string, number> = {}) => Object.values(stats).reduce((sum, v) => sum + v, 0)

/**
 * What each untrusted state means, in the terms an operator has to act on.
 *
 * The two are opposites in what they leave behind: erosion has already thrown
 * the hashes away, a reset has not yet — which is why one heals itself and the
 * other is waiting for something to happen.
 */
const STALE_REASONS: Record<string, { label: string, hint: string }> = {
	'stale:eroded': {
		label: 'Eroded',
		hint: 'hashes were dropped on write because no rule maintains them; heals itself once a rule covers them again',
	},
	'stale:reset': {
		label: 'Reset',
		hint: 'disowned by a reset — already hidden from search, and the background job clears them as it goes',
	},
}

const staleReason = (state: string) => STALE_REASONS[state] ?? { label: state, hint: '' }

/** Display names for the background jobs' heartbeat lines (D17). */
const JOB_LABELS: Record<string, string> = {
	rule_sweep: 'Rule sweep',
	pending_drain: 'Queue drain',
	orphan_purge: 'Orphan purge',
}

const jobRows = computed(() => Object.entries(status.value.jobs ?? {}).map(([key, run]) => ({
	key,
	label: JOB_LABELS[key] ?? key,
	time: run.lastRun === null ? 'never ran yet' : new Date(run.lastRun * 1000).toLocaleString(),
	countsText: run.lastRun === null
		? ''
		: Object.entries(run.counts).map(([name, value]) => `${name} ${value}`).join(', '),
})))

// --- Rule editing (global + additional rules share one dialog) ---

const showRuleForm = ref(false)
const editingRule = ref<RuleDraft | null>(null)

/** A failed save's message — rendered inside the dialog, not on the page. */
const saveError = ref<string | null>(null)

function toDraft(rule: Rule): RuleDraft {
	return {
		id: rule.id,
		type: rule.type ?? 'include',
		mode: rule.mode,
		algos: rule.algos,
		path: rule.path,
		selector: rule.selector,
		admin_enforced: rule.admin_enforced,
	}
}

function openAddRule(): void {
	editingRule.value = null
	saveError.value = null
	showRuleForm.value = true
}

/**
 * A placeholder row's button: open the dialog seeded with that namespace's
 * catch-all. Nothing is stored until the administrator saves — a page load
 * must never write configuration.
 */
function handleCreateForNamespace(payload: { selector: string; label: string }): void {
	editingRule.value = {
		id: undefined,
		type: 'include',
		mode: 'auto',
		algos: ['sha1', 'md5'],
		path: '**',
		selector: payload.selector,
		admin_enforced: false,
	}
	saveError.value = null
	showRuleForm.value = true
}

function openEditRule(rule: Rule): void {
	// Defaults are ordinary rules now: same dialog, no locked fields. A
	// deleted shipped default is recreated (disabled) by the repair step.
	editingRule.value = toDraft(rule)
	saveError.value = null
	showRuleForm.value = true
}

function closeRuleForm(): void {
	showRuleForm.value = false
	editingRule.value = null
	saveError.value = null
}

async function handleSaveRule(draft: RuleDraft): Promise<void> {
	const result = await saveRule(draft)

	if (result.success) {
		closeRuleForm()
		toastSuccess('Rule saved.')
	} else {
		saveError.value = result.error || 'Saving failed.'
	}
}

function handleDeleteRule(rule: Rule): void {
	OC.dialogs.confirm(
		'Delete this rule?',
		'Confirm Delete',
		(confirmed: boolean) => {
			if (!confirmed) return
			deleteRule(rule.id).then((result) => {
				if (result.success) {
					toastSuccess('Rule deleted.')
				} else {
					ruleMsg.value = result.error || 'Delete failed.'
				}
			})
		},
		true,
	)
}

async function handleReorder(payload: { selector: string; defaults: boolean; orderedIds: Array<Rule['id']> }): Promise<void> {
	const result = await reorderSegment(payload.selector, payload.defaults, payload.orderedIds)
	if (!result.success) {
		ruleMsg.value = result.error || 'Reorder failed.'
	}
}

async function handleApplyRule(rule: Rule): Promise<void> {
	const result = await applyRule(rule.id)
	if (result.success) {
		toastSuccess('Re-apply queued — the background job takes it from here.')
	} else {
		ruleMsg.value = result.error || 'Re-apply failed.'
	}
}

async function handleToggleRule(rule: Rule): Promise<void> {
	const result = await toggleRule(rule.id, !rule.enabled)
	if (result.success) {
		toastSuccess(rule.enabled ? 'Rule disabled.' : 'Rule enabled.')
	} else {
		ruleMsg.value = result.error || 'Toggle failed.'
	}
}

loadStatus()
loadRules().then(() => {
	rulesLoaded.value = true
})
</script>

<template>
	<div>
		<!-- Documentation is the last tab, and stays so: a new tab goes in
		     front of it. App.spec.ts holds the page to that. -->
		<div class="fcias-tabs" role="tablist">
			<button
				type="button"
				class="fcias-tab"
				:class="{ 'is-active': activeTab === 'settings' }"
				role="tab"
				:aria-selected="activeTab === 'settings'"
				aria-controls="fcias-tab-panel-settings"
				@click="setTab('settings')">
				Settings
			</button>
			<button
				type="button"
				class="fcias-tab"
				:class="{ 'is-active': activeTab === 'permissions' }"
				role="tab"
				:aria-selected="activeTab === 'permissions'"
				aria-controls="fcias-tab-panel-permissions"
				@click="setTab('permissions')">
				Permissions
			</button>
			<button
				type="button"
				class="fcias-tab"
				:class="{ 'is-active': activeTab === 'tokens' }"
				role="tab"
				:aria-selected="activeTab === 'tokens'"
				aria-controls="fcias-tab-panel-tokens"
				@click="setTab('tokens')">
				Sudo tokens
			</button>
			<button
				type="button"
				class="fcias-tab"
				:class="{ 'is-active': activeTab === 'advanced' }"
				role="tab"
				:aria-selected="activeTab === 'advanced'"
				aria-controls="fcias-tab-panel-advanced"
				@click="setTab('advanced')">
				Advanced
			</button>
			<button
				type="button"
				class="fcias-tab"
				:class="{ 'is-active': activeTab === 'docs' }"
				role="tab"
				:aria-selected="activeTab === 'docs'"
				aria-controls="fcias-tab-panel-docs"
				@click="setTab('docs')">
				Documentation
			</button>
		</div>

		<div
			v-if="activeTab === 'settings'"
			id="fcias-tab-panel-settings"
			class="fcias-tab-panel"
			role="tabpanel">
			<NcNoteCard
				v-if="showIdleBanner"
				id="fcias-idle-banner"
				type="warning">
				<p>
					<strong>Automatic hashing is inactive.</strong>
					No enabled <em>include</em> rule exists, so no file is hashed until one says so —
					enable the <em>All home folders</em> default in the table below, or create a rule.
					Manual recalculation from the file sidebar keeps working either way.
				</p>
				<p class="fcias-hint">
					The home-folders default covers home folders only: files on external storage and in
					group folders are reached only by the <em>Everything</em> default or by their own
					group-folder or storage rules.
				</p>
				<div class="fcias-idle-banner-actions">
					<NcButton data-action="banner-ack" @click="handleAcknowledgeBanner">
						Acknowledged
					</NcButton>
					<NcButton data-action="banner-close" variant="tertiary" @click="bannerClosed = true">
						Close
					</NcButton>
				</div>
			</NcNoteCard>

			<div class="fcias-section">
				<h4>Hash Algorithms</h4>
				<p class="fcias-hint">
					Which algorithms this server computes, from what its PHP provides, and which of them is the
					default. Rules may only use these, and every picker in the app offers exactly these.
				</p>
				<AlgorithmSection />
			</div>

			<div class="fcias-section">
				<h4>Rules</h4>
				<p class="fcias-hint">
					Which algorithms are computed for which files, on real-time file events.
				</p>

				<p class="fcias-hint">
					Evaluated top to bottom — the first matching rule decides the file. A rule's band follows
					from its scope and whether it is enforced, so enforced rules always precede users' own
					rules, and the catch-all default is last. Drag a rule by its handle to reorder it
					<em>within</em> its band; to move it between bands, change its scope or its Enforced flag.
				</p>

				<div id="fcias-rules-list">
					<RuleTable
						:rules="rules"
						variant="admin"
						:group-folders-label="groupFoldersLabel"
						:group-folders-available="groupFoldersAvailable"
						:available-group-folders="availableGroupFolders"
						:available-storages="availableStorages"
						:reorderable="true"
						empty-text="No rules yet."
						@edit="openEditRule"
						@toggle="handleToggleRule"
						@apply="handleApplyRule"
						@delete="handleDeleteRule"
						@create="handleCreateForNamespace"
						@reorder="handleReorder" />
				</div>

				<button id="fcias-btn-add-rule" class="fcias-btn" @click="openAddRule">
					Add Rule
				</button>
				<RuleForm
					v-if="showRuleForm"
					:rule="editingRule"
					variant="admin"
					:error-message="saveError"
					:supported-algos="supportedAlgos"
					:available-users="availableUsers"
					:available-groups="availableGroups"
					:group-folders-available="groupFoldersAvailable"
					:group-folders-label="groupFoldersLabel"
					:available-group-folders="availableGroupFolders"
					@save="handleSaveRule"
					@cancel="closeRuleForm" />

				<div id="fcias-rules-msg">
					<p v-if="ruleMsg" class="fcias-error">
						{{ ruleMsg }}
					</p>
				</div>
			</div>
		</div>

		<!-- Every permission, each a "Who may …" in the same shape: a switch
		     for everyone, and groups and users when the switch is off. -->
		<div
			v-if="activeTab === 'permissions'"
			id="fcias-tab-panel-permissions"
			class="fcias-tab-panel"
			role="tabpanel">
			<div class="fcias-section">
				<h4>Who may calculate by hand</h4>
				<p class="fcias-hint">
					Triggering a computation of one's own files — the sidebar's Recalculate buttons and the API's
					recalc route — on top of owning them. Reading what is already computed is untouched; an account
					not named here simply does not see the buttons.
				</p>
				<PermissionSection
					permission="manual_recalc"
					switch-label="Allow all users to calculate by hand"
					:help="PERMISSION_HELP.manual_recalc" />
			</div>

			<div class="fcias-section">
				<h4>Who may edit rules</h4>
				<p class="fcias-hint">
					Creating and editing rules for folders one can write to. An administrator always may; a rule an
					administrator has enforced is read-only for everyone else regardless.
				</p>
				<PermissionSection
					permission="rule_editing"
					switch-label="Allow all users to edit rules"
					:help="PERMISSION_HELP.rule_editing" />
			</div>

			<div class="fcias-section">
				<h4>Who may look across accounts</h4>
				<p class="fcias-hint">
					The sudoers: members of the admin group always, plus the groups and users selected here. Looking
					across accounts still costs a password confirmation each time; this only says who may be asked.
				</p>
				<PermissionSection
					permission="instance_view"
					switch-label="Allow all users to look across accounts"
					:help="PERMISSION_HELP.instance_view" />
			</div>

			<div class="fcias-section">
				<h4>Who may use the API</h4>
				<p class="fcias-hint">
					Scripts and other apps calling this app's public API with an app password. The bundled pages —
					this one, the Duplicates page, the file sidebar — keep working for everyone; this decides who may
					reach the same routes from outside them.
				</p>
				<PermissionSection
					permission="api_access"
					switch-label="Allow all users to use the API"
					:help="PERMISSION_HELP.api_access" />
			</div>
		</div>

		<!-- Diagnostics, read-only: what the instance holds and what its
		     jobs last did. The idle banner is not here — it points at a rule,
		     and lives with the rules on Settings. -->
		<div
			v-if="activeTab === 'advanced'"
			id="fcias-tab-panel-advanced"
			class="fcias-tab-panel"
			role="tabpanel">
			<div class="fcias-section">
				<h4 class="fcias-status-header">
					<span>Status Info</span>
					<button id="fcias-btn-refresh-status"
						class="fcias-btn"
						@click="loadStatus">
						Refresh
					</button>
				</h4>
				<table class="grid fcias-status-table">
					<tbody>
						<tr>
							<td>App Version</td>
							<td id="fcias-status-version">
								{{ status.version || '—' }}
							</td>
						</tr>
						<tr>
							<td>Database Version</td>
							<td id="fcias-status-dbversion">
								{{ status.dbVersion || '—' }}
							</td>
						</tr>
						<tr>
							<td>Indexed Hashes</td>
							<td id="fcias-status-rowcount">
								{{ status.rowCount || 0 }}
							</td>
						</tr>
						<tr>
							<td>Pending Updates</td>
							<td id="fcias-status-pending">
								<template v-if="pendingTotal(status.pendingStats) === 0">
									Total: 0<br>None
								</template>
								<template v-else>
									Total: {{ pendingTotal(status.pendingStats) }}<br>
									<template v-for="(count, mode) in status.pendingStats" :key="mode">
										{{ mode }}: {{ count }}<br>
									</template>
								</template>
							</td>
						</tr>
						<tr>
							<td>Untrusted Hashes</td>
							<td id="fcias-status-untrusted">
								<template v-if="pendingTotal(status.staleStats) === 0">
									Total: 0<br>None
								</template>
								<template v-else>
									Total: {{ pendingTotal(status.staleStats) }}<br>
									<template v-for="(count, state) in status.staleStats" :key="state">
										{{ staleReason(String(state)).label }}: {{ count }}
										<span class="fcias-hint">— {{ staleReason(String(state)).hint }}</span><br>
									</template>
								</template>
							</td>
						</tr>
						<tr>
							<td>Background Jobs</td>
							<td id="fcias-status-jobs">
								<div v-if="jobRows.length" class="fcias-job-grid">
									<template v-for="job in jobRows" :key="job.key">
										<span>{{ job.label }}</span>
										<span class="fcias-job-time">{{ job.time }}</span>
										<span>{{ job.countsText }}</span>
									</template>
								</div>
								<template v-else>
									—
								</template>
							</td>
						</tr>
						<tr>
							<td>Last Updated</td>
							<td id="fcias-status-lastupdated">
								<div class="fcias-job-grid">
									<span />
									<span class="fcias-job-time">{{ lastUpdated || '—' }}</span>
									<span />
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<TunablesSection />
		</div>

		<div
			v-if="activeTab === 'docs'"
			id="fcias-tab-panel-docs"
			class="fcias-tab-panel"
			role="tabpanel">
			<DocsViewer />
		</div>

		<div
			v-if="activeTab === 'tokens'"
			id="fcias-tab-panel-tokens"
			class="fcias-tab-panel"
			role="tabpanel">
			<SudoTokensTab />
		</div>
	</div>
</template>
