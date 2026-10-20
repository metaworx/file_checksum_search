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
import { t } from '../l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { rowLink } from '../fileLink'
import { fileLabel, labelKind, trashLabel, versionLabel } from '../fileLabel'
import AlgorithmSelect from '../components/AlgorithmSelect.vue'
import LocationIcon from '../components/LocationIcon.vue'
import { type AlgoOption, fetchAlgorithms } from '../algorithms'
import { crossAccountUrl, emptyGroupUrl, hashForLink } from './crossAccountLink'
import { useSidebarHashes } from './composables/useSidebarHashes'
import { useClipboard } from '../composables/useClipboard'
import RecalcButton from './components/RecalcButton.vue'
import SectionHeader from './components/SectionHeader.vue'
import type { FileNode, VersionEntry } from './types'

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
	mimeTypeShown,
	mimeType,
	versions,
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

/** The table's rows, in the order the server keyed them. */
const hashRows = computed(() => Object.values(hashes.value))

const MIME_TITLE = t('file_checksum_search', 'Detected from the file\'s content')

const VERSIONS = {
	help: t('file_checksum_search', 'Checksums of this file\'s older versions, newest first: what the file held before. Click one to copy it.'),
	// TRANSLATORS: in the list of a file's older versions, for a version no checksum is kept of
	none: t('file_checksum_search', 'No checksums for this version.'),
}

/** A version's heading: when the file held its content, as the lists name it. */
function versionTitle(version: VersionEntry): string {
	return versionLabel({ version }) ?? ''
}

/**
 * Nextcloud's own type for the file, where it differs from the content's:
 * Nextcloud takes it from the name, so a renamed file can disagree with
 * itself. Nothing where they agree, or where the content has no type yet.
 */
const byNameText = computed<string>(() => {
	const byName = props.node?.mime
	if (!mimeType.value || !byName || byName === mimeType.value) {
		return ''
	}
	// TRANSLATORS: Nextcloud's own MIME type for the file, which it takes from the file's name; {type} is that MIME type, such as image/jpeg
	return t('file_checksum_search', 'By its name: {type}', { type: byName })
})

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
	return entry ? crossAccountUrl(entry, props.node?.size === 0) : null
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

