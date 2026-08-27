<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The rule list — one table for every rule, in evaluation order.
 *
 * Rules arrive already band-sorted from the server, so rendering them in the
 * given order *is* showing the evaluation order. Bands are contiguous by
 * construction, which is what lets a drag be confined to one of them: there is
 * no legal drop outside the dragged row's own band, so a reorder can never
 * promote a rule past one it must not outrun.
 */
import { computed, ref } from 'vue'
import RuleRow from './RuleRow.vue'
import HelpPopover from '../components/HelpPopover.vue'
import { BAND_HELP, BAND_LABELS } from './bands'
import type { Rule } from './types'

const props = defineProps<{
	rules: Rule[]
	variant: 'admin' | 'personal'
	/** Whether the caller may create rules at all; drives the empty-state hint. */
	canEditAny?: boolean
	/** Overrides the "no rules" placeholder text. */
	emptyText?: string
	/** Turns the drag handles on. Rows still need `canEdit` individually. */
	reorderable?: boolean
}>()

const emit = defineEmits<{
	(e: 'edit', rule: Rule): void
	(e: 'toggle', rule: Rule): void
	(e: 'apply', rule: Rule): void
	(e: 'delete', rule: Rule): void
	/**
	 * A reorder completed within one segment partition: one selector's
	 * regular rules, or its defaults.
	 */
	(e: 'reorder', payload: { selector: string; defaults: boolean; orderedIds: Array<Rule['id']> }): void
}>()

const draggedId = ref<Rule['id'] | null>(null)
const dragOverId = ref<Rule['id'] | null>(null)

function isDraggable(rule: Rule): boolean {
	return props.reorderable === true
		&& rule.canEdit === true
}

/** True on the first row of each band, which carries the band label. */
function startsBand(index: number): boolean {
	return index === 0 || props.rules[index - 1].band !== props.rules[index].band
}

function bandLabel(rule: Rule): string {
	return BAND_LABELS[rule.band ?? 0] ?? `Band ${rule.band}`
}

function bandHelp(rule: Rule): string {
	return BAND_HELP[rule.band ?? 0] ?? ''
}

/**
 * What each column means and what its values can be.
 *
 * The table is the only place a rule's derived properties — its band, its
 * position, whether it is enforced — are ever shown, and none of them are
 * self-explanatory from a one-word heading. The dialog explains the fields
 * someone fills in; this explains the columns they then have to read.
 */
const COLUMN_HELP: Record<string, string> = {
	priority: 'Where the rule sits in evaluation order, written "<band>.<position>". The first rule that '
		+ 'matches a file decides it outright, so a lower number is stronger. The band follows from the '
		+ 'rule\'s scope and whether it is enforced — it is never chosen directly — and the position is the '
		+ 'rule\'s place inside that band, which is what dragging changes.',
	scope: 'Whose files this rule can apply to: "All users" for everyone on this instance, "Group: <name>" '
		+ 'for the members of one group, or a single user\'s ID for just that person.',
	path: 'Glob pattern the file path must match. "**" matches every file, "**/*.txt" matches by extension, '
		+ 'and "/Documents/**" matches everything below one folder.',
	type: 'What happens when this rule matches. "include" computes the checksums in this row. "ignore" stops '
		+ 'automatic hashing but still allows a manual recalculation. "exclude" blocks hashing by every '
		+ 'route, including the sidebar button, the API and the occ command.',
	algos: 'Checksum algorithms computed for matching files. Each one is indexed separately, so more '
		+ 'algorithms means more work per file. Shown as "—" for a rule that computes nothing.',
	mode: 'What happens to a file that already has a hash. "auto" recomputes only a stale one, "missing" '
		+ 'also fills gaps, "force" discards and recomputes everything, and "lazy" clears the hashes now and '
		+ 'lets a later run recompute them. Shown as "—" for a rule that computes nothing.',
	status: 'Whether the rule takes part in evaluation at all. A disabled rule is skipped as though it were '
		+ 'not there, so the next matching rule decides instead.',
	enforced: '"Yes" means an administrator set this rule and users cannot override or disable it from their '
		+ 'personal settings. Enforced rules fill the first three bands, so no rule of a user\'s own can '
		+ 'outrun one.',
}

function ruleById(id: Rule['id']): Rule | undefined {
	return props.rules.find((rule) => rule.id === id)
}

function onDragStart(rule: Rule, event: DragEvent): void {
	draggedId.value = rule.id
	event.dataTransfer?.setData('text/plain', String(rule.id))
	if (event.dataTransfer) {
		event.dataTransfer.effectAllowed = 'move'
	}
}

/**
 * A drop is only offered inside the dragged row's own band — and, in band 4,
 * only within the same owner's rules. Refusing to preventDefault() is what
 * makes the browser show a "no drop" cursor elsewhere, so the constraint is
 * visible while dragging rather than only enforced on release.
 */
