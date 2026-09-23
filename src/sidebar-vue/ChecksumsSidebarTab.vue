<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Sidebar tab showing the checksums stored for the selected file.
 *
 * Recalculation is offered twice over: one or two quick buttons composed for
 * this file and this user ({@see quickAlgos}), and a picker holding
 * everything the instance computes. Duplicate lookup is inline.
 */
import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { FRONTEND } from '../routes'
import { fileLabel, labelKind } from '../fileLabel'
import AlgorithmSelect from '../components/AlgorithmSelect.vue'
import LocationIcon from '../components/LocationIcon.vue'
import { type AlgoOption, fetchAlgorithms } from '../algorithms'
import { crossAccountUrl, hashForLink } from './crossAccountLink'
import { useSidebarHashes } from './composables/useSidebarHashes'
import { useClipboard } from './composables/useClipboard'
import RecalcButton from './components/RecalcButton.vue'
import SectionHeader from './components/SectionHeader.vue'
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
	ruleAlgos,
	preferredAlgo,
	defaultAlgo,
	canRecalc,
	canSudo,
	error,
	recalculating,
	recalcError,
	recalcErrorMessage,
	duplicates,
	searching,
	dupError,
	showDuplicates,
	loadHashes,
	recalc,
	toggleDuplicates,
} = useSidebarHashes(() => props.node)

/**
 * The way to the Duplicates page's Others tab, for a viewer who may look
 * across accounts and a file that has a hash to look for. One link, not a
 * switch: the tab has the picker, the amber ground and the row labels, and
 * a second copy in this pane would be a smaller, worse one. The address
 * carries the hash and names the whole reach, so it lands on the group.
 */
const crossAccountHref = computed<string | null>(() => {
	if (!canSudo.value) {
		return null
	}
	const entry = hashForLink(hashes.value, preferredAlgo.value || defaultAlgo.value)
	return entry ? crossAccountUrl(entry) : null
})

const { copied, copyToClipboard } = useClipboard()

/** The quick buttons' captions; anything else is its id in capitals. */
const QUICK_LABELS: Record<string, string> = { sha1: 'SHA-1', md5: 'MD5' }

/**
 * Which algorithms get a one-click button, for this file and this user.
 *
 * First the user's preference, else the instance default. Second, the first
 * algorithm the file's governing rule computes that is not already first —
 * so a user whose rules produce SHA-256 sees it, and one who chose MD5 sees
 * MD5 beside whatever their rules do. One button when they coincide, or when
 * no rule maintains the file. Everything else is in the picker.
 */
const quickAlgos = computed<AlgoOption[]>(() => {
	const first = preferredAlgo.value || defaultAlgo.value
	if (!first) {
		return []
	}
	const second = ruleAlgos.value.find((a) => a !== first)
	return [first, ...(second ? [second] : [])]
		.map((id) => ({ id, label: QUICK_LABELS[id] ?? id.toUpperCase() }))
})

// The picker offers what the instance computes, read from the server once
// per page. Until it answers the picker is empty and the quick buttons still
// work; if it never answers it stays empty, which is what "nothing to pick"
// looks like.
const algorithmIds = ref<string[]>([])
const selectedAlgo = ref('sha256')

fetchAlgorithms()
	.then(({ algorithms, default: fallback }) => {
		algorithmIds.value = algorithms
		if (!algorithms.includes(selectedAlgo.value)) {
			selectedAlgo.value = algorithms.includes('sha256') ? 'sha256' : fallback
		}
	})
	.catch(() => {
		// Nothing to offer beyond the quick buttons; leave the picker empty.
	})

function onRecalc(algo: string | null): void {
	recalc(algo === null ? selectedAlgo.value : algo)
}

// No `dir`: core resolves the id in the viewer's own folder and works the
// directory out for itself, so one sent along was never read.
function fileLink(file: DuplicateFile): string {
	return `${generateUrl(FRONTEND.fileLink, { fileid: file.fileid })}?opendetails=true`
}

watch(
	() => props.node,
	() => {
		// Fire-and-forget: loadHashes() reports its own failures through the
		// `error` ref, so there is nothing for a caller to await or catch.
		loadHashes()
	},
	{ immediate: true },
)
</script>

