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
	/** Number the Priority column starts at. The global rule's own table passes 0. */
	priorityOffset?: number
	/** Set to hide the per-row Delete button (the global rule). */
	hideDelete?: boolean
	/** Overrides the "no rules" placeholder text. */
	emptyText?: string
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
			{{ emptyText ?? (variant === 'admin' ? 'No additional rules.' : 'No rules.') }}
		</p>

		<table v-else class="grid fcias-cron-table">
			<!-- One grid for all three tables (global, additional, personal), so
			     rows line up across them. Status fits its badge and the action
			     column fits Edit + Disable + Delete without clipping. -->
			<colgroup>
				<col style="width: 6%">
				<col style="width: 9%">
				<col style="width: 20%">
				<col style="width: 11%">
				<col style="width: 8%">
				<col style="width: 11%">
				<col style="width: 7%">
				<col style="width: 28%">
			</colgroup>
			<thead>
				<tr>
					<th>Priority</th>
					<th>{{ variant === 'admin' ? 'User' : 'Scope' }}</th>
					<th>Path</th>
					<th>Algorithms</th>
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
					:priority-offset="priorityOffset"
					:hide-delete="hideDelete"
					@edit="emit('edit', $event)"
					@toggle="emit('toggle', $event)"
					@delete="emit('delete', $event)" />
			</tbody>
		</table>
	</div>
</template>
