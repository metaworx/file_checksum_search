<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Root component for the personal settings page.
 */

import { ref } from 'vue'
import RuleTable from '../rules-vue/RuleTable.vue'
import RuleForm from '../rules-vue/RuleForm.vue'
import type { Rule, RuleDraft } from '../rules-vue/types'
import DocsViewer from '../docs-vue/DocsViewer.vue'
import PreferenceSection from './PreferenceSection.vue'
import { OCS_ADMIN } from '../routes'
import { useRules } from '../rules-vue/composables/useRules'

declare const OC: {
	Notification: { showTemporary: (msg: string) => void }
	dialogs: { confirm: (text: string, title: string, callback: (confirmed: boolean) => void, modal?: boolean) => void }
}

const {
	rules,
	canCreate,
	supportedAlgos,
	loadRules,
	saveRule,
	deleteRule,
	toggleRule,
	applyRule,
	reorderSegment,
} = useRules('own')

function tabFromHash(): 'rules' | 'help' {
	const tab = window.location.hash.replace(/^#/, '').split('/')[0]
	// 'faq' still resolves: this tab was called that until the FAQ became the
	// administrator's document, and links to #faq are already out there.
	return tab === 'help' || tab === 'faq' ? 'help' : 'rules'
}

const activeTab = ref<'rules' | 'help'>(tabFromHash())

function setTab(tab: 'rules' | 'help'): void {
	activeTab.value = tab
	window.location.hash = tab
}

window.addEventListener('hashchange', () => {
	activeTab.value = tabFromHash()
})

const ruleMsg = ref('')
const showRuleForm = ref(false)
const editingRule = ref<RuleDraft | null>(null)

/** A failed save's message — rendered inside the dialog, not on the page. */
const saveError = ref<string | null>(null)

function openAddRule(): void {
	editingRule.value = null
	saveError.value = null
	showRuleForm.value = true
}

function openEditRule(rule: Rule): void {
	editingRule.value = {
		id: rule.id,
		type: rule.type ?? 'include',
		mode: rule.mode,
		algos: rule.algos,
		path: rule.path,
		selector: rule.selector,
		admin_enforced: rule.admin_enforced,
	}
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
		OC.Notification.showTemporary('Rule saved.')
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

loadRules()
</script>

<template>
	<div>
		<div class="fcias-tabs" role="tablist">
			<button
				type="button"
				class="fcias-tab"
				:class="{ 'is-active': activeTab === 'rules' }"
				role="tab"
				:aria-selected="activeTab === 'rules'"
				aria-controls="fcias-tab-panel-rules"
				@click="setTab('rules')">
				Rules
			</button>
			<button
				type="button"
				class="fcias-tab"
				:class="{ 'is-active': activeTab === 'help' }"
				role="tab"
				:aria-selected="activeTab === 'help'"
				aria-controls="fcias-tab-panel-help"
				@click="setTab('help')">
				Help
			</button>
		</div>

		<div
			v-if="activeTab === 'rules'"
			id="fcias-tab-panel-rules"
			class="fcias-tab-panel"
			role="tabpanel">
			<h4>Rules applying to your files</h4>

			<p class="fcias-hint">
				Every rule that can affect your files, in the order they are evaluated — the first match
				decides. Rules an administrator enforced come first and are read-only; your own rules come
				next and are yours to edit and reorder; the defaults below them apply only where none of
				your rules matched. You can create rules only if you have been given permission and the
				path is in a folder you can write to.
			</p>

			<PreferenceSection :algorithms="supportedAlgos" />
			<div id="fcias-personal-msg">
				<p v-if="ruleMsg" class="fcias-error">
					{{ ruleMsg }}
				</p>
			</div>

			<div id="fcias-personal-rules">
				<RuleTable
					:rules="rules"
					variant="personal"
					:can-edit-any="canCreate"
					:reorderable="true"
					@edit="openEditRule"
					@toggle="handleToggleRule"
					@apply="handleApplyRule"
					@delete="handleDeleteRule"
					@reorder="handleReorder" />
			</div>

			<button v-if="canCreate"
				id="fcias-personal-add"
				class="fcias-btn"
				@click="openAddRule">
				Add Rule
			</button>

			<RuleForm
				v-if="showRuleForm"
				:rule="editingRule"
				variant="personal"
				:error-message="saveError"
				:supported-algos="supportedAlgos"
				@save="handleSaveRule"
				@cancel="closeRuleForm" />
		</div>

		<div
			v-if="activeTab === 'help'"
			id="fcias-tab-panel-help"
			class="fcias-tab-panel"
			role="tabpanel">
			<DocsViewer :endpoint="OCS_ADMIN.getHelp" only="docs/user-guide.md" />
		</div>
	</div>
</template>
