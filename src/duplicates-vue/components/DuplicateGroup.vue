<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Collapsible duplicate group display.
 *
 * The header in columns of fixed width, so that groups line up down the
 * page: the preview, the count and each file's size, the algorithm, the
 * hash, and a menu. Each row: its checkbox while rows are chosen, its path,
 * a Version badge where it is one, its date, and a menu. What a group or a row
 * allows is in its menu, Verify among it, and nothing would only be
 * refused.
 */

import { ref, computed, watch } from 'vue'
import { formatFileSize } from '@nextcloud/files'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import LocationIcon from '../../components/LocationIcon.vue'
import MdiIcon from '../../components/MdiIcon.vue'
import {
	ICON_BIN,
	ICON_BIN_FOREVER,
	ICON_CHECKBOX,
	ICON_CLOSE,
	ICON_FILE,
	ICON_FILE_CHECK,
	ICON_FILE_PLUS,
	ICON_HISTORY,
	ICON_RESTORE,
	ICON_SELECT_ALL,
	ICON_SELECT_INVERSE,
} from '../../components/icons'
import { fileLabel, labelKind, trashLabel, versionLabel } from '../../fileLabel'
import { groupPreviewUrl } from '../../fileLink'
import type { DuplicateGroup as GroupType } from '../composables/useDuplicates'
import type { RowAction } from '../duplicateActions'
import { formatDateTime, n, t } from '../../l10n'

type FileItem = GroupType['files'][number]

/** One entry of a menu: what it says, shows, and does. */
interface MenuEntry {
	key: string
	icon: string
	label: string
	title?: string
	disabled?: boolean
	/** Red, for what deletes. */
	danger?: boolean
	run: () => void
}

// canVerify defaults to true: Vue reads an absent boolean prop as false.
const props = withDefaults(defineProps<{
	group: GroupType
	fileUrl: (file: FileItem) => string
	/** True while any verification is running; Verify waits for it. */
	verifying?: boolean
	/** False for an account that may not recalculate by hand: Verify is not offered. */
	canVerify?: boolean
	/** True on the viewer's own listing, the one the page acts on: each row offers what it allows. */
	canAct?: boolean
	/** True while an action is running; the menus wait for it. */
	acting?: boolean
}>(), { verifying: false, canVerify: true, canAct: false, acting: false })

const emit = defineEmits<{
	(e: 'verifyGroup', group: GroupType): void
	(e: 'verifyFile', group: GroupType, file: FileItem): void
	(e: 'act', group: GroupType, file: FileItem, action: RowAction): void
	(e: 'deleteSelected', group: GroupType, fileIds: number[]): void
}>()

const open = ref(false)

/** Whether the group's rows are being chosen for deletion, a checkbox each. */
const selecting = ref(false)

/** The rows chosen, by file id. */
const selected = ref<number[]>([])

/** A row that may be chosen: one the server allows to be deleted, one way or the other. */
function selectable(file: FileItem): boolean {
	return props.canAct && (file.actions?.includes('trash') === true || file.actions?.includes('delete') === true)
}

const selectableIds = computed(() => props.group.files.filter(selectable).map((file) => file.fileid))

function choose(fileId: number, chosen: boolean): void {
	selected.value = chosen
		? [...new Set([...selected.value, fileId])]
		: selected.value.filter((id) => id !== fileId)
}

/** Choosing begins with the rows in view. */
function startSelecting(): void {
	open.value = true
	selecting.value = true
}

function stopSelecting(): void {
	selecting.value = false
	selected.value = []
}

// A group read again is a new choice: what was chosen may be gone.
watch(() => props.group, stopSelecting)

const TEXTS = {
	verifyAll: t('file_checksum_search', 'Verify all'),
	verify: t('file_checksum_search', 'Verify'),
	verified: `✓ ${t('file_checksum_search', 'Verified')}`,
	// TRANSLATORS: a badge on a row of a group of duplicates: the row is an earlier version of the file it names
	version: t('file_checksum_search', 'Version'),
	// TRANSLATORS: the name of a group of duplicates' menu, for a reader that cannot see it; {hash} is the group's checksum
	groupMenu: t('file_checksum_search', 'Actions for the group {hash}', { hash: props.group.hash_value }),
}

