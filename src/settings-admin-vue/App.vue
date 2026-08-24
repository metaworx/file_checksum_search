<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Root component for the admin settings page.
 */

import { ref } from 'vue'
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
	definitions,
	loadStatus,
	loadDefinitions,
	globalRule,
	saveGlobalRule,
	saveRule,
	deleteRule,
	toggleRule,
	reorderBand,
} = useAdminSettings()

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
/** True while the dialog is editing the global rule rather than an additional one. */
const editingGlobal = ref(false)

function toDraft(rule: Rule): RuleDraft {
	return {
		id: rule.id,
		type: rule.type ?? 'include',
		mode: rule.mode,
		algos: rule.algos,
		path: rule.path,
		userScope: rule.userScope,
		admin_enforced: rule.admin_enforced,
		pinned: rule.pinned,
	}
}

function openAddRule(): void {
	editingRule.value = null
	editingGlobal.value = false
	showRuleForm.value = true
}

function openEditRule(rule: Rule): void {
	editingRule.value = toDraft(rule)
	// The catch-all's `**`/`all` reach is what makes it the default, so its
	// dialog locks those two fields wherever it is edited from.
	editingGlobal.value = rule.pinned === true
	showRuleForm.value = true
}

/** No global rule stored yet — open the dialog seeded with its fixed reach. */
function openCreateGlobalRule(): void {
	editingRule.value = {
		id: undefined,
		type: 'include',
		mode: 'auto',
		algos: ['sha1'],
		path: '**',
		userScope: 'all',
		admin_enforced: false,
		pinned: true,
	}
	editingGlobal.value = true
	showRuleForm.value = true
}

function closeRuleForm(): void {
	showRuleForm.value = false
	editingRule.value = null
	editingGlobal.value = false
}

async function handleSaveRule(draft: RuleDraft): Promise<void> {
	// saveGlobalRule pins path/userScope server-side, so a locked-but-tampered
	// form can never narrow the global rule into an ordinary one.
	const result = editingGlobal.value
		? await saveGlobalRule({
			id: draft.id,
			mode: draft.mode,
			algos: draft.algos,
			admin_enforced: draft.admin_enforced,
		})
		: await saveRule(draft)

	if (result.success) {
		const label = editingGlobal.value ? 'Global rule saved.' : 'Rule saved.'
		closeRuleForm()
		OC.Notification.showTemporary(label)
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

async function handleReorder(payload: { band: number; ownerId?: string; orderedIds: Array<Rule['id']> }): Promise<void> {
	const result = await reorderBand(payload.band, payload.orderedIds, payload.ownerId)
	if (!result.success) {
		ruleMsg.value = result.error || 'Reorder failed.'
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
loadDefinitions()
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
			<div class="fcias-section">
				<h4>
					Status
					<button id="fcias-btn-refresh-status" class="fcias-btn" style="margin-left:12px" @click="loadStatus">
						Refresh
					</button>
				</h4>
				<table class="grid fcias-status-table">
					<tbody>
						<tr>
							<td>App Version</td>
							<td id="fcias-status-version">{{ status.version || '—' }}</td>
						</tr>
						<tr>
							<td>Database Version</td>
							<td id="fcias-status-dbversion">{{ status.dbVersion || '—' }}</td>
						</tr>
						<tr>
							<td>Indexed Hashes</td>
							<td id="fcias-status-rowcount">{{ status.rowCount || 0 }}</td>
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
							<td id="fcias-status-lastupdated">{{ lastUpdated || '—' }}</td>
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
						:reorderable="true"
						empty-text="No rules yet."
						@edit="openEditRule"
						@toggle="handleToggleRule"
						@delete="handleDeleteRule"
						@reorder="handleReorder" />
				</div>

				<button id="fcias-btn-add-definition" class="fcias-btn" @click="openAddRule">
					Add Rule
				</button>
				<button
					v-if="globalRule() === null"
					id="fcias-btn-create-global"
					class="fcias-btn"
					@click="openCreateGlobalRule">
					Create Catch-all Default
				</button>

				<RuleForm
					v-if="showRuleForm"
					:rule="editingRule"
					variant="admin"
					:supported-algos="supportedAlgos"
					:available-users="availableUsers"
					:available-groups="availableGroups"
					:lock-scope="editingGlobal"
					:title="editingGlobal ? 'Catch-all default rule' : undefined"
					@save="handleSaveRule"
					@cancel="closeRuleForm" />

				<div id="fcias-cron-msg">
					<p v-if="ruleMsg" class="fcias-error">{{ ruleMsg }}</p>
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