function isValidTarget(target: Rule): boolean {
	const source = draggedId.value === null ? undefined : ruleById(draggedId.value)
	if (source === undefined) return false
	// One selector's rules are one segment; the shape partition separates a
	// segment's regular rules from its ** defaults. Crossing either would
	// change what the rule can outrank, so neither is a legal drop.
	if (source.selector !== target.selector) return false
	if (source.band !== target.band) return false
	if ((source.isDefault === true) !== (target.isDefault === true)) return false
	return true
}

function onDragOver(rule: Rule, event: DragEvent): void {
	if (!isValidTarget(rule)) return
	event.preventDefault()
	dragOverId.value = rule.id
}

function onDragLeave(rule: Rule): void {
	if (dragOverId.value === rule.id) {
		dragOverId.value = null
	}
}

function onDrop(target: Rule, event: DragEvent): void {
	event.preventDefault()
	const sourceId = draggedId.value
	draggedId.value = null
	dragOverId.value = null

	if (sourceId === null || sourceId === target.id) return
	const source = ruleById(sourceId)
	if (source === undefined) return
	if (source.selector !== target.selector) return
	if (source.band !== target.band) return
	if ((source.isDefault === true) !== (target.isDefault === true)) return

	// The payload is the segment partition, reordered — never the whole
	// table, so the server can hold it to an exact permutation.
	const segment = props.rules.filter(
		(rule) => rule.selector === source.selector
			&& rule.band === source.band
			&& (rule.isDefault === true) === (source.isDefault === true),
	)

	const orderedIds = segment.map((rule) => rule.id)
	const from = orderedIds.indexOf(sourceId)
	const to = orderedIds.indexOf(target.id)
	if (from === -1 || to === -1) return

	orderedIds.splice(from, 1)
	orderedIds.splice(to, 0, sourceId)

	emit('reorder', {
		selector: source.selector,
		defaults: source.isDefault === true,
		orderedIds,
	})
}

function onDragEnd(): void {
	draggedId.value = null
	dragOverId.value = null
}

const emptyMessage = computed(
	() => props.emptyText ?? (props.variant === 'admin' ? 'No rules yet.' : 'No rules apply to your files.'),
)
</script>

<template>
	<div>
		<p v-if="variant === 'personal' && canEditAny === false" class="fcias-error">
			You are not allowed to edit rules. Contact an administrator.
		</p>

		<p v-if="rules.length === 0">
			{{ emptyMessage }}
		</p>

		<table v-else class="grid fcias-cron-table">
			<colgroup>
				<col style="width: 4%">
				<col style="width: 7%">
				<col style="width: 14%">
				<col style="width: 23%">
				<col style="width: 8%">
				<col style="width: 14%">
				<col style="width: 8%">
				<col style="width: 9%">
				<col style="width: 7%">
				<col style="width: 6%">
			</colgroup>
			<thead>
				<tr>
					<th />
					<th
						v-for="column in [
							{ key: 'priority', label: 'Priority' },
							{ key: 'scope', label: 'Scope' },
							{ key: 'path', label: 'Path' },
							{ key: 'type', label: 'Type' },
							{ key: 'algos', label: 'Algorithms' },
							{ key: 'mode', label: 'Mode' },
							{ key: 'status', label: 'Status' },
							{ key: 'enforced', label: 'Enforced' },
						]"
						:key="column.key"
						:data-column="column.key">
						<span class="fcias-th-inner">
							<span class="fcias-th-label">{{ column.label }}</span>
							<HelpPopover :text="COLUMN_HELP[column.key]" :label="column.label" />
						</span>
					</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<template v-for="(rule, index) in rules" :key="rule.id">
					<!-- The band label is a real row rather than styling alone, so
					     the grouping survives for anyone not seeing the colours. -->
					<tr v-if="startsBand(index)" :class="`fcias-band-header fcias-band-${rule.band ?? 0}`">
						<th colspan="10" scope="colgroup">
							<span class="fcias-th-inner">
								<span>{{ rule.band }} · {{ bandLabel(rule) }}</span>
								<HelpPopover :text="bandHelp(rule)" :label="`Band ${rule.band}`" />
							</span>
						</th>
					</tr>
					<RuleRow
						:rule="rule"
						:variant="variant"
						:group-folders-label="groupFoldersLabel"
						:can-drag="isDraggable(rule)"
						:is-dragging="draggedId === rule.id"
						:is-drag-over="dragOverId === rule.id"
						:starts-band="startsBand(index)"
						@edit="emit('edit', $event)"
						@toggle="emit('toggle', $event)"
						@apply="emit('apply', $event)"
						@delete="emit('delete', $event)"
						@row-dragstart="onDragStart"
						@row-dragover="onDragOver"
						@row-dragleave="onDragLeave"
						@row-drop="onDrop"
						@row-dragend="onDragEnd" />
				</template>
			</tbody>
		</table>
	</div>
</template>
