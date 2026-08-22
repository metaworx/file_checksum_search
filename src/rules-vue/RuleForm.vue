<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Create/edit form, shared by the admin and personal settings pages.
 * Element ids are kept per-variant and identical to the previous
 * vanilla-JS markup (`#fcias-cron-*` / `#fcias-personal-*`) so the
 * existing Cypress e2e selectors (tests/e2e/rules.cy.js) keep working
 * unmodified.
 */
import { reactive, watch } from 'vue'
import { toAlgoOptions } from '../algorithms'
import AlgoMultiselect from '../settings-vue/AlgoMultiselect.vue'
import type { RuleDraft } from './types'

const props = defineProps<{
	rule: RuleDraft | null
	variant: 'admin' | 'personal'
	supportedAlgos: string[]
	/** Admin variant only: user ids offered in the User Scope select. */
	availableUsers?: string[]
}>()

const emit = defineEmits<{
	(e: 'save', draft: RuleDraft): void
	(e: 'cancel'): void
}>()

const ids = props.variant === 'admin'
	? {
		form: 'fcias-cron-form',
		path: 'fcias-cron-path',
		userscope: 'fcias-cron-userscope',
		algos: 'fcias-cron-algos',
		mode: 'fcias-cron-mode',
		adminEnforced: 'fcias-cron-admin-enforced',
		save: 'fcias-btn-save-definition',
		cancel: 'fcias-btn-cancel-definition',
	}
	: {
		form: 'fcias-personal-form',
		path: 'fcias-personal-path',
		userscope: '',
		algos: 'fcias-personal-algos',
		mode: 'fcias-personal-mode',
		adminEnforced: '',
		save: 'fcias-personal-save',
		cancel: 'fcias-personal-cancel',
	}

const draft = reactive<RuleDraft>({
	id: undefined,
	path: '/',
	mode: 'auto',
	algos: ['sha1'],
	userScope: 'all',
	admin_enforced: false,
})

function seed(rule: RuleDraft | null): void {
	draft.id = rule?.id
	draft.path = rule?.path || '/'
	draft.mode = rule?.mode || 'auto'
	draft.algos = rule?.algos?.length ? rule.algos.slice() : ['sha1']
	draft.userScope = rule?.userScope || 'all'
	draft.admin_enforced = rule?.admin_enforced === true
}

watch(() => props.rule, seed, { immediate: true })

function submit(): void {
	emit('save', { ...draft, algos: draft.algos.slice() })
}
</script>

<template>
	<div :id="ids.form" class="fcias-cron-form">
		<div v-if="variant === 'admin'" class="fcias-cron-form-row">
			<label :for="ids.userscope">User Scope</label>
			<select :id="ids.userscope" v-model="draft.userScope">
				<option value="all">All Users</option>
				<option v-for="uid in availableUsers ?? []" :key="uid" :value="uid">
					{{ uid }}
				</option>
			</select>
		</div>

		<div class="fcias-cron-form-row">
			<label :for="ids.path">Path (glob)</label>
			<input :id="ids.path" v-model="draft.path" type="text" placeholder="/">
		</div>

		<div class="fcias-cron-form-row">
			<label>Algorithms</label>
			<div :id="ids.algos" class="fcias-algo-select">
				<AlgoMultiselect
					:key="draft.id ?? 'new'"
					:initial="draft.algos"
					:options="toAlgoOptions(supportedAlgos)"
					:on-change="(v: string[]) => { draft.algos = v }" />
			</div>
		</div>

		<div class="fcias-cron-form-row">
			<label :for="ids.mode">Mode</label>
			<select :id="ids.mode" v-model="draft.mode">
				<option value="auto">Auto (recalc existing only if stale)</option>
				<option value="missing">Missing (recalc existing + missing)</option>
				<option value="force">Force (delete all, recalc all)</option>
				<option value="lazy">Lazy (delete hashes, recalc later)</option>
			</select>
		</div>

		<div v-if="variant === 'admin'" class="fcias-cron-form-row">
			<label :for="ids.adminEnforced">Users may not edit this rule</label>
			<input :id="ids.adminEnforced" v-model="draft.admin_enforced" type="checkbox">
		</div>

		<div class="fcias-cron-form-actions">
			<button :id="ids.save" class="fcias-btn" @click="submit">
				Save
			</button>
			<button :id="ids.cancel" class="fcias-btn" @click="emit('cancel')">
				Cancel
			</button>
		</div>
	</div>
</template>
