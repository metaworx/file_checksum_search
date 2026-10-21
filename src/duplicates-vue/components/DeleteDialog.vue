<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The confirmation before rows of a duplicate group are deleted: what the
 * server's dry run said would happen to each, the earlier versions that go
 * along with a file, and whether it can be undone. For rows chosen in a
 * group, the way is chosen here too, and the file that stays and takes
 * the deleted files' versions, or none; each choice is dry-run again.
 */

import { computed } from 'vue'
import { formatFileSize } from '@nextcloud/files'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { fileLabel, trashLabel, versionLabel } from '../../fileLabel'
import type { DuplicateGroup as GroupType } from '../composables/useDuplicates'
import type { GoingVersion, RowResult } from '../duplicateActions'
import { formatDateTime, n, t } from '../../l10n'

const props = withDefaults(defineProps<{
	group: GroupType
	/** The dry run's answer, a row each. */
	results: RowResult[]
	/** Past the trash: nothing comes back. */
	forGood: boolean
	/** True while the deletion runs. */
	busy?: boolean
	/** A group's deletion: the way, and where the files' versions go, are chosen here. */
	choosing?: boolean
	/** The files of the group that stay and may take the versions. */
	targets?: GroupType['files']
	/** The file that takes them; null for none, undefined while not chosen. */
	mergeInto?: number | null
}>(), { busy: false, choosing: false, targets: () => [], mergeInto: undefined })

const emit = defineEmits<{
	(e: 'confirm'): void
	(e: 'close'): void
	(e: 'update:forGood', value: boolean): void
	(e: 'update:mergeInto', value: number | null): void
}>()

const TEXTS = {
	trash: t('file_checksum_search', 'Move to trash'),
	delete: t('file_checksum_search', 'Delete permanently'),
	// TRANSLATORS: the title of a version a person named, shown beside its name
	named: t('file_checksum_search', 'Named version'),
	// TRANSLATORS: the heading of a choice: which file that stays takes the earlier versions of the files deleted
	target: t('file_checksum_search', 'Put their earlier versions into'),
	// TRANSLATORS: a choice under "Put their earlier versions into": no file takes them, and the versions go wherever their own files go
	none: t('file_checksum_search', 'None, they go with their files'),
}

/** Named after what its button does, as a confirmation that asks nothing else. */
const title = computed(() => props.forGood ? TEXTS.delete : TEXTS.trash)

/** Whether anything is lost for good: a file deleted so, or any row that has no trash to go to. */
const permanent = computed(() => props.results.some((result) => result.action === 'delete'))

const ready = computed(() => props.results.some((result) => result.status === 'ready'))

/** Whether a file is among the rows: only a file has a way to choose. */
const hasFiles = computed(() => props.results.some((result) => result.kind === 'file' && result.status !== 'refused'))

/** Asked where the versions go: on a group's deletion, where some go along and a file stays to take them. */
const askTarget = computed(() => props.choosing
	&& props.targets.length > 0
	&& props.results.some((result) => (result.versions?.length ?? 0) > 0))

/** The chosen target as the radios hold it: a file id, `none`, or nothing yet. */
const targetValue = computed(() => props.mergeInto === undefined ? '' : props.mergeInto === null ? 'none' : String(props.mergeInto))

/**
 * Whether no copy of the content stays among the files: every one of them
 * is among the rows to delete. A version or a trashed copy is no copy.
 */
const noCopy = computed(() => props.choosing && !props.group.files.some((file) => !file.version && !file.trash
	&& !props.results.some((result) => result.fileid === file.fileid && result.status === 'ready')))

const noCopyText = computed(() => props.forGood
	? t('file_checksum_search', 'No copy of this content stays.')
	: t('file_checksum_search', 'No copy stays outside the trash.'))

// Cancel closes the dialog itself, which reports it through onOpen().
const buttons = computed(() => [
	{ label: t('file_checksum_search', 'Cancel') },
	{
		label: props.forGood ? TEXTS.delete : TEXTS.trash,
		variant: props.forGood ? 'error' as const : 'primary' as const,
		disabled: !ready.value || props.busy || (askTarget.value && props.mergeInto === undefined),
		callback: () => {
			emit('confirm')
			// The dialog stays until the parent closes it, with the outcome.
			return false
		},
	},
])

/** The row as the group shows it. */
function rowOf(result: RowResult): GroupType['files'][number] | undefined {
	return props.group.files.find((file) => file.fileid === result.fileid)
}

function labelOf(result: RowResult): string {
	const file = rowOf(result)
	if (!file) {
		return String(result.fileid)
	}
	return [fileLabel(file), versionLabel(file), trashLabel(file)].filter((part) => part !== null && part !== '').join(' ')
}

