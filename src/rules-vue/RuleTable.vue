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
import { BAND_HELP, BAND_LABELS, selectorKind, selectorTarget } from './bands'
import type { GroupFolderOption, Rule } from './types'

const props = defineProps<{
	rules: Rule[]
	variant: 'admin' | 'personal'
	/** Whether the caller may create rules at all; drives the empty-state hint. */
	canEditAny?: boolean
	/** True until the rules have been loaded once: neither hint nor empty state is shown before. */
	loading?: boolean
	/** Overrides the "no rules" placeholder text. */
	emptyText?: string
	/** Turns the drag handles on. Rows still need `canEdit` individually. */
	reorderable?: boolean
	/** What the groupfolders app calls itself, for the Scope column. */
	groupFoldersLabel?: string | null
	/** Whether the groupfolders app is installed and enabled. */
	groupFoldersAvailable?: boolean
	/** The group folders that exist — part of the coverage view. */
	availableGroupFolders?: GroupFolderOption[]
	/** Raw ids of storages only storage:<id> or '*' can reach. */
	availableStorages?: string[]
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
	/** A placeholder row's button: create this namespace's first rule. */
	(e: 'create', payload: { selector: string; label: string }): void
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
	scope: 'Which slice of files the rule addresses: one user\'s home folder, the members of one group, '
		+ '"All home folders", one group folder, one storage by its id, or "Everything". A rule only ever '
		+ 'meets files inside its slice — "All home folders" reaches every personal folder and nothing else, '
		+ 'while "Everything" also reaches group folders and external storage.',
	path: 'Glob pattern the file path must match. "**" matches every file, "**/*.txt" matches by extension, '
		+ 'and "/Documents/**" matches everything below one folder.',
	type: 'What happens when this rule matches. "include" computes the checksums in this row. "ignore" stops '
		+ 'automatic hashing but still allows a manual recalculation. "exclude" blocks hashing by every '
		+ 'route, including the sidebar button, the API and the occ command.',
	algos: 'Checksum algorithms computed for matching files. Each one is indexed separately, so more '
		+ 'algorithms means more work per file. Shown as "—" for a rule that computes nothing.',
	mode: 'What happens to a file that already has a hash. "auto" recomputes only an outdated one, "missing" '
		+ 'also fills gaps, "force" discards and recomputes everything, and "lazy" clears the hashes now and '
		+ 'lets a later run recompute them. Shown as "—" for a rule that computes nothing.',
	status: 'Whether the rule takes part in evaluation at all. A disabled rule is skipped as though it were '
		+ 'not there, so the next matching rule decides instead.',
	enforced: '"Yes" means an administrator set this rule and users cannot override or disable it from their '
		+ 'personal settings. Enforced rules fill the first four bands, so no rule of a user\'s own can '
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

/** Selectors that already have a catch-all (`**`) rule of their own. */
const selectorsWithDefault = computed(
	() => new Set(
		props.rules
			.filter((rule) => rule.isDefault === true)
			.map((rule) => rule.selector || '*'),
	),
)

/** Selectors any rule addresses at all, catch-all or not. */
const selectorsWithAnyRule = computed(
	() => new Set(props.rules.map((rule) => rule.selector || '*')),
)

/**
 * Every namespace this instance has, in evaluation order.
 *
 * The four kinds a catch-all can address: one storage, one group folder,
 * all home folders, everything. Each is a slice that either has a rule of
 * its own or is left to whatever more general rule happens to cover it.
 */
const namespaces = computed(() => {
	if (props.variant !== 'admin') return []

	const folderTerm = props.groupFoldersLabel ?? 'Group folder'

	return [
		...(props.availableStorages ?? []).map((id) => ({
			selector: `storage:${id}`,
			label: `Storage: ${id}`,
		})),
		...(props.groupFoldersAvailable === true
			? (props.availableGroupFolders ?? []).map((folder) => ({
				selector: `groupfolder:${folder.id}`,
				label: `${folderTerm}: ${folder.name} (#${folder.id})`,
			}))
			: []),
		{ selector: 'home:*', label: 'All home folders' },
		{ selector: '*', label: 'Everything — every storage' },
	]
})

/**
 * Namespaces with no catch-all rule of their own.
 *
 * Rendered as virtual rows — nothing is written until the administrator
 * says so — because a namespace nobody has ruled on is otherwise
 * invisible: you would have to know it exists to discover it is uncovered.
 * A namespace with specific rules but no catch-all is listed too, saying
 * so in its own words: the gap is real, just narrower.
 */
const placeholders = computed(
	() => namespaces.value
		.filter((namespace) => !selectorsWithDefault.value.has(namespace.selector))
		.map((namespace) => ({
			key: namespace.selector,
			selector: namespace.selector,
			label: namespace.label,
			note: selectorsWithAnyRule.value.has(namespace.selector)
				? 'no catch-all rule — only the specific rules above apply here'
				: 'not covered — no rule addresses it, so nothing is hashed there',
		})),
)

/**
 * Whether a rule names a provider that is no longer there — a group folder
 * rule after the app was disabled, or one naming a folder that was deleted.
 * Such a rule is inert by construction; saying so beats leaving the reader
 * to wonder why it never matches.
 */
function providerMissing(rule: Rule): boolean {
	if (props.variant !== 'admin' || selectorKind(rule.selector || '*') !== 'groupfolder') {
		return false
	}

	if (props.groupFoldersAvailable !== true) return true

	return !(props.availableGroupFolders ?? [])
		.some((folder) => String(folder.id) === String(selectorTarget(rule.selector)))
}

const emptyMessage = computed(
	() => props.emptyText ?? (props.variant === 'admin' ? 'No rules yet.' : 'No rules apply to your files.'),
)
</script>

<template>
	<div>
		<!-- Information, not a failure: it stays for as long as it is true, so
		     it is styled as a hint. Failed requests report in the page's own
		     message slot above this table. -->
		<p v-if="variant === 'personal' && !loading && canEditAny === false" class="fcias-hint">
			You are not allowed to edit rules. Contact an administrator.
		</p>

		<p v-if="!loading && rules.length === 0">
			{{ emptyMessage }}
		</p>

		<!-- Placeholders alone are worth a table: on an instance with no
		     rules at all, they are how an administrator sees what could be
		     ruled on. -->
		<table v-if="rules.length > 0 || placeholders.length > 0" class="grid fcias-rules-table">
			<colgroup>
				<col style="width: 4%">
				<col style="width: 7%">
				<col style="width: 14%">
				<col style="width: 21%">
				<col style="width: 8%">
				<col style="width: 14%">
				<col style="width: 8%">
				<col style="width: 9%">
				<col style="width: 7%">
				<col style="width: 8%">
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
						:group-folders="availableGroupFolders"
						:can-drag="isDraggable(rule)"
						:is-dragging="draggedId === rule.id"
						:is-drag-over="dragOverId === rule.id"
						:starts-band="startsBand(index)"
						:provider-missing="providerMissing(rule)"
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

				<!-- Virtual rows: namespaces that exist but no rule addresses.
				     Nothing is stored until the button below is used — a page
				     load must never write configuration. -->
				<template v-if="placeholders.length">
					<tr class="fcias-placeholder-header">
						<th colspan="10" scope="colgroup">
							<span class="fcias-th-inner">
								<span>Without a rule of their own</span>
								<HelpPopover
									text="These namespaces exist on this instance but have no catch-all rule of
										their own, so what happens to their files is decided by whatever more
										general rule covers them — or by nothing at all. Creating a rule from
										here starts one for that namespace; until then, nothing is stored."
									label="Without a rule of their own" />
							</span>
						</th>
					</tr>
					<tr
						v-for="placeholder in placeholders"
						:key="placeholder.key"
						class="fcias-placeholder-row"
						:data-placeholder="placeholder.selector">
						<td />
						<td class="fcias-priority-cell">
							—
						</td>
						<td :title="placeholder.label">
							{{ placeholder.label }}
						</td>
						<td colspan="6" class="fcias-muted">
							{{ placeholder.note }}
						</td>
						<td class="fcias-rules-actions">
							<button
								class="fcias-btn"
								type="button"
								data-action="create"
								@click="emit('create', { selector: placeholder.selector, label: placeholder.label })">
								Create rule
							</button>
						</td>
					</tr>
				</template>
			</tbody>
		</table>
	</div>
</template>
