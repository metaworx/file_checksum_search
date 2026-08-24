<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Rule list, shared by the admin ("Additional Rules") and personal
 * ("Rules applying to your files") settings pages.
 */
import { ref } from 'vue'
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
	/**
	 * Enables the drag-handle column. Rendered on every instance (not just
	 * reorderable ones) so the global-rule, additional-rules and personal
	 * tables keep sharing one column grid — see the colgroup comment below.
	 * Within a reorderable table, only rows the caller may actually move
	 * get a live handle: every row for the admin variant, and only rows
	 * with `canEdit === true` for the personal variant.
	 */
	reorderable?: boolean
}>()

const emit = defineEmits<{
	(e: 'edit', rule: Rule): void
	(e: 'toggle', rule: Rule): void
	(e: 'delete', rule: Rule): void
	/**
	 * A drag-and-drop reorder completed. Carries only the IDs of rows this
	 * table allows dragging, in their new relative order.
	 *
	 * Currently unwired: rule priority moved to a band model, where a
	 * reorder permutes one band at a time and the payload identifies the
	 * band. Both settings pages therefore render without drag handles until
	 * the banded table lands; the mechanics below are kept as the basis for
	 * it (see RuleService::reorderBand()).
	 */
	(e: 'reorder', orderedIds: Array<Rule['id']>): void
}>()

const draggedId = ref<Rule['id'] | null>(null)
const dragOverId = ref<Rule['id'] | null>(null)

function isDraggable( rule: Rule ): boolean {
	return props.reorderable === true && ( props.variant === 'admin' || rule.canEdit === true )
}

function onDragStart( rule: Rule, event: DragEvent ): void {
	draggedId.value = rule.id
	event.dataTransfer?.setData( 'text/plain', String( rule.id ) )
	if ( event.dataTransfer ) {
		event.dataTransfer.effectAllowed = 'move'
	}
}

function onDragOver( rule: Rule, event: DragEvent ): void {
	if ( draggedId.value === null ) return
	// Required for drop to fire at all — browsers default to rejecting drops.
	event.preventDefault()
	dragOverId.value = rule.id
}

function onDragLeave( rule: Rule ): void {
	if ( dragOverId.value === rule.id ) {
		dragOverId.value = null
	}
}

function onDrop( targetRule: Rule, event: DragEvent ): void {
	event.preventDefault()
	const sourceId = draggedId.value
	draggedId.value = null
	dragOverId.value = null

	if ( sourceId === null || sourceId === targetRule.id ) return

	const order = props.rules.map( ( r ) => r.id )
	const from = order.indexOf( sourceId )
	const to = order.indexOf( targetRule.id )
	if ( from === -1 || to === -1 ) return

	order.splice( from, 1 )
	order.splice( to, 0, sourceId )

	const reorderableIds = order.filter( ( id ) => {
		const rule = props.rules.find( ( r ) => r.id === id )
		return rule !== undefined && isDraggable( rule )
	} )

	emit( 'reorder', reorderableIds )
}

function onDragEnd(): void {
	draggedId.value = null
	dragOverId.value = null
}
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
			     column fits Edit + Disable + Delete without clipping. The drag
			     column is included even where reordering is off (the global
			     rule's table), so the grid stays identical everywhere. -->
			<colgroup>
				<col style="width: 4%">
				<col style="width: 6%">
				<col style="width: 9%">
				<col style="width: 16%">
				<col style="width: 11%">
				<col style="width: 8%">
				<col style="width: 11%">
				<col style="width: 7%">
				<col style="width: 28%">
			</colgroup>
			<thead>
				<tr>
					<th />
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
					:can-drag="isDraggable(rule)"
					:is-dragging="draggedId === rule.id"
					:is-drag-over="dragOverId === rule.id"
					@edit="emit('edit', $event)"
					@toggle="emit('toggle', $event)"
					@delete="emit('delete', $event)"
					@row-dragstart="onDragStart"
					@row-dragover="onDragOver"
					@row-dragleave="onDragLeave"
					@row-drop="onDrop"
					@row-dragend="onDragEnd" />
			</tbody>
		</table>
	</div>
</template>