function versionsText(result: RowResult): string {
	return n('file_checksum_search', 'It takes %n earlier version with it.', 'It takes %n earlier versions with it.', result.versions?.length ?? 0)
}

function versionText(version: GoingVersion): string {
	return `${formatDateTime(new Date(version.timestamp * 1000))} · ${formatFileSize(version.size)}`
}

/** What the merge does with a file's versions: those that go to the file that stays, and those of a second it has already. */
function mergeText(result: RowResult): string {
	const merge = result.merge
	if (!merge) {
		return ''
	}
	const parts = [n('file_checksum_search', '%n version goes to the file that stays.', '%n versions go to the file that stays.', merge.imported)]
	if (merge.skipped > 0) {
		parts.push(n('file_checksum_search', '%n version of the same time is left out.', '%n versions of the same time are left out.', merge.skipped))
	}
	return parts.join(' ')
}

function onHow(value: unknown): void {
	emit('update:forGood', value === 'true')
}

function onTarget(value: unknown): void {
	emit('update:mergeInto', value === 'none' ? null : Number(value))
}

function onOpen(open: boolean): void {
	if (!open) {
		emit('close')
	}
}
</script>

<template>
	<NcDialog :name="title"
		:open="true"
		:buttons="buttons"
		size="normal"
		@update:open="onOpen">
		<!-- A group's deletion chooses its way here; a row's chose it in its menu. -->
		<div v-if="choosing && hasFiles" class="dd-choice" data-testid="fcias-delete-how">
			<NcCheckboxRadioSwitch type="radio"
				name="dd-how"
				value="false"
				:model-value="String(forGood)"
				@update:model-value="onHow">
				{{ TEXTS.trash }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch type="radio"
				name="dd-how"
				value="true"
				:model-value="String(forGood)"
				@update:model-value="onHow">
				{{ TEXTS.delete }}
			</NcCheckboxRadioSwitch>
		</div>
		<fieldset v-if="askTarget" class="dd-target" data-testid="fcias-delete-target">
			<legend>{{ TEXTS.target }}</legend>
			<NcCheckboxRadioSwitch v-for="file in targets"
				:key="file.fileid"
				type="radio"
				name="dd-target"
				:value="String(file.fileid)"
				:model-value="targetValue"
				@update:model-value="onTarget">
				{{ fileLabel(file) }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch type="radio"
				name="dd-target"
				value="none"
				:model-value="targetValue"
				@update:model-value="onTarget">
				{{ TEXTS.none }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<ul class="dd-rows" data-testid="fcias-delete-rows">
			<li v-for="result in results"
				:key="result.fileid"
				class="dd-row"
				:data-status="result.status">
				<span class="dd-label">{{ labelOf(result) }}</span>
				<span v-if="result.status === 'refused'" class="dd-reason">{{ result.reason }}</span>
				<span v-else class="dd-action">{{ result.action === 'delete' ? TEXTS.delete : TEXTS.trash }}</span>
				<template v-if="result.versions && result.versions.length > 0">
					<span class="dd-versions">{{ versionsText(result) }}</span>
					<ul class="dd-version-list">
						<li v-for="version in result.versions" :key="version.timestamp" data-testid="fcias-going-version">
							{{ versionText(version) }}
							<strong v-if="version.label" class="dd-named" :title="TEXTS.named">{{ version.label }}</strong>
						</li>
					</ul>
				</template>
				<span v-if="result.merge" class="dd-versions" data-testid="fcias-delete-merge">{{ mergeText(result) }}</span>
			</li>
		</ul>
		<p v-if="noCopy" class="dd-warning" data-testid="fcias-delete-no-copy">
			{{ noCopyText }}
		</p>
		<p v-if="permanent" class="dd-warning" data-testid="fcias-delete-permanent">
			{{ t('file_checksum_search', 'This cannot be undone.') }}
		</p>
	</NcDialog>
</template>

<style scoped>
.dd-rows {
	margin: 0;
	padding: 0;
	list-style: none;
}

.dd-row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.dd-row:last-child {
	border-bottom: none;
}

.dd-label {
	word-break: break-all;
}

.dd-action,
.dd-versions {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

/* The -text variants: the bare ones are background fills. */
.dd-reason {
	font-size: 12px;
	color: var(--color-error-text);
}

.dd-version-list {
	margin: 0;
	padding-inline-start: 16px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.dd-named {
	margin-inline-start: 4px;
	color: var(--color-main-text);
}

.dd-choice {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 16px;
	margin-bottom: 8px;
}

.dd-target {
	margin-bottom: 8px;
}

.dd-target legend {
	font-weight: bold;
}

.dd-warning {
	margin-top: 12px;
	font-weight: bold;
	color: var(--color-error-text);
}
</style>
