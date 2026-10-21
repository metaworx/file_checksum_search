<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The confirmation before the rows chosen across the Duplicates page are
 * deleted: the way, where the deleted files' earlier versions go, what the
 * server's dry run said in sum, and, folded under it, each group's rows.
 * Each choice is dry-run again.
 */

import { computed } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { fileLabel, trashLabel, versionLabel } from '../../fileLabel'
import type { DuplicateGroup as GroupType } from '../composables/useDuplicates'
import type { GroupResults, RowResult } from '../duplicateActions'
import { groupKey } from '../pageSelection'
import { n, t } from '../../l10n'

const props = withDefaults(defineProps<{
	/** The groups shown, for the rows' labels and the copies that stay. */
	groups: GroupType[]
	/** The dry run's answer, a group each. */
	answers: GroupResults[]
	/** Past the trash: nothing comes back. */
	forGood: boolean
	/** Whether the deleted files' earlier versions go to a copy that stays (D2-A), or with their files. */
	keepVersions: boolean
	/** The copy that takes a group's versions, by the group's key; absent where none does. */
	targets: Record<string, number>
	/** True while the deletion runs. */
	busy?: boolean
}>(), { busy: false })

const emit = defineEmits<{
	(e: 'confirm'): void
	(e: 'close'): void
	(e: 'update:forGood', value: boolean): void
	(e: 'update:keepVersions', value: boolean): void
}>()

const TEXTS = {
	title: t('file_checksum_search', 'Delete selected'),
	trash: t('file_checksum_search', 'Move to trash'),
	delete: t('file_checksum_search', 'Delete permanently'),
	target: t('file_checksum_search', 'Put their earlier versions into'),
	// TRANSLATORS: a choice under "Put their earlier versions into", when rows of many groups are deleted at once: in each group, the copy that is not deleted, or the most recently changed one
	keep: t('file_checksum_search', 'The copy that stays, the most recently changed where several do'),
	none: t('file_checksum_search', 'None, they go with their files'),
}

const results = computed<RowResult[]>(() => props.answers.flatMap((answer) => answer.results))

const ready = computed(() => results.value.filter((result) => result.status === 'ready'))

/** What the dry run said, in sum, a line each where there is anything to say. */
const summary = computed(() => {
	const lines: string[] = []
	const toTrash = ready.value.filter((result) => result.action === 'trash').length
	const toDelete = ready.value.filter((result) => result.action === 'delete').length
	const refused = results.value.filter((result) => result.status === 'refused' || result.status === 'failed').length
	const kept = ready.value.reduce((sum, result) => sum + (result.merge?.imported ?? 0), 0)
	const going = ready.value.reduce((sum, result) => sum + (result.merge ? 0 : (result.versions?.length ?? 0)), 0)

	if (toTrash > 0) {
		lines.push(n('file_checksum_search', '%n row goes to the trash.', '%n rows go to the trash.', toTrash))
	}
	if (toDelete > 0) {
		lines.push(n('file_checksum_search', '%n row is deleted permanently.', '%n rows are deleted permanently.', toDelete))
	}
	if (kept > 0) {
		lines.push(n('file_checksum_search', '%n earlier version goes to a copy that stays.', '%n earlier versions go to a copy that stays.', kept))
	}
	if (going > 0) {
		lines.push(n('file_checksum_search', '%n earlier version goes with its file.', '%n earlier versions go with their files.', going))
	}
	if (refused > 0) {
		lines.push(n('file_checksum_search', '%n row cannot be deleted.', '%n rows cannot be deleted.', refused))
	}
	return lines
})

/** The group answered, as the page shows it. */
function groupOf(answer: GroupResults): GroupType | undefined {
	return props.groups.find((group) => groupKey(group) === groupKey(answer))
}

/**
 * The groups left with no copy outside the trash: every file of theirs,
 * in whichever group it is deleted, is among the rows ready to go.
 */
const emptied = computed(() => {
	const going = new Set(ready.value.map((result) => result.fileid))
	return props.answers.map(groupOf).filter((group): group is GroupType => group !== undefined
		&& group.files.every((file) => file.version !== undefined || file.trash !== undefined || going.has(file.fileid))).length
})

const emptiedText = computed(() => props.forGood
	? n('file_checksum_search', 'In %n group no copy stays.', 'In %n groups no copy stays.', emptied.value)
	: n('file_checksum_search', 'In %n group no copy stays outside the trash.', 'In %n groups no copy stays outside the trash.', emptied.value))

const permanent = computed(() => ready.value.some((result) => result.action === 'delete'))

