<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Collapsible duplicate group display.
 */

import { ref, computed } from 'vue'
import type { DuplicateGroup as GroupType } from '../composables/useDuplicates'

const props = defineProps<{
	group: GroupType
	fileUrl: (file: GroupType['files'][number]) => string
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
			<span class="db-count">{{ group.file_count }} files <span v-if="statusClass" class="db-group-header-status" :class="statusClass">{{ statusText }}</span></span>
		</div>
		<div class="db-group-body">
			<ul class="db-file-list">
				<li v-for="file in group.files" :key="file.fileid" class="db-file-item">
					<a :href="fileUrl(file)" target="_blank" rel="noreferrer noopener">{{ file.path || file.name }}</a>
					<span v-if="file.verified === true" class="db-verified">\u2713</span>
					<span v-else-if="file.verified === false" class="db-mismatch">\u2717 ({{ file.verify_error || (file.verified_hash ? `now: ${file.verified_hash}` : '?') }})</span>
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
	margin-right: 8px;
}
.db-hash {
	font-family: var(--font-face-monospace);
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	word-break: break-all;
}
.db-count {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	margin-left: 12px;
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
.db-file-item {
	padding: 6px 14px;
	border-top: 1px solid var(--color-border);
	font-size: 13px;
	background: var(--color-main-background);
}
.db-file-item a {
	color: var(--color-main-text);
}
.db-file-item a:hover {
	color: var(--color-primary-element);
}
.db-verified {
	color: var(--color-success);
	margin-left: 4px;
	font-weight: bold;
}
.db-mismatch {
	color: var(--color-error);
	margin-left: 4px;
}
.db-group-header-status {
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}
.db-group-header-status.verified {
	color: var(--color-success);
}
.db-group-header-status.mixed {
	color: var(--color-warning);
}
</style>
