<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * One rule row. Vue's `{{ }}` interpolation auto-escapes every field below —
 * no manual escapeHtml() calls are needed here, unlike the vanilla-JS
 * predecessor.
 */
import { computed } from 'vue'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import { priorityLabel, selectorLabel } from './bands'
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
	(e: 'apply', rule: Rule): void
	(e: 'delete', rule: Rule): void
	(e: 'rowDragstart', rule: Rule, event: DragEvent): void
	(e: 'rowDragover', rule: Rule, event: DragEvent): void
	(e: 'rowDragleave', rule: Rule): void
	(e: 'rowDrop', rule: Rule, event: DragEvent): void
	(e: 'rowDragend'): void
}>()

/** An ignore/exclude rule computes nothing, so it has no algorithms or mode. */
const computesHashes = computed(() => (props.rule.type ?? 'include') === 'include')

/**
 * Re-apply queues the rule's full sweep — only meaningful for a rule that
 * both computes something and is switched on: applying an ignore/exclude
 * marks nothing, and the server refuses a disabled rule at submission.
 */
const canReapply = computed(() => props.rule.enabled && computesHashes.value)
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
				'fcias-rule-default': rule.isDefault,
			},
		]"
		@dragover="emit('rowDragover', rule, $event)"
		@dragleave="emit('rowDragleave', rule)"
		@drop="emit('rowDrop', rule, $event)">
		<td class="fcias-drag-handle-cell">
			<span
				v-if="canDrag"
				class="fcias-drag-handle"
				draggable="true"
				aria-hidden="true"
				title="Drag to reorder within this band"
				@dragstart="emit('rowDragstart', rule, $event)"
				@dragend="emit('rowDragend')">⠿</span>
		</td>
		<td class="fcias-priority-cell" :title="`Band ${rule.band}, position ${rule.position}`">
			{{ priorityLabel(rule) }}
		</td>
		<td :title="selectorLabel(rule.selector)">
			{{ selectorLabel(rule.selector) }}
		</td>
		<td :title="rule.path || '/'">
			{{ rule.path || '/' }}
		</td>
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
			<NcActions v-if="rule.canEdit" :aria-label="`Actions for the rule on ${rule.path || '/'}`">
				<NcActionButton data-action="edit" @click="emit('edit', rule)">
					Edit
				</NcActionButton>
				<NcActionButton data-action="toggle" @click="emit('toggle', rule)">
					{{ rule.enabled ? 'Disable' : 'Enable' }}
				</NcActionButton>
				<NcActionButton v-if="canReapply" data-action="apply" @click="emit('apply', rule)">
					Re-apply
				</NcActionButton>
				<NcActionButton data-action="delete" @click="emit('delete', rule)">
					Delete
				</NcActionButton>
			</NcActions>
			<span v-else class="fcias-muted">Read-only</span>
		</td>
	</tr>
</template>