const TITLES = {
	verifyAll: t('file_checksum_search', 'Read every file in this group again, recalculate its checksum and compare'),
	verify: t('file_checksum_search', 'Read this file again, recalculate its checksum and compare'),
	notYours: t('file_checksum_search', 'Not in your files'),
	// The same fact for a reader that never hovers, read after the label.
	notYoursSpoken: t('file_checksum_search', '(not in your files)'),
	// TRANSLATORS: the tooltip of a duplicate group's MIME type: it comes from what the files hold, not from their names
	mimetype: t('file_checksum_search', 'MIME type, detected from the content'),
}

/** Whether the group holds a file to read: a version, or a file in the trash, is not read again. */
const readable = computed(() => props.group.files.some((file) => !file.version && !file.trash))

/**
 * The group's menu: Verify all, where there is a file to read and the
 * account may; Select, where a row may be deleted; while rows are chosen,
 * what to do with the choice.
 */
const groupMenu = computed<MenuEntry[]>(() => {
	const entries: MenuEntry[] = []
	if (props.canVerify && readable.value) {
		entries.push({ key: 'verifyAll', icon: ICON_FILE_CHECK, label: TEXTS.verifyAll, title: TITLES.verifyAll, disabled: props.verifying, run: () => emit('verifyGroup', props.group) })
	}
	if (selectableIds.value.length === 0) {
		return entries
	}
	if (!selecting.value) {
		entries.push({ key: 'select', icon: ICON_CHECKBOX, label: t('file_checksum_search', 'Select'), disabled: props.acting, run: startSelecting })
		return entries
	}
	entries.push(
		{ key: 'selectAll', icon: ICON_SELECT_ALL, label: t('file_checksum_search', 'Select all'), run: () => { selected.value = [...selectableIds.value] } },
		{ key: 'invertSelection', icon: ICON_SELECT_INVERSE, label: t('file_checksum_search', 'Invert selection'), run: () => { selected.value = selectableIds.value.filter((id) => !selected.value.includes(id)) } },
		{ key: 'deleteSelected', icon: ICON_BIN, label: t('file_checksum_search', 'Delete selected'), danger: true, disabled: props.acting || selected.value.length === 0, run: () => emit('deleteSelected', props.group, [...selected.value]) },
		{ key: 'cancelSelection', icon: ICON_CLOSE, label: t('file_checksum_search', 'Cancel'), run: stopSelecting },
	)
	return entries
})

/**
 * What a row's menu offers, in the order a person looks for them: reading
 * it again, then bringing back before taking away. Taking another file's
 * versions is no action of its own, but a choice in a group's deletion.
 */
const ROW_ACTIONS: Array<{ action: RowAction, icon: string, label: string }> = [
	{ action: 'restore', icon: ICON_RESTORE, label: t('file_checksum_search', 'Restore') },
	// TRANSLATORS: a menu entry on a version of a file in the trash: add it to the versions of the file now at the place the trashed file had
	{ action: 'restoreAsVersion', icon: ICON_HISTORY, label: t('file_checksum_search', 'Restore into the file at its place') },
	// TRANSLATORS: a menu entry on a version of a file: make a new file holding what the version holds
	{ action: 'saveAsFile', icon: ICON_FILE_PLUS, label: t('file_checksum_search', 'Save as a new file') },
	{ action: 'trash', icon: ICON_BIN, label: t('file_checksum_search', 'Move to trash') },
	{ action: 'delete', icon: ICON_BIN_FOREVER, label: t('file_checksum_search', 'Delete permanently') },
]

/**
 * A row's menu. Verify where the account may and the row is read: a
 * version holds what its file held and nothing writes to it, nor to a
 * file in the trash. The rest as the server said the row allows, on the
 * viewer's own listing.
 */
function rowMenu(file: FileItem): MenuEntry[] {
	const entries: MenuEntry[] = []
	if (props.canVerify && !file.version && !file.trash) {
		entries.push({ key: 'verify', icon: ICON_FILE_CHECK, label: TEXTS.verify, title: TITLES.verify, disabled: props.verifying, run: () => emit('verifyFile', props.group, file) })
	}
	if (props.canAct) {
		for (const entry of ROW_ACTIONS.filter((candidate) => file.actions?.includes(candidate.action))) {
			entries.push({ key: entry.action, icon: entry.icon, label: entry.label, danger: entry.action === 'delete', disabled: props.acting, run: () => emit('act', props.group, file, entry.action) })
		}
	}
	return entries
}

