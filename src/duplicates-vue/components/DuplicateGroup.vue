<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Collapsible duplicate group display.
 */

import { ref, computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import LocationIcon from '../../components/LocationIcon.vue'
import { fileLabel, labelKind } from '../../fileLabel'
import type { DuplicateGroup as GroupType } from '../composables/useDuplicates'

const props = defineProps<{
	group: GroupType
	fileUrl: (file: GroupType['files'][number]) => string
	/** True while any verification is running; both buttons wait for it. */
	verifying?: boolean
}>()

const emit = defineEmits<{
	(e: 'verifyGroup', group: GroupType): void
	(e: 'verifyFile', group: GroupType, file: GroupType['files'][number]): void
}>()

const open = ref(false)

const statusClass = computed(() => {
	if (props.group.match_count === undefined || props.group.mismatch_count === undefined) return ''
	if (props.group.mismatch_count === 0) return 'verified'
	return 'mixed'
})

const statusText = computed(() => {
	if (props.group.match_count === undefined || props.group.mismatch_count === undefined) return ''
	if (props.group.mismatch_count === 0) return '\u2713 Verified'
	return `${props.group.match_count}\u2713 ${props.group.mismatch_count}\u2717`
})

function toggle(): void {
	open.value = !open.value
}
</script>

<template>
	<div class="db-group" :class="{ open }">
		<div class="db-group-header" @click="toggle">
			<div>
				<span class="db-algo-badge">{{ group.algo.toUpperCase() }}</span>
				<span class="db-hash">{{ group.hash_value }}</span>
			</div>
			<span class="db-count">
				<span>{{ group.file_count }} files</span>
				<span v-if="statusClass" class="db-group-header-status" :class="statusClass">{{ statusText }}</span>
				<!-- Reading every file in the group costs time and, on metered
				     storage, money — so it is asked for here, per group, and
				     never for the whole page at once. -->
				<NcButton
					class="db-verify-all"
					:disabled="verifying"
					title="Recompute every file in this group from its contents"
					@click.stop="emit('verifyGroup', group)">
					Verify all
				</NcButton>
			</span>
		</div>
		<div class="db-group-body">
			<ul class="db-file-list">
				<li v-for="file in group.files" :key="file.fileid" class="db-file-item">
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
						<span v-else class="db-file-unopenable" title="Not in your files"><LocationIcon v-if="labelKind(file)" :kind="labelKind(file)!" :size="14" />{{ fileLabel(file) }}<span class="hidden-visually"> (not in your files)</span></span>
						<span v-if="file.verified === true" class="db-verified">✓</span>
						<span v-else-if="file.verified === false" class="db-mismatch">✗ ({{ file.verify_error || (file.verified_hash ? `now: ${file.verified_hash}` : '?') }})</span>
					</span>
					<NcButton
						class="db-verify-file"
						:disabled="verifying"
						title="Recompute this file from its contents"
						@click="emit('verifyFile', group, file)">
						Verify
					</NcButton>
				</li>
			</ul>
		</div>
	</div>
</template>

<style scoped>
.db-group {
	border-bottom: 1px solid var(--color-border);
}

/* Small enough to sit in a row without becoming the row's subject, and
   never squeezed by a long path or a long hash beside it. */
.db-verify-all,
.db-verify-file {
	--default-clickable-area: 28px;

	display: inline-flex;
	flex: 0 0 auto;
}

.db-group:last-child {
	border-bottom: none;
}

.db-group-header {
	padding: 10px 14px;
	background: var(--color-background-dark);
	cursor: pointer;
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.db-group-header:hover {
	background: var(--color-background-hover);
}

.db-algo-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius);
	background: var(--color-background-darker);
	font-weight: 600;
	font-size: 12px;
	margin-inline-end: 8px;
}

.db-hash {
	font-family: var(--font-face-monospace);
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	word-break: break-all;
}

/* The count, the verdict and the button share one right-aligned row: the
   button belongs after the number it acts on, not under it. */
.db-count {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 8px;
	flex: 0 0 auto;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	margin-inline-start: 12px;
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

/* Same row shape as the header, so every button on the page lines up on
   one right edge rather than wherever its path happened to end. */
.db-file-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 6px 14px;
	border-top: 1px solid var(--color-border);
	font-size: 13px;
	background: var(--color-main-background);
}

/* min-width lets the path shrink instead of pushing the button out. */
.db-file-label {
	min-width: 0;
	word-break: break-all;
}

.db-file-item a {
	color: var(--color-main-text);
}

.db-file-item a:hover {
	color: var(--color-primary-element);
}

/* The -text variants, not the bare ones: --color-success and --color-error
   are the *background* fills, which on a dark theme all but vanish against
   the row they sit on — a verdict nobody can read is no verdict. */
.db-verified {
	color: var(--color-success-text);
	margin-inline-start: 4px;
	font-weight: bold;
}

.db-mismatch {
	color: var(--color-error-text);
	margin-inline-start: 4px;
}

.db-group-header-status {
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.db-group-header-status.verified {
	color: var(--color-success-text);
}

.db-group-header-status.mixed {
	color: var(--color-warning-text);
}
</style>
