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
import { t } from '../l10n'

/** The words each permission section shows; the component is the same. */
const PERMISSION_HELP = {
	rule_editing: {
		allowAll: t('file_checksum_search', 'When on, every account may create and edit rules for folders in their own files that they can write to. When off, only the groups and individual accounts you select may.'),
		groups: t('file_checksum_search', 'Members of these groups may create and edit rules for folders in their own files that they can write to. Selected groups and selected accounts are combined — being in either is enough.'),
		users: t('file_checksum_search', 'Individual accounts that may create and edit rules for folders in their own files that they can write to, in addition to the members of any selected groups.'),
	},
	manual_recalc: {
		allowAll: t('file_checksum_search', 'When on, every account may recalculate checksums by hand. When off, only the groups and individual accounts selected here may; everyone still sees the checksums already computed.'),
		groups: t('file_checksum_search', 'Members of these groups may recalculate checksums by hand. Selected groups and selected accounts are combined — being in either is enough.'),
		users: t('file_checksum_search', 'Individual accounts that may recalculate checksums by hand, in addition to the members of any selected groups.'),
	},
	api_access: {
		allowAll: t('file_checksum_search', 'When on, every account may call the public API with an app password. When off, only the groups and individual accounts selected here may; the app\'s own pages keep working for everyone.'),
		groups: t('file_checksum_search', 'Members of these groups may call the public API with an app password. Selected groups and selected accounts are combined — being in either is enough.'),
		users: t('file_checksum_search', 'Individual accounts that may call the public API with an app password, in addition to the members of any selected groups.'),
	},
	instance_view: {
		allowAll: t('file_checksum_search', 'When on, every account may look across all accounts after confirming their password. When off, only members of the admin group and the groups and accounts selected here may.'),
		groups: t('file_checksum_search', 'Members of these groups may look across accounts once they have confirmed their password. Selected groups and selected accounts are combined — being in either is enough.'),
		users: t('file_checksum_search', 'Individual accounts that may look at other accounts\' files once they have confirmed their password, in addition to the members of any selected groups and of the admin group.'),
	},
}

/** The idle banner's text, whole sentences so a translation can reorder them. */
const IDLE_BANNER = {
	lead: t('file_checksum_search', 'Automatic hashing is inactive.'),
	// TRANSLATORS: "All home folders" is a scope as the rule table shows it, "Include" a rule type as the dialog shows it; translate them as those do
	body: t('file_checksum_search', 'No "Include" rule is enabled, so no file is hashed automatically. Enable the "All home folders" default in the table below, or add a rule. Recalculating by hand from the file sidebar still works.'),
	// TRANSLATORS: "All home folders" and "Everything" are scopes as the rule table shows them; translate them as those do
	scope: t('file_checksum_search', 'The "All home folders" default covers home folders only: files on external storage and in team folders are reached only by the "Everything" default or by their own team-folder or storage rules.'),
}

/** The sections' explanations, too long to sit in the template. */
const HINTS = {
	algorithms: t('file_checksum_search', 'Which algorithms this server computes, from what its PHP provides, and which of them is the default. Rules may only use these, and every picker in the app offers exactly these.'),
	rules: t('file_checksum_search', 'Which files get checksums, with which algorithms, and which are left alone. Rules act when a file is created or changed, and a background job applies them to existing files after a few minutes.'),
	// TRANSLATORS: "Enforced" is a field of the rule dialog and a column of the rule table; translate it as those do
	order: t('file_checksum_search', 'Evaluated top to bottom — the first matching rule decides the file. A rule\'s band follows from its scope and whether it is enforced, so enforced rules always precede personal rules, and the catch-all default is last. Drag a rule by its handle to reorder it among the rules of the same scope; to move it to another band, change its scope or its "Enforced" setting.'),
	// TRANSLATORS: "Recalculate" is a button of the file sidebar, "Verify" and "Verify all" buttons of the Duplicates page; translate them as those buttons do
	manualRecalc: t('file_checksum_search', 'Recalculating checksums by hand: the sidebar\'s "Recalculate" buttons, "Verify" and "Verify all" on the Duplicates page, and the API\'s recalculation routes, for any file the account can reach. Reading checksums already computed is not affected. Without this permission neither the sidebar nor the Duplicates page shows the buttons, and the API refuses the request.'),
	ruleEditing: t('file_checksum_search', 'Creating and editing rules for folders in one\'s own files that one can write to. An administrator always may; a rule an administrator has enforced is read-only for everyone else regardless.'),
	instanceView: t('file_checksum_search', 'The sudoers: members of the admin group always, plus the groups and accounts selected here. A group admin may also look at the members of the groups they administer without being selected. Looking across accounts still needs a password confirmation, which holds for 30 minutes, or a granted app password; this only says who may be asked.'),
	apiAccess: t('file_checksum_search', 'Scripts and other apps calling this app\'s public API with an app password. The bundled pages — this one, the Duplicates page, the file sidebar — keep working for everyone; this decides who may reach the same routes from outside them.'),
}