/** The menu's name, for a reader that cannot see which row it is on. */
function rowMenuLabel(file: FileItem): string {
	return t('file_checksum_search', 'Actions for {name}', { name: fileLabel(file) })
}

/** The checkbox's name, for a reader that cannot see which row it is on. */
function selectLabel(file: FileItem): string {
	return t('file_checksum_search', 'Select {name}', { name: fileLabel(file) })
}

const statusClass = computed(() => {
	if (props.group.match_count === undefined || props.group.mismatch_count === undefined) return ''
	if (props.group.mismatch_count === 0) return 'verified'
	return 'mixed'
})

const statusText = computed(() => {
	if (props.group.match_count === undefined || props.group.mismatch_count === undefined) return ''
	if (props.group.mismatch_count === 0) return TEXTS.verified
	return `${props.group.match_count}✓ ${props.group.mismatch_count}✗`
})

const fileCount = computed(() => n('file_checksum_search', '%n file', '%n files', props.group.file_count))

/**
 * Each file's size, once for the group: they share their content, and so
 * their size. Null where the server gave none.
 */
const eachSize = computed(() => {
	const size = props.group.files.find((file) => file.size !== undefined && file.size >= 0)?.size
	// TRANSLATORS: under the number of files of a group of duplicates, the size of each; "à" as in "2 files à 208 B"
	return size === undefined ? null : t('file_checksum_search', 'à {size}', { size: formatFileSize(size) })
})

/** One thumbnail for the group: its files share their content, and so their preview. */
const previewUrl = computed(() => groupPreviewUrl(props.group.files))

/** A file's icon instead once it fails to load: a broken image tells nobody anything. */
const previewFailed = ref(false)
watch(previewUrl, () => {
	previewFailed.value = false
})

/** A row's date: when a version's file had that content, else when the file last changed. */
function rowDate(file: FileItem): { text: string, title: string } | null {
	if (file.version) {
		return { text: formatDateTime(new Date(file.version.timestamp)), title: versionLabel(file) as string }
	}
	if (file.mtime === undefined) {
		return null
	}
	const text = formatDateTime(new Date(file.mtime * 1000))
	return { text, title: t('file_checksum_search', 'Last changed {date}', { date: text }) }
}

/** Why a file's verification failed: the error, or the hash it has now. */
function mismatchText(file: FileItem): string {
	if (file.verify_error) return file.verify_error
	// TRANSLATORS: the checksum a file has now, after verification found it changed
	return file.verified_hash ? t('file_checksum_search', 'now: {hash}', { hash: file.verified_hash }) : '?'
}

function toggle(): void {
	open.value = !open.value
}
</script>

