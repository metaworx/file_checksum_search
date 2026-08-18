<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Read-only documentation viewer for the admin settings page and the
 * duplicates page help tab. Markdown files are rendered with NcRichText
 * (GFM); all other files are shown as raw text.
 */

import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import NcRichText from '@nextcloud/vue/components/NcRichText'
import { OCS_ADMIN } from '../routes'

interface DocEntry {
	label?: string
	name?: string
	path?: string
	content?: string | null
}

const props = withDefaults(defineProps<{
	/** OCS endpoint (app-relative path) to fetch the docs list from. */
	endpoint?: string
	/** When set, only the doc whose path/name matches this value is shown. */
	only?: string
	/** When set, selected docs are reflected in the URL hash as #<prefix>/<name>. */
	hashPrefix?: string
}>(), {
	endpoint: OCS_ADMIN.getDocs,
})

const docs = ref<DocEntry[]>([])
const activeIndex = ref(0)
const error = ref('')

const activeDoc = computed<DocEntry | null>(() => docs.value[activeIndex.value] ?? null)

function isMarkdown(doc: DocEntry | null): boolean {
	const name = doc?.name ?? doc?.path ?? ''
	return name.toLowerCase().endsWith('.md')
}

function downloadDoc(doc: DocEntry): void {
	const content = doc.content ?? ''
	const name = doc.name ?? doc.path ?? 'document.txt'
	const filename = name.substring(name.lastIndexOf('/') + 1)
	const blob = new Blob([content], { type: 'text/plain;charset=utf-8' })
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = filename
	document.body.appendChild(link)
	link.click()
	document.body.removeChild(link)
	URL.revokeObjectURL(url)
}

function selectDoc(index: number): void {
	activeIndex.value = index
	if (props.hashPrefix && !props.only) {
		const name = docs.value[index]?.name ?? docs.value[index]?.path ?? ''
		if (name) {
			history.replaceState(null, '', `#${props.hashPrefix}/${encodeURIComponent(name)}`)
		}
	}
}

function restoreFromHash(): void {
	if (!props.hashPrefix || props.only) return
	const hash = window.location.hash.replace(/^#/, '')
	const prefix = `${props.hashPrefix}/`
	if (!hash.startsWith(prefix)) return
	const name = decodeURIComponent(hash.slice(prefix.length))
	const index = docs.value.findIndex((doc) => (doc.name ?? doc.path) === name)
	if (index !== -1) {
		activeIndex.value = index
	}
}

onMounted(async () => {
	window.addEventListener('hashchange', restoreFromHash)
	try {
		const response = await fetch(generateOcsUrl(props.endpoint))
		const data = (await response.json()) as { docs?: DocEntry[] }
		let list = data.docs ?? []
		if (props.only) {
			list = list.filter((doc) => (doc.path ?? doc.name) === props.only)
		}
		docs.value = list
		await nextTick()
		restoreFromHash()
	} catch (e) {
		error.value = 'Failed to load documentation.'
	}
})

onBeforeUnmount(() => {
	window.removeEventListener('hashchange', restoreFromHash)
})
</script>

<template>
	<div class="fcias-docs-layout">
		<nav v-if="!only" class="fcias-docs-nav" aria-label="Documentation files">
			<div
				v-for="(doc, index) in docs"
				:key="doc.path ?? doc.name"
				class="fcias-docs-item-row">
				<button
					type="button"
					class="fcias-docs-item button-vue"
					:class="{ 'is-active': index === activeIndex }"
					@click="selectDoc(index)">
					{{ doc.label ?? doc.name }}
				</button>
				<button
					type="button"
					class="fcias-docs-download button-vue"
					:title="doc.name ?? doc.label"
					:aria-label="`Download ${doc.name ?? doc.label}`"
					@click="downloadDoc(doc)">
					<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true">
						<path d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z" />
					</svg>
				</button>
			</div>
		</nav>

		<div class="fcias-docs-content">
			<p v-if="error" class="fcias-error">{{ error }}</p>
			<NcRichText
				v-else-if="activeDoc && isMarkdown(activeDoc)"
				:text="activeDoc.content ?? ''"
				:use-markdown="true"
				:use-extended-markdown="true"
				:autolink="true" />
			<pre v-else-if="activeDoc" class="fcias-docs-raw">{{ activeDoc.content }}</pre>
			<p v-else class="fcias-muted">No documentation available.</p>
		</div>
	</div>
</template>

<style>
/* Documentation viewer layout (shared by settings + duplicates pages) */

.fcias-docs-layout {
	display: flex;
	gap: 16px;
}

.fcias-docs-nav {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 200px;
}

.fcias-docs-item {
	background: transparent;
	border: none;
	border-radius: var(--border-radius, 3px);
	padding: 6px 10px;
	text-align: left;
	color: var(--color-main-text);
	cursor: pointer;
}

.fcias-docs-item:hover,
.fcias-docs-item:focus-visible {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.fcias-docs-item:active {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.fcias-docs-item.is-active,
.fcias-docs-item.is-active:hover,
.fcias-docs-item.is-active:focus-visible,
.fcias-docs-item.is-active:active {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.fcias-docs-item-row {
	display: flex;
	align-items: center;
	gap: 4px;
}

.fcias-docs-item-row .fcias-docs-item {
	flex: 1;
}

.fcias-docs-download {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: transparent;
	border: none;
	border-radius: var(--border-radius, 3px);
	padding: 5px;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.fcias-docs-download:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.fcias-docs-content {
	flex: 1;
	min-width: 0;
	overflow: auto;
	max-height: 70vh;
}

.fcias-docs-raw {
	margin: 0;
	padding: 12px;
	background: var(--color-background-dark, #f0f0f0);
	border-radius: var(--border-radius, 3px);
	font-family: monospace;
	font-size: 0.85em;
	white-space: pre-wrap;
	word-break: break-word;
}

.fcias-docs-content table {
	margin: 12px 0;
}

.fcias-docs-content th,
.fcias-docs-content td {
	padding: 6px 10px;
}
</style>
