<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The confirmation before rows of a duplicate group are deleted: what the
 * server's dry run said would happen to each, the earlier versions that go
 * along with a file, and whether it can be undone.
 */

import { computed } from 'vue'
import { formatFileSize } from '@nextcloud/files'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { fileLabel, trashLabel, versionLabel } from '../../fileLabel'
import type { DuplicateGroup as GroupType } from '../composables/useDuplicates'
import type { GoingVersion, RowResult } from '../duplicateActions'
import { formatDateTime, n, t } from '../../l10n'

const props = defineProps<{
	group: GroupType
	/** The dry run's answer, a row each. */
	results: RowResult[]
	/** Past the trash: nothing comes back. */
	forGood: boolean
	/** True while the deletion runs. */
	busy?: boolean
}>()

const emit = defineEmits<{
	(e: 'confirm'): void
	(e: 'close'): void
}>()

const TEXTS = {
	trash: t('file_checksum_search', 'Move to trash'),
	delete: t('file_checksum_search', 'Delete permanently'),
	// TRANSLATORS: the title of a version a person named, shown beside its name
	named: t('file_checksum_search', 'Named version'),
}

/** Named after what its button does, as a confirmation that asks nothing else. */
const title = computed(() => props.forGood ? TEXTS.delete : TEXTS.trash)

/** Whether anything is lost for good: a file deleted so, or any row that has no trash to go to. */
const permanent = computed(() => props.results.some((result) => result.action === 'delete'))

const ready = computed(() => props.results.some((result) => result.status === 'ready'))

// Cancel closes the dialog itself, which reports it through onOpen().
const buttons = computed(() => [
	{ label: t('file_checksum_search', 'Cancel') },
	{
		label: props.forGood ? TEXTS.delete : TEXTS.trash,
		variant: props.forGood ? 'error' as const : 'primary' as const,
		disabled: !ready.value || props.busy,
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
			</li>
		</ul>
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

.dd-warning {
	margin-top: 12px;
	font-weight: bold;
	color: var(--color-error-text);
}
</style>