<template>
	<div class="fcias-container">
		<section class="fcias-section">
			<SectionHeader
				:title="t('file_checksum_search', 'Checksums')"
				:help="t('file_checksum_search', 'Checksums indexed for this file. Click a hash to copy it.')" />
			<div v-if="loading" class="fcias-loading">
				<NcLoadingIcon :size="20" />
				<span>{{ t('file_checksum_search', 'Loading checksums …') }}</span>
			</div>
			<div v-else-if="error" class="fcias-error">
				{{ error }}
			</div>
			<div v-else-if="hashes.length === 0" class="fcias-empty">
				{{ t('file_checksum_search', 'No checksums available for this file.') }}
			</div>
			<div v-else class="fcias-hash-table-wrap">
				<table class="fcias-hash-table">
					<tbody>
						<tr v-for="entry in hashes" :key="entry.algo">
							<td><span class="fcias-algo-badge">{{ entry.algo }}</span></td>
							<td class="fcias-hash-value">
								<span
									class="fcias-selectable-hash"
									:data-hash="entry.hash"
									:title="entry.hash"
									@click="copyToClipboard(entry.hash)">{{ entry.hash }}</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>

		<!-- Hidden, not disabled, for a user the manual-recalculation permission
		     does not name: a button that can only fail is not an offer. -->
		<section v-if="!loading && !error && canRecalc" class="fcias-section">
			<SectionHeader
				:title="t('file_checksum_search', 'Recalculate')"
				:help="t('file_checksum_search', 'Compute a checksum for the selected algorithm.')" />
			<div class="fcias-recalc-row">
				<RecalcButton
					v-for="quick in quickAlgos"
					:key="quick.id"
					:algo="quick.id"
					:label="quick.label"
					:recalculating="recalculating"
					:recalc-error="recalcError"
					@recalc="onRecalc" />

				<div class="fcias-recalc-custom">
					<div class="fcias-algo-select-wrap">
						<AlgorithmSelect
							v-model="selectedAlgo"
							:algorithms="algorithmIds"
							label="Algorithm" />
					</div>
					<RecalcButton
						:label="t('file_checksum_search', 'Recalc')"
						:recalculating="recalculating"
						:recalc-error="recalcError"
						@recalc="onRecalc" />
				</div>
			</div>
			<p v-if="recalcErrorMessage" class="fcias-error fcias-recalc-error">
				{{ recalcErrorMessage }}
			</p>
		</section>

		<section v-if="!loading && !error" class="fcias-section">
			<SectionHeader
				:title="t('file_checksum_search', 'Duplicates')"
				:help="t('file_checksum_search', 'Find other files sharing a checksum with this file.')" />
			<div class="fcias-dup-actions">
				<button class="fcias-dup-btn" :disabled="searching" @click="toggleDuplicates">
					{{ t('file_checksum_search', 'Find duplicates') }}
				</button>
				<a v-if="crossAccountHref"
					class="fcias-dup-across"
					data-testid="fcias-dup-across"
					:href="crossAccountHref"
					target="_blank"
					rel="noreferrer noopener"
					:title="t('file_checksum_search', 'Open the Duplicates page on this hash, across every account you may see')">
					{{ t('file_checksum_search', 'Find across accounts') }}
				</a>
			</div>
			<div v-if="showDuplicates" class="fcias-dup-results">
				<div v-if="searching" class="fcias-loading">
					<NcLoadingIcon :size="14" />
					<span>{{ t('file_checksum_search', 'Searching …') }}</span>
				</div>
				<div v-else-if="dupError" class="fcias-error">
					{{ dupError }}
				</div>
				<div v-else-if="duplicates && duplicates.length === 0" class="fcias-empty">
					{{ t('file_checksum_search', 'No other files share checksums with this file.') }}
				</div>
				<div v-else>
					<div v-for="group in duplicates"
						:key="`${group.algo}-${group.hash_value}`"
						class="fcias-dup-group">
						<div class="fcias-dup-group-header">
							<span class="fcias-algo-badge">{{ group.algo }}</span>
							<span class="fcias-dup-hash-label">{{ group.hash_value }}</span>
						</div>
						<ul class="fcias-dup-list">
							<li v-for="file in group.files" :key="file.fileid" class="fcias-dup-item">
								<a v-if="file.openable !== false"
									class="fcias-dup-item-link"
									:href="fileLink(file)"
									target="_blank"
									rel="noreferrer noopener"><LocationIcon v-if="labelKind(file)" :kind="labelKind(file)!" :size="14" />{{ fileLabel(file) }}</a>
								<span v-else class="fcias-dup-item-unopenable" title="Not in your files"><LocationIcon v-if="labelKind(file)" :kind="labelKind(file)!" :size="14" />{{ fileLabel(file) }}</span>
							</li>
						</ul>
					</div>
				</div>
			</div>
		</section>

		<div v-if="copied" class="fcias-copied-toast">
			{{ t('file_checksum_search', 'Copied!') }}
		</div>
	</div>
</template>

<style scoped>
.fcias-container {
	overflow-x: hidden;
}

.fcias-section {
	margin: 12px 0;
}

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

.fcias-hash-table-wrap {
	overflow-x: auto;
	max-width: 100%;
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

.fcias-recalc-error {
	margin-top: 8px;
}

.fcias-error {
	color: var(--color-error);
}

.fcias-recalc-row {
	margin: 8px 0;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.fcias-recalc-custom {
	display: flex;
	flex-shrink: 0;
}

.fcias-algo-select-wrap {
	flex: 0 0 185px;
	max-width: 185px;
}

.fcias-algo-select-wrap :deep(> div.v-select.vs--single.vs--searchable.select > div.vs__dropdown-toggle) {
	max-width: 185px;
}

.fcias-algo-select-wrap :deep(> div.v-select.vs--single.vs--searchable.select) {
	min-width: 185px;
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

.fcias-dup-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

/* A link dressed as the button beside it, so the two read as one pair of
   choices — its own class, since the spec clicks the button by its — and
   the amber edge is the Others tab's ground, announced early. */
.fcias-dup-across {
	display: inline-block;
	padding: 4px 12px;
	font-size: 12px;
	text-decoration: none;
	color: var(--color-main-text);
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.fcias-dup-across:hover,
.fcias-dup-across:focus-visible {
	background: var(--color-warning);
	color: var(--color-warning-text);
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
	color: #ffffff;
	padding: 8px 16px;
	border-radius: 4px;
	font-size: 13px;
	z-index: 9999;
	pointer-events: none;
}
</style>
