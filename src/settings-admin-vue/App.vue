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
import PermissionSection from './PermissionSection.vue'
import DocsViewer from '../docs-vue/DocsViewer.vue'
import { useAdminSettings } from './composables/useAdminSettings'

declare const OC: {
	Notification: { showTemporary: (msg: string) => void }
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
	definitions,
	loadStatus,
	acknowledgeIdleBanner,
	loadDefinitions,
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
		&& !definitions.value.some((rule) => rule.enabled && (rule.type ?? 'include') === 'include'),
)

const showIdleBanner = computed(
	() => hashingIdle.value
		&& !status.value.idleBannerAcknowledged
		&& !bannerClosed.value,
)

async function handleAcknowledgeBanner(): Promise<void> {
	if (await acknowledgeIdleBanner()) {
		OC.Notification.showTemporary('Noted — the banner stays away until a rule is enabled and disabled again.')
	}
}

function tabFromHash(): 'settings' | 'docs' {
	const tab = window.location.hash.replace(/^#/, '').split('/')[0]
	return tab === 'docs' ? 'docs' : 'settings'
}

const activeTab = ref<'settings' | 'docs'>(tabFromHash())

function setTab(tab: 'settings' | 'docs'): void {
	activeTab.value = tab
	window.location.hash = tab
}

window.addEventListener('hashchange', () => {
	activeTab.value = tabFromHash()
})

const ruleMsg = ref('')

const pendingTotal = (stats: Record<string, number> = {}) => Object.values(stats).reduce((sum, v) => sum + v, 0)

// --- Rule editing (global + additional rules share one dialog) ---

const showRuleForm = ref(false)
const editingRule = ref<RuleDraft | null>(null)

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
	showRuleForm.value = true
}

function openEditRule(rule: Rule): void {
	// Defaults are ordinary rules now: same dialog, no locked fields. A
	// deleted shipped default is recreated (disabled) by the repair step.
	editingRule.value = toDraft(rule)
	showRuleForm.value = true
}

function closeRuleForm(): void {
	showRuleForm.value = false
	editingRule.value = null
}

async function handleSaveRule(draft: RuleDraft): Promise<void> {
	const result = await saveRule(draft)

	if (result.success) {
		closeRuleForm()
		OC.Notification.showTemporary('Rule saved.')
	} else {
		ruleMsg.value = result.error || 'Save failed.'
	}
}

function handleDeleteRule(rule: Rule): void {
	OC.dialogs.confirm(
		'Delete this rule definition?',
		'Confirm Delete',
		(confirmed: boolean) => {
			if (!confirmed) return
			deleteRule(rule.id).then((result) => {
				if (result.success) {
					OC.Notification.showTemporary('Rule deleted.')
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
		OC.Notification.showTemporary('Re-apply queued — the background job takes it from here.')
	} else {
		ruleMsg.value = result.error || 'Re-apply failed.'
	}
}

async function handleToggleRule(rule: Rule): Promise<void> {
	const result = await toggleRule(rule.id, !rule.enabled)
	if (result.success) {
		OC.Notification.showTemporary(rule.enabled ? 'Rule disabled.' : 'Rule enabled.')
	} else {
		ruleMsg.value = result.error || 'Toggle failed.'
	}
}

loadStatus()
loadDefinitions().then(() => {
	rulesLoaded.value = true
})
</script>

<template>
	<div>
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
				<h4>
					Status
					<button id="fcias-btn-refresh-status"
						class="fcias-btn"
						style="margin-left:12px"
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
							<td>Last Updated</td>
							<td id="fcias-status-lastupdated">
								{{ lastUpdated || '—' }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="fcias-section">
				<h4>Rule Editing Permission</h4>
				<p class="fcias-hint">
					Users in the selected groups, the selected users, or everyone (when enabled) may edit and create rules
					for folders they can write to.
				</p>
				<PermissionSection />
			</div>

			<div class="fcias-section">
				<h4>Rule Definitions</h4>
				<p class="fcias-hint">
					Which algorithms are computed for which files, on real-time file events.
				</p>

				<p class="fcias-hint">
					Evaluated top to bottom — the first matching rule decides the file. A rule's band follows
					from its scope and whether it is enforced, so enforced rules always precede users' own
					rules, and the catch-all default is last. Drag a rule by its handle to reorder it
					<em>within</em> its band; to move it between bands, change its scope or its Enforced flag.
				</p>

				<div id="fcias-cron-list">
					<RuleTable
						:rules="definitions"
						variant="admin"
						:group-folders-label="groupFoldersLabel"
						:reorderable="true"
						empty-text="No rules yet."
						@edit="openEditRule"
						@toggle="handleToggleRule"
						@apply="handleApplyRule"
						@delete="handleDeleteRule"
						@reorder="handleReorder" />
				</div>

				<button id="fcias-btn-add-definition" class="fcias-btn" @click="openAddRule">
					Add Rule
				</button>
				<RuleForm
					v-if="showRuleForm"
					:rule="editingRule"
					variant="admin"
					:supported-algos="supportedAlgos"
					:available-users="availableUsers"
					:available-groups="availableGroups"
					:group-folders-available="groupFoldersAvailable"
					:group-folders-label="groupFoldersLabel"
					:available-group-folders="availableGroupFolders"
					@save="handleSaveRule"
					@cancel="closeRuleForm" />

				<div id="fcias-cron-msg">
					<p v-if="ruleMsg" class="fcias-error">
						{{ ruleMsg }}
					</p>
				</div>
			</div>
		</div>

		<div
			v-if="activeTab === 'docs'"
			id="fcias-tab-panel-docs"
			class="fcias-tab-panel"
			role="tabpanel">
			<DocsViewer />
		</div>
	</div>
</template>