// Cancel closes the dialog itself, which reports it through onOpen().
const buttons = computed(() => [
	{ label: t('file_checksum_search', 'Cancel') },
	{
		label: props.forGood ? TEXTS.delete : TEXTS.trash,
		variant: props.forGood ? 'error' as const : 'primary' as const,
		disabled: ready.value.length === 0 || props.busy,
		callback: () => {
			emit('confirm')
			// The dialog stays until the parent closes it, with the outcome.
			return false
		},
	},
])

function labelOf(answer: GroupResults, result: RowResult): string {
	const file = groupOf(answer)?.files.find((row) => row.fileid === result.fileid)
	if (!file) {
		return String(result.fileid)
	}
	return [fileLabel(file), versionLabel(file), trashLabel(file)].filter((part) => part !== null && part !== '').join(' ')
}

/** Where a group's versions go, named. */
function targetText(answer: GroupResults): string | null {
	const target = groupOf(answer)?.files.find((file) => file.fileid === props.targets[groupKey(answer)])
	return target ? t('file_checksum_search', 'Their earlier versions go to {name}.', { name: fileLabel(target) }) : null
}

function onHow(value: unknown): void {
	emit('update:forGood', value === 'true')
}

function onKeep(value: unknown): void {
	emit('update:keepVersions', value === 'true')
}

function onOpen(open: boolean): void {
	if (!open) {
		emit('close')
	}
}
</script>

<template>
	<NcDialog :name="TEXTS.title"
		:open="true"
		:buttons="buttons"
		size="normal"
		@update:open="onOpen">
		<div class="pd-choice" data-testid="fcias-page-delete-how">
			<NcCheckboxRadioSwitch type="radio"
				name="pd-how"
				value="false"
				:model-value="String(forGood)"
				@update:model-value="onHow">
				{{ TEXTS.trash }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch type="radio"
				name="pd-how"
				value="true"
				:model-value="String(forGood)"
				@update:model-value="onHow">
				{{ TEXTS.delete }}
			</NcCheckboxRadioSwitch>
		</div>
		<fieldset class="pd-target" data-testid="fcias-page-delete-versions">
			<legend>{{ TEXTS.target }}</legend>
			<NcCheckboxRadioSwitch type="radio"
				name="pd-keep"
				value="true"
				:model-value="String(keepVersions)"
				@update:model-value="onKeep">
				{{ TEXTS.keep }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch type="radio"
				name="pd-keep"
				value="false"
				:model-value="String(keepVersions)"
				@update:model-value="onKeep">
				{{ TEXTS.none }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<ul class="pd-summary" data-testid="fcias-page-delete-summary">
			<li v-for="line in summary" :key="line">
				{{ line }}
			</li>
		</ul>
		<p v-if="emptied > 0" class="pd-warning" data-testid="fcias-page-delete-emptied">
			{{ emptiedText }}
		</p>
		<p v-if="permanent" class="pd-warning" data-testid="fcias-page-delete-permanent">
			{{ t('file_checksum_search', 'This cannot be undone.') }}
		</p>
		<!-- Each group's rows, folded: a page's choice may run to hundreds. -->
		<details v-for="answer in answers"
			:key="groupKey(answer)"
			class="pd-group"
			data-testid="fcias-page-delete-group">
			<summary><code>{{ answer.hash_value.slice(0, 12) }}</code> · {{ answer.algo.toUpperCase() }} · {{ answer.results.length }}</summary>
			<p v-if="targetText(answer)" class="pd-note">
				{{ targetText(answer) }}
			</p>
			<ul class="pd-rows">
				<li v-for="result in answer.results"
					:key="result.fileid"
					:data-status="result.status">
					<span class="pd-label">{{ labelOf(answer, result) }}</span>
					<span v-if="result.status === 'refused' || result.status === 'failed'" class="pd-reason">{{ result.reason }}</span>
					<span v-else class="pd-note">{{ result.action === 'delete' ? TEXTS.delete : TEXTS.trash }}</span>
				</li>
			</ul>
		</details>
	</NcDialog>
</template>

<style scoped>
.pd-choice {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 16px;
	margin-bottom: 8px;
}

.pd-target {
	margin-bottom: 8px;
}

.pd-target legend {
	font-weight: bold;
}

.pd-summary {
	margin: 8px 0;
	padding-inline-start: 16px;
	list-style: disc;
}

.pd-group {
	padding: 4px 0;
	border-top: 1px solid var(--color-border);
}

.pd-group summary {
	cursor: pointer;
}

.pd-rows {
	margin: 4px 0 0;
	padding: 0;
	list-style: none;
}

.pd-rows li {
	display: flex;
	flex-direction: column;
	padding: 2px 0;
}

.pd-label {
	word-break: break-all;
}

.pd-note {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

/* The -text variants: the bare ones are background fills. */
.pd-reason {
	font-size: 12px;
	color: var(--color-error-text);
}

.pd-warning {
	margin-top: 12px;
	font-weight: bold;
	color: var(--color-error-text);
}
</style>
