<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Root component for the admin settings page.
 */

import { ref, watch } from 'vue'
import { toAlgoOptions } from '../algorithms'
import AlgoMultiselect from '../settings-vue/AlgoMultiselect.vue'
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
	definitions,
	supportedAlgos,
	availableUsers,
	loadStatus,
	loadDefinitions,
	globalRule,
	additionalRules,
	saveGlobalRule,
	saveRule,
	deleteRule,
	toggleRule,
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

// --- Global rule (priority 0) ---

const globalMode = ref('auto')
const globalAlgos = ref<string[]>(['sha1'])
const globalAdminEnforced = ref(false)

watch(definitions, () => {
	const g = globalRule()
	globalMode.value = g?.mode || 'auto'
	globalAlgos.value = g?.algos?.length ? g.algos.slice() : ['sha1']
	globalAdminEnforced.value = g?.admin_enforced === true
}, { immediate: true })

async function saveGlobalRuleForm(): Promise<void> {
	const g = globalRule()
	const result = await saveGlobalRule({
		id: g?.id,
		mode: globalMode.value,
		algos: globalAlgos.value,
		admin_enforced: globalAdminEnforced.value,
	})
	if (result.success) {
		OC.Notification.showTemporary('Global rule saved.')
	} else {
		ruleMsg.value = result.error || 'Save failed.'
	}
}

async function toggleGlobalRule(): Promise<void> {
	const g = globalRule()
	if (!g) {
		await saveGlobalRuleForm()
		return
	}
	const result = await toggleRule(g.id, !g.enabled)
	if (!result.success) {
		ruleMsg.value = result.error || 'Toggle failed.'
	}
}

// --- Additional rules ---

const showRuleForm = ref(false)
const editingRule = ref<RuleDraft | null>(null)

function openAddRule(): void {
	editingRule.value = null
	showRuleForm.value = true
}

function openEditRule(rule: Rule): void {
	editingRule.value = {
		id: rule.id,
		mode: rule.mode,
		algos: rule.algos,
		path: rule.path,
		userScope: rule.userScope,
		admin_enforced: rule.admin_enforced,
	}
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
				<table class="grid">
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
				<p class="fcias-hint">The global default for real-time file events.</p>

				<div id="fcias-global-rule" class="fcias-global-rule-form">
					<h5>Global Rule (priority 0)</h5>
					<div class="fcias-cron-form-row">
						<label>User Scope</label>
						<span>all</span>
					</div>
					<div class="fcias-cron-form-row">
						<label>Path</label>
						<span>**</span>
					</div>
					<div class="fcias-cron-form-row">
						<label>Algorithms</label>
						<div class="fcias-algo-select">
							<AlgoMultiselect
								:initial="globalAlgos"
								:options="toAlgoOptions(supportedAlgos)"
								:on-change="(v: string[]) => { globalAlgos = v }" />
						</div>
					</div>
					<div class="fcias-cron-form-row">
						<label for="fcias-global-mode">Mode</label>
						<select id="fcias-global-mode" v-model="globalMode">
							<option value="auto">Auto (recalc existing only if stale)</option>
							<option value="missing">Missing (recalc existing + missing)</option>
							<option value="force">Force (delete all, recalc all)</option>
							<option value="lazy">Lazy (delete hashes, recalc later)</option>
						</select>
					</div>
					<div class="fcias-cron-form-row">
						<label>Status</label>
						<span :class="globalRule()?.enabled !== false ? 'fcias-compat-pass' : 'fcias-compat-fail'">
							{{ globalRule()?.enabled !== false ? 'Enabled' : 'Disabled' }}
						</span>
					</div>
					<div class="fcias-cron-form-row">
						<label for="fcias-global-admin-enforced">Users may not edit this rule</label>
						<input id="fcias-global-admin-enforced" v-model="globalAdminEnforced" type="checkbox">
					</div>
					<div class="fcias-cron-form-actions">
						<button id="fcias-btn-save-global" class="fcias-btn" @click="saveGlobalRuleForm">
							Save Global Rule
						</button>
						<button id="fcias-btn-toggle-global" class="fcias-btn" @click="toggleGlobalRule">
							{{ globalRule()?.enabled !== false ? 'Disable' : 'Enable' }}
						</button>
					</div>
				</div>

				<h5>Additional Rules</h5>
				<p class="fcias-hint">Rules are processed in order — each file is handled by the first matching rule.</p>

				<div id="fcias-cron-list">
					<RuleTable
						:rules="additionalRules()"
						variant="admin"
						@edit="openEditRule"
						@toggle="handleToggleRule"
						@delete="handleDeleteRule" />
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
