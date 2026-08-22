<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * One rule row, shared by the admin and personal rule tables. Vue's
 * `{{ }}` interpolation auto-escapes every field below — no manual
 * escapeHtml() calls are needed here, unlike the vanilla-JS predecessor.
 */
import type { Rule } from './types'

const props = defineProps<{
	rule: Rule
	variant: 'admin' | 'personal'
	/** Row position within the table, shown in the Priority column. */
	index?: number
	/** Number the Priority column starts at. The global rule's own table uses 0. */
	priorityOffset?: number
	/** Set for rules that must not be removed (the global rule). */
	hideDelete?: boolean
}>()

const emit = defineEmits<{
	(e: 'edit', rule: Rule): void
	(e: 'toggle', rule: Rule): void
	(e: 'delete', rule: Rule): void
}>()

const canManage = props.variant === 'admin' || props.rule.canEdit === true
</script>

<template>
	<tr :data-id="String(rule.id)">
		<td>{{ (index ?? 0) + (priorityOffset ?? 1) }}</td>
		<td :title="rule.userScope || 'all'">{{ rule.userScope || 'all' }}</td>
		<td :title="rule.path || '/'">{{ rule.path || '/' }}</td>
		<td :title="(rule.algos || []).join(', ')">{{ (rule.algos || []).join(', ') }}</td>
		<td>{{ rule.mode || 'auto' }}</td>
		<td>
			<span :class="rule.enabled ? 'fcias-compat-pass' : 'fcias-compat-fail'">
				{{ rule.enabled ? 'Enabled' : 'Disabled' }}
			</span>
		</td>
		<td v-if="variant === 'admin'">{{ rule.admin_enforced ? 'Yes' : 'No' }}</td>
		<td v-else><input type="checkbox" :checked="rule.admin_enforced" disabled></td>
		<td class="fcias-cron-actions">
			<template v-if="canManage">
				<button class="fcias-btn fcias-btn-edit" data-action="edit" @click="emit('edit', rule)">
					Edit
				</button>
				<button class="fcias-btn fcias-btn-toggle" data-action="toggle" @click="emit('toggle', rule)">
					{{ rule.enabled ? 'Disable' : 'Enable' }}
				</button>
				<button
					v-if="!hideDelete"
					class="fcias-btn fcias-btn-danger fcias-btn-delete"
					data-action="delete"
					@click="emit('delete', rule)">
					Delete
				</button>
			</template>
			<span v-else class="fcias-muted">Read-only</span>
		</td>
	</tr>
</template>