declare const OC: {
	dialogs: { confirm: (text: string, title: string, callback: (confirmed: boolean) => void, modal?: boolean) => void }
}

const {
	status,
	statusError,
	lastUpdated,
	supportedAlgos,
	defaultAlgo,
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
		// TRANSLATORS: "Include" is a rule type as the rule dialog shows it; translate it as that does
		toastSuccess(t('file_checksum_search', 'Noted — the banner stays hidden until an "Include" rule is enabled and then disabled again.'))
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
		// TRANSLATORS: the state of checksums deleted because no rule maintains them any more
		label: t('file_checksum_search', 'Eroded'),
		hint: t('file_checksum_search', 'the file changed while no rule maintained its checksums, so they were deleted; it gets them back once a rule covers it again'),
	},
	'stale:reset': {
		// TRANSLATORS: the state of checksums a reset has made invalid; a past participle, not the action
		label: t('file_checksum_search', 'Reset'),
		hint: t('file_checksum_search', 'invalidated by a reset: already hidden from search, and pending deletion by the background job'),
	},
}

const staleReason = (state: string) => STALE_REASONS[state] ?? { label: state, hint: '' }

/** Display names for the background jobs' heartbeat lines (D17). */
const JOB_LABELS: Record<string, string> = {
	// TRANSLATORS: a background job's name: every few minutes it applies the rules to the files and queues those that need checksums
	rule_sweep: t('file_checksum_search', 'Rule sweep'),
	// TRANSLATORS: a background job's name: it computes the checksums of the queued files
	pending_drain: t('file_checksum_search', 'Queue drain'),
	// TRANSLATORS: a background job's name: it removes the checksums of files that no longer exist
	orphan_purge: t('file_checksum_search', 'Orphan purge'),
	// TRANSLATORS: a background job's name: it copies the checksums Nextcloud already holds into this app's index.
	filecache_backfill: t('file_checksum_search', 'Checksum copy'),
	// TRANSLATORS: a background job's name: it checks that every stored checksum can be found by a search, and fixes what cannot.
	hash_index_check: t('file_checksum_search', 'Checksum index check'),
}

