<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Sidebar tab showing indexed checksums for the selected file, with
 * SHA-1/MD5 recalc and inline duplicate lookup.
 */
import { watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { FRONTEND } from '../routes'
import { useSidebarHashes } from './composables/useSidebarHashes'
import { useClipboard } from './composables/useClipboard'
import type { DuplicateFile, FileNode } from './types'

const props = withDefaults(
	defineProps<{
		node?: FileNode | null
		active?: boolean
	}>(),
	{
		node: null,
		active: false,
	},
)

const {
	loading,
	hashes,
	error,
	recalculating,
	recalcError,
	duplicates,
	searching,
	dupError,
	showDuplicates,
	loadHashes,
	recalc,
	toggleDuplicates,
} = useSidebarHashes(() => props.node)

const { copied, copyToClipboard } = useClipboard()

function fileLink(file: DuplicateFile): string {
	const dirPath = file.path ? (file.path.substring(0, file.path.lastIndexOf('/')) || '/') : '/'
	return `${generateUrl(FRONTEND.fileLink, { fileid: file.fileid })}?dir=${encodeURIComponent(dirPath)}&opendetails=true`
}

watch(
	() => props.node,
	() => {
		void loadHashes()
	},
	{ immediate: true },
)
</script>

<template>
	<div class="fcias-container">
		<div v-if="loading" class="fcias-loading">
			<NcLoadingIcon :size="20" />
			<span>{{ t('file_checksum_search', 'Loading checksums …') }}</span>
		</div>
		<div v-else-if="error" class="fcias-error">{{ error }}</div>
		<div v-else-if="hashes.length === 0" class="fcias-empty">
			{{ t('file_checksum_search', 'No checksums available for this file.') }}
		</div>
		<table v-else class="fcias-hash-table">
			<tbody>
				<tr v-for="entry in hashes" :key="entry.algo">
					<td><span class="fcias-algo-badge">{{ entry.algo }}</span></td>
					<td class="fcias-hash-value">
						<span
							class="fcias-selectable-hash"
							:data-hash="entry.hash"
							:title="entry.updated_at ? `Last computed: ${entry.updated_at}` : undefined"
							@click="copyToClipboard(entry.hash)">{{ entry.hash }}</span>
					</td>
				</tr>
			</tbody>
		</table>

		<div v-if="!loading && !error" class="fcias-actions">
			<div class="fcias-recalc-row">
				<button
					class="fcias-recalc-btn"
					data-algo="sha1"
					:disabled="recalculating !== null"
					@click="recalc('sha1')">
					<NcLoadingIcon v-if="recalculating === 'sha1'" :size="14" />
					<span v-else>{{ recalcError === 'sha1' ? t('file_checksum_search', 'Error') : t('file_checksum_search', 'Recalc SHA-1') }}</span>
				</button>
				<button
					class="fcias-recalc-btn"
					data-algo="md5"
					:disabled="recalculating !== null"
					@click="recalc('md5')">
					<NcLoadingIcon v-if="recalculating === 'md5'" :size="14" />
					<span v-else>{{ recalcError === 'md5' ? t('file_checksum_search', 'Error') : t('file_checksum_search', 'Recalc MD5') }}</span>
				</button>
			</div>

			<div class="fcias-dup-section">
				<button class="fcias-dup-btn" :disabled="searching" @click="toggleDuplicates">
					{{ t('file_checksum_search', 'Find duplicates') }}
				</button>
				<div v-if="showDuplicates" class="fcias-dup-results">
					<div v-if="searching" class="fcias-loading">
						<NcLoadingIcon :size="14" />
						<span>{{ t('file_checksum_search', 'Searching …') }}</span>
					</div>
					<div v-else-if="dupError" class="fcias-error">{{ dupError }}</div>
					<div v-else-if="duplicates && duplicates.length === 0" class="fcias-empty">
						{{ t('file_checksum_search', 'No other files share checksums with this file.') }}
					</div>
					<div v-else>
						<div v-for="group in duplicates" :key="`${group.algo}-${group.hash_value}`" class="fcias-dup-group">
							<div class="fcias-dup-group-header">
								<span class="fcias-algo-badge">{{ group.algo }}</span>
								<span class="fcias-dup-hash-label">{{ group.hash_value }}</span>
							</div>
							<ul class="fcias-dup-list">
								<li v-for="file in group.files" :key="file.fileid" class="fcias-dup-item">
									<a class="fcias-dup-item-link" :href="fileLink(file)" target="_blank" rel="noreferrer noopener">{{ file.path }}</a>
								</li>
							</ul>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div v-if="copied" class="fcias-copied-toast">
			{{ t('file_checksum_search', 'Copied!') }}
		</div>
	</div>
</template>

<style scoped>
.fcias-algo-badge {
	display: inline-block;
	padding: 2px 6px;
	border-radius: 4px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	font-size: 0.85em;
	font-weight: 600;
	font-family: var(--font-face-monospace);
}

.fcias-hash-value {
	font-family: var(--font-face-monospace);
	font-size: 0.85em;
	word-break: break-all;
}

.fcias-selectable-hash {
	user-select: all;
	cursor: pointer;
}

.fcias-hash-table {
	width: 100%;
	border-collapse: collapse;
}

.fcias-hash-table td {
	padding: 4px 6px;
	vertical-align: top;
}

.fcias-hash-table td:first-child {
	width: 1%;
	white-space: nowrap;
}

.fcias-empty,
.fcias-loading,
.fcias-error {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 12px;
}

.fcias-loading {
	display: flex;
	align-items: center;
	gap: 6px;
}

.fcias-error {
	color: var(--color-error);
}

.fcias-actions {
	margin-top: 12px;
}

.fcias-recalc-row {
	margin: 8px 0;
	display: flex;
	gap: 8px;
}

.fcias-recalc-btn {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin: 0;
	padding: 4px 10px;
	font-size: 12px;
	cursor: pointer;
}

.fcias-recalc-btn:disabled {
	opacity: 0.5;
	cursor: default;
}

.fcias-dup-section {
	margin-top: 12px;
}

.fcias-dup-btn {
	padding: 4px 12px;
	font-size: 12px;
	cursor: pointer;
}

.fcias-dup-btn:disabled {
	opacity: 0.5;
	cursor: default;
}

.fcias-dup-results {
	margin-top: 8px;
}

.fcias-dup-group {
	margin-bottom: 10px;
}

.fcias-dup-group-header {
	margin-bottom: 4px;
}

.fcias-dup-hash-label {
	font-family: var(--font-face-monospace);
	font-size: 11px;
	word-break: break-all;
	color: var(--color-text-maxcontrast);
}

.fcias-dup-list {
	margin: 4px 0 0 0;
	padding: 0;
	list-style: none;
	font-size: 12px;
}

.fcias-dup-item {
	padding: 3px 0 3px 8px;
	border-inline-start: 2px solid var(--color-border);
	margin-bottom: 2px;
}

.fcias-dup-item-link {
	font-weight: 500;
	word-break: break-all;
	color: var(--color-main-text);
}

.fcias-dup-item-link:hover {
	color: var(--color-primary-element);
}

.fcias-copied-toast {
	position: fixed;
	bottom: 20px;
	inset-inline-start: 50%;
	transform: translateX(-50%);
	background: var(--color-success);
	color: #fff;
	padding: 8px 16px;
	border-radius: 4px;
	font-size: 13px;
	z-index: 9999;
	pointer-events: none;
}
</style>