<template>
	<div class="db-group" :class="{ open }">
		<div class="db-group-header" @click="toggle">
			<!-- Decorative beside the rows that name the files: what they
			     hold, once, since a shared hash is shared content; a file's
			     glyph where there is none. -->
			<span class="db-preview-cell">
				<img v-if="previewUrl && !previewFailed"
					class="db-preview"
					data-testid="fcias-group-preview"
					:src="previewUrl"
					alt=""
					loading="lazy"
					@error="previewFailed = true">
				<MdiIcon v-else
					class="db-preview-none"
					:path="ICON_FILE"
					:size="28" />
			</span>
			<span class="db-count">
				<span data-testid="fcias-group-count">{{ fileCount }}</span>
				<span v-if="eachSize" class="db-each-size" data-testid="fcias-group-size">{{ eachSize }}</span>
			</span>
			<span class="db-algo-cell">
				<span class="db-algo-badge">{{ group.algo.toUpperCase() }}</span>
			</span>
			<span class="db-hash-cell">
				<!-- TRANSLATORS: a label on a group of duplicates whose files are all empty (zero bytes) -->
				<span v-if="group.empty" class="db-empty-badge" data-testid="fcias-empty-group">{{ t('file_checksum_search', 'Empty files') }}</span>
				<span class="db-hash">{{ group.hash_value }}</span>
				<!-- One per group: the files share their content, and so its type. -->
				<span v-if="group.mimetype"
					class="db-mimetype"
					data-testid="fcias-group-mimetype"
					:title="TITLES.mimetype">{{ group.mimetype }}</span>
			</span>
			<span class="db-status-cell">
				<span v-if="statusClass" class="db-badge db-group-header-status" :class="statusClass">{{ statusText }}</span>
			</span>
			<!-- Reading every file costs time and, on metered storage, money:
			     asked for here, per group, never for the whole page. The menu
			     is the group's, so a click on it leaves the group as it is. -->
			<span class="db-menu-cell" @click.stop>
				<!-- A menu even for a single entry, which NcActions would show
				     as a bare button: the same place, the same glyph, on every
				     group and every row. -->
				<NcActions v-if="groupMenu.length > 0"
					class="db-group-actions"
					force-menu
					:aria-label="TEXTS.groupMenu">
					<NcActionButton v-for="entry in groupMenu"
						:key="entry.key"
						:data-action="entry.key"
						:class="{ 'db-action-danger': entry.danger }"
						:title="entry.title"
						:disabled="entry.disabled"
						close-after-click
						@click="entry.run()">
						<template #icon>
							<MdiIcon :path="entry.icon" />
						</template>
						{{ entry.label }}
					</NcActionButton>
				</NcActions>
			</span>
		</div>
		<div class="db-group-body">
			<ul class="db-file-list">
				<li v-for="file in group.files" :key="file.fileid" class="db-file-item">
					<!-- The test id lands on the input, as every passed attribute does. -->
					<span v-if="selecting" class="db-select-cell">
						<NcCheckboxRadioSwitch v-if="selectable(file)"
							class="db-select-row"
							data-testid="fcias-select-row"
							:aria-label="selectLabel(file)"
							:model-value="selected.includes(file.fileid)"
							@update:model-value="choose(file.fileid, $event)" />
					</span>
					<span class="db-file-label">
						<!-- The viewer's own files by the path they know; anyone
						     else's by where they live, or three accounts' copies
						     of one template read as the same row three times. -->
						<!-- The glyph rides inside the label, so the label's text is
						     still the label: a location gets one for its kind of
						     place, a plain path none. -->
						<a v-if="file.openable !== false"
							:href="fileUrl(file)"
							target="_blank"
							rel="noreferrer noopener"><LocationIcon v-if="labelKind(file)" :kind="labelKind(file)!" :size="14" />{{ fileLabel(file) }}</a>
						<!-- A link resolves in the viewer's own folder; a file they do
						     not hold would only open to "not found". The title is
						     for the pointer; the hidden text is the same fact for
						     a reader that never hovers. -->
						<span v-else class="db-file-unopenable" :title="TITLES.notYours"><LocationIcon v-if="labelKind(file)" :kind="labelKind(file)!" :size="14" />{{ fileLabel(file) }}<span class="hidden-visually"> {{ TITLES.notYoursSpoken }}</span></span>
						<!-- In the trash, where the link opens: it can be restored there. -->
						<span v-if="file.trash" class="db-trash" data-testid="fcias-trash">{{ trashLabel(file) }}</span>
					</span>
					<!-- The header's badge, for the one file, where a version's
					     is: a version is never read again, so never both. -->
					<span v-if="file.verified === true" class="db-badge db-verified">{{ TEXTS.verified }}</span>
					<span v-else-if="file.verified === false" class="db-badge db-mismatch">✗ {{ mismatchText(file) }}</span>
					<!-- An earlier version of the file the label names, which the
					     link opens: the version is in its details. -->
					<span v-if="file.version"
						class="db-badge db-version-badge"
						data-testid="fcias-version"
						:title="versionLabel(file) ?? undefined">{{ TEXTS.version }}</span>
					<span v-if="rowDate(file)"
						class="db-file-date"
						data-testid="fcias-row-date"
						:title="rowDate(file)!.title">{{ rowDate(file)!.text }}</span>
					<span class="db-menu-cell">
						<NcActions v-if="rowMenu(file).length > 0"
							class="db-file-actions"
							force-menu
							:aria-label="rowMenuLabel(file)">
							<NcActionButton v-for="entry in rowMenu(file)"
								:key="entry.key"
								:data-action="entry.key"
								:class="{ 'db-action-danger': entry.danger }"
								:title="entry.title"
								:disabled="entry.disabled"
								close-after-click
								@click="entry.run()">
								<template #icon>
									<MdiIcon :path="entry.icon" />
								</template>
								{{ entry.label }}
							</NcActionButton>
						</NcActions>
					</span>
				</li>
			</ul>
		</div>
	</div>
</template>

<style scoped>
.db-group {
	border-bottom: 1px solid var(--color-border);
}

.db-group:last-child {
	border-bottom: none;
}