const jobRows = computed(() => Object.entries(status.value.jobs ?? {}).map(([key, run]) => ({
	key,
	label: JOB_LABELS[key] ?? key,
	// TRANSLATORS: when a background job last ran: not at all
	time: run.lastRun === null ? t('file_checksum_search', 'Not run yet') : new Date(run.lastRun * 1000).toLocaleString(),
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
		// None: the form starts a new rule from the instance's default.
		algos: [],
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
		toastSuccess(t('file_checksum_search', 'Rule saved.'))
	} else {
		saveError.value = result.error || t('file_checksum_search', 'Could not save the rule.')
	}
}

function handleDeleteRule(rule: Rule): void {
	OC.dialogs.confirm(
		t('file_checksum_search', 'Delete this rule?'),
		t('file_checksum_search', 'Delete rule'),
		(confirmed: boolean) => {
			if (!confirmed) return
			deleteRule(rule.id).then((result) => {
				if (result.success) {
					toastSuccess(t('file_checksum_search', 'Rule deleted.'))
				} else {
					ruleMsg.value = result.error || t('file_checksum_search', 'Could not delete the rule.')
				}
			})
		},
		true,
	)
}

async function handleReorder(payload: { selector: string; defaults: boolean; orderedIds: Array<Rule['id']> }): Promise<void> {
	const result = await reorderSegment(payload.selector, payload.defaults, payload.orderedIds)
	if (!result.success) {
		ruleMsg.value = result.error || t('file_checksum_search', 'Could not reorder the rules.')
	}
}

async function handleApplyRule(rule: Rule): Promise<void> {
	const result = await applyRule(rule.id)
	if (result.success) {
		toastSuccess(t('file_checksum_search', 'Reapply queued — a background job will go through the rule\'s files.'))
	} else {
		ruleMsg.value = result.error || t('file_checksum_search', 'Could not reapply the rule.')
	}
}

async function handleToggleRule(rule: Rule): Promise<void> {
	const result = await toggleRule(rule.id, !rule.enabled)
	if (result.success) {
		toastSuccess(rule.enabled ? t('file_checksum_search', 'Rule disabled.') : t('file_checksum_search', 'Rule enabled.'))
	} else {
		ruleMsg.value = result.error || t('file_checksum_search', 'Could not enable or disable the rule.')
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
				{{ t('file_checksum_search', 'Settings') }}
			</button>
			<button
				type="button"
				class="fcias-tab"
				:class="{ 'is-active': activeTab === 'permissions' }"
				role="tab"
				:aria-selected="activeTab === 'permissions'"
				aria-controls="fcias-tab-panel-permissions"
				@click="setTab('permissions')">
				{{ t('file_checksum_search', 'Permissions') }}
			</button>
			<button
				type="button"
				class="fcias-tab"
				:class="{ 'is-active': activeTab === 'tokens' }"
				role="tab"
				:aria-selected="activeTab === 'tokens'"
				aria-controls="fcias-tab-panel-tokens"
				@click="setTab('tokens')">
				{{ t('file_checksum_search', 'Sudo tokens') }}
			</button>
			<button
				type="button"
				class="fcias-tab"
				:class="{ 'is-active': activeTab === 'advanced' }"
				role="tab"
				:aria-selected="activeTab === 'advanced'"
				aria-controls="fcias-tab-panel-advanced"
				@click="setTab('advanced')">
				{{ t('file_checksum_search', 'Advanced') }}
			</button>
			<button
				type="button"
				class="fcias-tab"
				:class="{ 'is-active': activeTab === 'docs' }"
				role="tab"
				:aria-selected="activeTab === 'docs'"
				aria-controls="fcias-tab-panel-docs"
				@click="setTab('docs')">
				{{ t('file_checksum_search', 'Documentation') }}
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
					<strong>{{ IDLE_BANNER.lead }}</strong>
					{{ IDLE_BANNER.body }}
				</p>
				<p class="fcias-hint">
					{{ IDLE_BANNER.scope }}
				</p>
				<div class="fcias-idle-banner-actions">
					<NcButton data-action="banner-ack" @click="handleAcknowledgeBanner">
						<!-- TRANSLATORS: a button: hides the banner until an include rule is enabled and then disabled again -->
						{{ t('file_checksum_search', 'Acknowledge') }}
					</NcButton>
					<NcButton data-action="banner-close" variant="tertiary" @click="bannerClosed = true">
						{{ t('file_checksum_search', 'Close') }}
					</NcButton>
				</div>
			</NcNoteCard>

			<div class="fcias-section">
				<h4>{{ t('file_checksum_search', 'Checksum algorithms') }}</h4>
				<p class="fcias-hint">
					{{ HINTS.algorithms }}
				</p>
				<AlgorithmSection />
			</div>

			<div class="fcias-section">
				<h4>{{ t('file_checksum_search', 'Rules') }}</h4>
				<p class="fcias-hint">
					{{ HINTS.rules }}
				</p>

				<p class="fcias-hint">
					{{ HINTS.order }}
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
						:empty-text="t('file_checksum_search', 'No rules yet.')"
						@edit="openEditRule"
						@toggle="handleToggleRule"
						@apply="handleApplyRule"
						@delete="handleDeleteRule"
						@create="handleCreateForNamespace"
						@reorder="handleReorder" />
				</div>

				<button id="fcias-btn-add-rule" class="fcias-btn" @click="openAddRule">
					{{ t('file_checksum_search', 'Add rule') }}
				</button>
				<RuleForm
					v-if="showRuleForm"
					:rule="editingRule"
					variant="admin"
					:error-message="saveError"
					:supported-algos="supportedAlgos"
					:default-algo="defaultAlgo"
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
				<h4>{{ t('file_checksum_search', 'Who may recalculate checksums') }}</h4>
				<p class="fcias-hint">
					{{ HINTS.manualRecalc }}
				</p>
				<PermissionSection
					permission="manual_recalc"
					:switch-label="t('file_checksum_search', 'Allow all accounts to recalculate checksums')"
					:help="PERMISSION_HELP.manual_recalc" />
			</div>

			<div class="fcias-section">
				<h4>{{ t('file_checksum_search', 'Who may edit rules') }}</h4>
				<p class="fcias-hint">
					{{ HINTS.ruleEditing }}
				</p>
				<PermissionSection
					permission="rule_editing"
					:switch-label="t('file_checksum_search', 'Allow all accounts to edit rules')"
					:help="PERMISSION_HELP.rule_editing" />
			</div>

			<div class="fcias-section">
				<h4>{{ t('file_checksum_search', 'Who may look across accounts') }}</h4>
				<p class="fcias-hint">
					{{ HINTS.instanceView }}
				</p>
				<PermissionSection
					permission="instance_view"
					:switch-label="t('file_checksum_search', 'Allow all accounts to look across accounts')"
					:help="PERMISSION_HELP.instance_view" />
			</div>

			<div class="fcias-section">
				<h4>{{ t('file_checksum_search', 'Who may use the API') }}</h4>
				<p class="fcias-hint">
					{{ HINTS.apiAccess }}
				</p>
				<PermissionSection
					permission="api_access"
					:switch-label="t('file_checksum_search', 'Allow all accounts to use the API')"
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
					<span>{{ t('file_checksum_search', 'Status') }}</span>
					<button id="fcias-btn-refresh-status"
						class="fcias-btn"
						@click="loadStatus">
						{{ t('file_checksum_search', 'Refresh') }}
					</button>
				</h4>
				<p v-if="statusError" id="fcias-status-error" class="fcias-error">
					{{ statusError }}
				</p>
				<table class="grid fcias-status-table">
					<tbody>
						<tr>
							<td>{{ t('file_checksum_search', 'App version') }}</td>
							<td id="fcias-status-version">
								{{ status.version || '—' }}
							</td>
						</tr>
						<tr>
							<td>{{ t('file_checksum_search', 'Database version') }}</td>
							<td id="fcias-status-dbversion">
								{{ status.dbVersion || '—' }}
							</td>
						</tr>
						<tr>
							<td>{{ t('file_checksum_search', 'Indexed checksums') }}</td>
							<td id="fcias-status-rowcount">
								{{ status.rowCount || 0 }}
							</td>
						</tr>
						<tr>
							<!-- TRANSLATORS: files waiting for the background job to compute their checksums, counted by mode -->
							<td>{{ t('file_checksum_search', 'Queued files') }}</td>
							<td id="fcias-status-pending">
								<template v-if="pendingTotal(status.pendingStats) === 0">
									{{ t('file_checksum_search', 'Total: {count}', { count: 0 }) }}<br>
									{{ t('file_checksum_search', 'None') }}
								</template>
								<template v-else>
									{{ t('file_checksum_search', 'Total: {count}', { count: pendingTotal(status.pendingStats) }) }}<br>
									<template v-for="(count, mode) in status.pendingStats" :key="mode">
										{{ mode }}: {{ count }}<br>
									</template>
								</template>
							</td>
						</tr>
						<tr>
							<td>{{ t('file_checksum_search', 'Untrusted checksums') }}</td>
							<td id="fcias-status-untrusted">
								<template v-if="pendingTotal(status.staleStats) === 0">
									{{ t('file_checksum_search', 'Total: {count}', { count: 0 }) }}<br>
									{{ t('file_checksum_search', 'None') }}
								</template>
								<template v-else>
									{{ t('file_checksum_search', 'Total: {count}', { count: pendingTotal(status.staleStats) }) }}<br>
									<template v-for="(count, state) in status.staleStats" :key="state">
										{{ staleReason(String(state)).label }}: {{ count }}
										<span class="fcias-hint">— {{ staleReason(String(state)).hint }}</span><br>
									</template>
								</template>
							</td>
						</tr>
						<tr>
							<td>{{ t('file_checksum_search', 'Background jobs') }}</td>
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
							<td>{{ t('file_checksum_search', 'Last updated') }}</td>
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
