<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * One rule row. Vue's `{{ }}` interpolation auto-escapes every field below —
 * no manual escapeHtml() calls are needed here, unlike the vanilla-JS
 * predecessor.
 */
import { priorityLabel, scopeLabel } from './bands'
import type { Rule } from './types'

const props = defineProps<{
	rule: Rule
	variant: 'admin' | 'personal'
	/** Whether this row's handle may be grabbed to start a drag. */
	canDrag?: boolean
	/** Set while this row is the one being dragged. */
	isDragging?: boolean
	/** Set while another row is being dragged over this one. */
	isDragOver?: boolean
	/** Set on the first row of each band, which carries the band label. */
	startsBand?: boolean
}>()

const emit = defineEmits<{
	(e: 'edit', rule: Rule): void
	(e: 'toggle', rule: Rule): void
	(e: 'delete', rule: Rule): void
	(e: 'row-dragstart', rule: Rule, event: DragEvent): void
	(e: 'row-dragover', rule: Rule, event: DragEvent): void
	(e: 'row-dragleave', rule: Rule): void
	(e: 'row-drop', rule: Rule, event: DragEvent): void
	(e: 'row-dragend'): void
}>()

/** An ignore/exclude rule computes nothing, so it has no algorithms or mode. */
const computesHashes = (props.rule.type ?? 'include') === 'include'
</script>

<template>
	<tr
		:data-id="String(rule.id)"
		:data-band="rule.band"
		:class="[
			`fcias-band-${rule.band ?? 0}`,
			{
				'fcias-band-start': startsBand,
				'fcias-dragging': isDragging,
				'fcias-drag-over': isDragOver,
				'fcias-rule-pinned': rule.pinned,
			},
		]"
		@dragover="emit('row-dragover', rule, $event)"
		@dragleave="emit('row-dragleave', rule)"
		@drop="emit('row-drop', rule, $event)">
		<td class="fcias-drag-handle-cell">
			<span
				v-if="canDrag"
				class="fcias-drag-handle"
				draggable="true"
				aria-hidden="true"
				title="Drag to reorder within this band"
				@dragstart="emit('row-dragstart', rule, $event)"
				@dragend="emit('row-dragend')">⠿</span>
		</td>
		<td class="fcias-priority-cell" :title="`Band ${rule.band}, position ${rule.position}`">
			{{ priorityLabel(rule) }}
		</td>
		<td :title="scopeLabel(rule.userScope)">{{ scopeLabel(rule.userScope) }}</td>
		<td :title="rule.path || '/'">{{ rule.path || '/' }}</td>
		<td>
			<span :class="`fcias-rule-type fcias-rule-type-${rule.type ?? 'include'}`">
				{{ rule.type ?? 'include' }}
			</span>
		</td>
		<td :title="computesHashes ? (rule.algos || []).join(', ') : ''">
			<span v-if="computesHashes">{{ (rule.algos || []).join(', ') }}</span>
			<span v-else class="fcias-muted">—</span>
		</td>
		<td>
			<span v-if="computesHashes">{{ rule.mode || 'auto' }}</span>
			<span v-else class="fcias-muted">—</span>
		</td>
		<td>
			<span :class="rule.enabled ? 'fcias-compat-pass' : 'fcias-compat-fail'">
				{{ rule.enabled ? 'Enabled' : 'Disabled' }}
			</span>
		</td>
		<td>{{ rule.admin_enforced ? 'Yes' : 'No' }}</td>
		<td class="fcias-cron-actions">
			<template v-if="rule.canEdit">
				<button class="fcias-btn fcias-btn-edit" data-action="edit" @click="emit('edit', rule)">
					Edit
				</button>
				<button class="fcias-btn fcias-btn-toggle" data-action="toggle" @click="emit('toggle', rule)">
					{{ rule.enabled ? 'Disable' : 'Enable' }}
				</button>
				<button
					v-if="!rule.pinned"
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