/* Columns of fixed width, the hash taking what is left, so that the
   groups of a page line up under each other. */
.db-group-header {
	display: grid;
	grid-template-columns: 36px 96px 84px minmax(0, 1fr) auto 36px;
	gap: 12px;
	align-items: center;
	padding: 8px 14px;
	background: var(--color-background-dark);
	cursor: pointer;
}

.db-group-header:hover {
	background: var(--color-background-hover);
}

.db-preview-cell {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
}

.db-preview {
	width: 36px;
	height: 36px;
	object-fit: cover;
	border-radius: var(--border-radius);
}

.db-preview-none {
	color: var(--color-text-maxcontrast);
}

/* The count over each file's size, in the count's small type, right-aligned
   so the numbers' last digits line up. */
.db-count {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	text-align: end;
	font-size: 12px;
	line-height: 1.3;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
}

.db-algo-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius);
	background: var(--color-background-darker);
	font-weight: 600;
	font-size: 12px;
}

/* min-width lets a long hash wrap instead of pushing the menu out. */
.db-hash-cell {
	min-width: 0;
}

/* Before the hash, in outline rather than fill: it describes the group,
   it is not a second algorithm. */
.db-empty-badge {
	display: inline-block;
	padding: 1px 8px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius);
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-inline-end: 8px;
}

.db-hash {
	font-family: var(--font-face-monospace);
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	word-break: break-all;
}

/* After the hash it describes the content of, framed as the empty files'
   badge is, so it reads as a fact about the group and not part of the hash. */
.db-mimetype {
	display: inline-block;
	padding: 1px 8px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius);
	font-family: var(--font-face-monospace);
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-inline-start: 8px;
	word-break: break-all;
}

.db-menu-cell {
	display: flex;
	justify-content: flex-end;
	width: 36px;
	flex: 0 0 36px;
}

/* Small enough to sit in a row without becoming the row's subject. */
.db-group-actions,
.db-file-actions {
	--default-clickable-area: 32px;
}

/* What deletes, in the palette's text red: the bare --color-error is a
   background fill, which on a dark theme all but vanishes. */
.db-action-danger :deep(.action-button) {
	color: var(--color-error-text);
}

.db-group-body {
	display: none;
}

.db-group.open .db-group-body {
	display: block;
}

.db-file-list {
	margin: 0;
	padding: 0;
	list-style: none;
}

/* The menu on the same right edge as the group's, the path taking what is left. */
.db-file-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 14px;
	border-top: 1px solid var(--color-border);
	font-size: 13px;
	background: var(--color-main-background);
}

.db-select-cell {
	display: flex;
	flex: 0 0 36px;
	width: 36px;
}

/* min-width lets the path shrink instead of pushing the menu out. */
.db-file-label {
	flex: 1 1 auto;
	min-width: 0;
	word-break: break-all;
}

.db-file-item a {
	color: var(--color-main-text);
}

.db-file-item a:hover {
	color: var(--color-primary-element);
}

/* One shape for what is said of a group or a row: outlined in its text's
   colour, which reads on a light theme and a dark one alike, where text on
   a coloured fill would not. */
.db-badge {
	display: inline-block;
	padding: 1px 8px;
	border: 1px solid currentColor;
	border-radius: var(--border-radius-pill, 12px);
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

/* An earlier version, at a glance, in the palette's warning yellow. */
.db-version-badge {
	flex: 0 0 auto;
	color: var(--color-warning-text);
}

/* Every part padded to two digits, in figures of one width: the dates of
   a group line up against the menu. */
.db-file-date {
	flex: 0 0 auto;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
}

/* The -text variants, not the bare ones: --color-success and --color-error
   are the *background* fills, which on a dark theme all but vanish against
   the row they sit on — a verdict nobody can read is no verdict. */
.db-verified {
	flex: 0 0 auto;
	color: var(--color-success-text);
}

.db-trash {
	color: var(--color-text-maxcontrast);
	margin-inline-start: 4px;
}

/* The hash it has now may wrap, as the path does, rather than push the
   date and the menu out. */
.db-mismatch {
	flex: 0 1 auto;
	min-width: 0;
	color: var(--color-error-text);
	white-space: normal;
	word-break: break-all;
}

.db-group-header-status.verified {
	color: var(--color-success-text);
}

.db-group-header-status.mixed {
	color: var(--color-warning-text);
}
</style>
