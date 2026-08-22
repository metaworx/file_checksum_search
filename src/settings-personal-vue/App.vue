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
import { OCS_ADMIN } from '../routes'
import { usePersonalSettings } from './composables/usePersonalSettings'

declare const OC: {
	Notification: { showTemporary: (msg: string) => void }
	dialogs: { confirm: (text: string, title: string, callback: (confirmed: boolean) => void, modal?: boolean) => void }
}

const {
	rules,
	canEditAny,
	supportedAlgos,
	loadRules,
	saveRule,
	deleteRule,
	toggleRule,
} = usePersonalSettings()

function tabFromHash(): 'rules' | 'faq' {
	const tab = window.location.hash.replace(/^#/, '').split('/')[0]
	return tab === 'faq' ? 'faq' : 'rules'
}

const activeTab = ref<'rules' | 'faq'>(tabFromHash())

function setTab(tab: 'rules' | 'faq'): void {
	activeTab.value = tab
	window.location.hash = tab
}

window.addEventListener('hashchange', () => {
	activeTab.value = tabFromHash()
})

const ruleMsg = ref('')
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
				:class="{ 'is-active': activeTab === 'faq' }"
				role="tab"
				:aria-selected="activeTab === 'faq'"
				aria-controls="fcias-tab-panel-faq"
				@click="setTab('faq')">
				FAQ
			</button>
		</div>

		<div
			v-if="activeTab === 'rules'"
			id="fcias-tab-panel-rules"
			class="fcias-tab-panel"
			role="tabpanel">
			<h4>Rules applying to your files</h4>

			<p class="fcias-hint">
				These rules apply to your files. Admin-enforced rules are read-only. You can edit rules only if you are in
				an enabled group and the rule path is in a folder you can write to.
			</p>

			<div id="fcias-personal-msg">
				<p v-if="ruleMsg" class="fcias-error">{{ ruleMsg }}</p>
			</div>

			<div id="fcias-personal-rules">
				<RuleTable
					:rules="rules"
					variant="personal"
					:can-edit-any="canEditAny"
					@edit="openEditRule"
					@toggle="handleToggleRule"
					@delete="handleDeleteRule" />
			</div>

			<button v-if="canEditAny" id="fcias-personal-add" class="fcias-btn" @click="openAddRule">
				Add Rule
			</button>

			<RuleForm
				v-if="showRuleForm"
				:rule="editingRule"
				variant="personal"
				:supported-algos="supportedAlgos"
				@save="handleSaveRule"
				@cancel="closeRuleForm" />
		</div>

		<div
			v-if="activeTab === 'faq'"
			id="fcias-tab-panel-faq"
			class="fcias-tab-panel"
			role="tabpanel">
			<DocsViewer :endpoint="OCS_ADMIN.getHelp" only="docs/FAQ.md" />
		</div>
	</div>
</template>
