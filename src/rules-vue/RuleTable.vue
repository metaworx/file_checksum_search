<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Rule list, shared by the admin ("Additional Rules") and personal
 * ("Rules applying to your files") settings pages.
 */
import RuleRow from './RuleRow.vue'
import type { Rule } from './types'

const props = defineProps<{
	rules: Rule[]
	variant: 'admin' | 'personal'
	/** Personal variant only: whether the current user may edit at least the rules they own. */
	canEditAny?: boolean
}>()

const emit = defineEmits<{
	(e: 'edit', rule: Rule): void
	(e: 'toggle', rule: Rule): void
	(e: 'delete', rule: Rule): void
}>()
</script>

<template>
	<div>
		<p v-if="variant === 'personal' && canEditAny === false" class="fcias-error">
			You are not allowed to edit rules. Contact an administrator.
		</p>

		<p v-if="rules.length === 0">
			{{ variant === 'admin' ? 'No additional rules.' : 'No rules.' }}
		</p>

		<table v-else class="grid fcias-cron-table">
			<thead>
				<tr>
					<th v-if="variant === 'admin'">Priority</th>
					<th>{{ variant === 'admin' ? 'User' : 'Scope' }}</th>
					<th>Path</th>
					<th>Algos</th>
					<th>Mode</th>
					<th>Status</th>
					<th>{{ variant === 'admin' ? 'Enforced' : 'Admin-enforced' }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<RuleRow
					v-for="(rule, index) in rules"
					:key="rule.id"
					:rule="rule"
					:variant="variant"
					:index="index"
					@edit="emit('edit', $event)"
					@toggle="emit('toggle', $event)"
					@delete="emit('delete', $event)" />
			</tbody>
		</table>
	</div>
</template>