// The space before "…" is a no-break space (U+00A0), as Nextcloud writes it.
// It is the character itself: the translation tool would keep an escape as
// text, and the text would no longer match its translation.
const progress = {
	loading: t('file_checksum_search', 'Loading checksums …'),
	searching: t('file_checksum_search', 'Searching …'),
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
				:help="t('file_checksum_search', 'Checksums indexed for this file. Click one to copy it.')" />
			<div v-if="loading" class="fcias-loading">
				<NcLoadingIcon :size="20" />
				<span>{{ progress.loading }}</span>
			</div>
			<div v-else-if="error" class="fcias-error">
				{{ error }}
			</div>
			<div v-else-if="hashRows.length === 0" class="fcias-empty">
				{{ t('file_checksum_search', 'No checksums available for this file.') }}
			</div>
			<div v-else class="fcias-hash-table-wrap">
				<table class="fcias-hash-table">
					<tbody>
						<tr v-for="entry in hashRows" :key="entry.algo">
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
			<!-- The content's type, beside the checksums of the same content:
			     shown while the server detects types, for a file that has
			     checksums. Nextcloud's own, from the name, follows where the
			     two differ, as a fact and not a warning: libmagic and Nextcloud
			     name some harmless contents differently. -->
			<p v-if="!loading && !error && mimeTypeShown && hashRows.length > 0" class="fcias-mimetype" data-testid="fcias-mimetype">
				<span class="fcias-mimetype-label" :title="MIME_TITLE">{{ t('file_checksum_search', 'MIME type') }}</span>
				<span v-if="mimeType" class="fcias-mimetype-value">{{ mimeType }}</span>
				<span v-else class="fcias-mimetype-none">{{ t('file_checksum_search', 'Not detected yet') }}</span>
				<span v-if="byNameText" class="fcias-mimetype-name" data-testid="fcias-mimetype-name">{{ byNameText }}</span>
			</p>
		</section>

		<!-- The file's older versions, each with the checksums it holds: what
		     the file held before, and which version holds a checksum the file
		     no longer does. Shown for a file that has any. -->
		<section v-if="!loading && !error && versions.length > 0" class="fcias-section" data-testid="fcias-versions">
			<SectionHeader
				:title="t('file_checksum_search', 'Versions')"
				:help="VERSIONS.help" />
			<div v-for="version in versions"
				:key="version.fileid"
				class="fcias-version"
				data-testid="fcias-version">
				<div class="fcias-version-title">
					{{ versionTitle(version) }}
				</div>
				<table v-if="Object.keys(version.hashes).length > 0" class="fcias-hash-table">
					<tbody>
						<tr v-for="entry in Object.values(version.hashes)" :key="entry.algo">
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
				<div v-else class="fcias-empty">
					{{ VERSIONS.none }}
				</div>
			</div>
		</section>

		<!-- Hidden, not disabled, for a user the manual-recalculation permission
		     does not name: a button that can only fail is not an offer. -->
		<section v-if="!loading && !error && canRecalc" class="fcias-section">
			<SectionHeader
				:title="t('file_checksum_search', 'Recalculate')"
				:help="t('file_checksum_search', 'Read the file again and recalculate its checksum with the chosen algorithm.')" />
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
							:label="t('file_checksum_search', 'Algorithm')" />
					</div>
					<RecalcButton
						:label="t('file_checksum_search', 'Recalculate')"
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
					:title="t('file_checksum_search', 'Open the Duplicates page on this checksum, across every account you may see')">
					{{ t('file_checksum_search', 'Find across accounts') }}
				</a>
			</div>
			<div v-if="showDuplicates" class="fcias-dup-results">
				<div v-if="searching" class="fcias-loading">
					<NcLoadingIcon :size="14" />
					<span>{{ progress.searching }}</span>
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
						<!-- The empty files' group would list every empty file the
						     viewer holds — and the lookup stops at a hundred, so
						     neither the list nor its length would be the whole of
						     it. The Duplicates page is where it is complete. -->
						<p v-if="group.empty" class="fcias-dup-empty" data-testid="fcias-dup-empty">
							<span>{{ t('file_checksum_search', 'Every empty file has this checksum.') }}</span>
							<a class="fcias-dup-empty-link"
								:href="emptyGroupUrl(group.algo, group.hash_value)"
								target="_blank"
								rel="noreferrer noopener">
								<!-- TRANSLATORS: a link that opens the Duplicates page (the page's name, as in the top navigation) on this group, in a new tab -->
								{{ t('file_checksum_search', 'Show in Duplicates') }}
							</a>
						</p>
						<ul v-else class="fcias-dup-list">
							<li v-for="file in group.files" :key="file.fileid" class="fcias-dup-item">
								<a v-if="file.openable !== false"
									class="fcias-dup-item-link"
									:href="rowLink(file)"
									target="_blank"
									rel="noreferrer noopener"><LocationIcon v-if="labelKind(file)" :kind="labelKind(file)!" :size="14" />{{ fileLabel(file) }}</a>
								<span v-else class="fcias-dup-item-unopenable" :title="t('file_checksum_search', 'Not in your files')"><LocationIcon v-if="labelKind(file)" :kind="labelKind(file)!" :size="14" />{{ fileLabel(file) }}</span>
								<span v-if="file.version" class="fcias-dup-version" data-testid="fcias-dup-version">{{ versionLabel(file) }}</span>
								<span v-if="file.trash" class="fcias-dup-version" data-testid="fcias-dup-trash">{{ trashLabel(file) }}</span>
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

/* The -light-text partner of the -light fill: -text is the colour for the
   full primary fill, and on a dark theme the two lights are both dark. */
.fcias-algo-badge {
	display: inline-block;
	padding: 2px 6px;
	border-radius: 4px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
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

/* The type on a line of its own under the checksums, its label as the
   algorithm badges are muted beside them, the name's type below it. */
.fcias-mimetype {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 2px 8px;
	margin-top: 8px;
	font-size: 0.9em;
}

.fcias-mimetype-label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.fcias-mimetype-value {
	font-family: var(--font-face-monospace);
	word-break: break-all;
}

.fcias-mimetype-none,
.fcias-mimetype-name {
	color: var(--color-text-maxcontrast);
}

.fcias-mimetype-name {
	flex-basis: 100%;
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

.fcias-dup-version {
	display: block;
	color: var(--color-text-maxcontrast);
}

.fcias-version + .fcias-version {
	margin-top: 8px;
}

.fcias-version-title {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	margin-bottom: 2px;
}

.fcias-dup-empty {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 8px;
	margin: 4px 0 0 0;
	padding-inline-start: 8px;
	border-inline-start: 2px solid var(--color-border);
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.fcias-dup-empty-link {
	color: var(--color-main-text);
	text-decoration: underline;
}

.fcias-dup-empty-link:hover {
	color: var(--color-primary-element);
}

.fcias-copied-toast {
	position: fixed;
	bottom: 20px;
	inset-inline-start: 50%;
	transform: translateX(-50%);
	background: var(--color-success);
	color: var(--color-success-text);
	padding: 8px 16px;
	border-radius: 4px;
	font-size: 13px;
	z-index: 9999;
	pointer-events: none;
}
</style>
