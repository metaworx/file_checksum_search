<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Read-only documentation viewer for the admin settings page.
 * Markdown files are rendered with NcRichText (GFM); all other
 * files are shown as raw text.
 */

import { computed, onMounted, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import NcRichText from '@nextcloud/vue/components/NcRichText'
import { OCS_ADMIN } from '../routes'

interface DocEntry {
	label?: string
	name?: string
	path?: string
	content?: string | null
}

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

onMounted(async () => {
	try {
		const response = await fetch(generateOcsUrl(OCS_ADMIN.getDocs))
		const data = (await response.json()) as { docs?: DocEntry[] }
		docs.value = data.docs ?? []
	} catch (e) {
		error.value = 'Failed to load documentation.'
	}
})
</script>

<template>
	<div class="fcias-docs-layout">
		<nav class="fcias-docs-nav" aria-label="Documentation files">
			<div
				v-for="(doc, index) in docs"
				:key="doc.path ?? doc.name"
				class="fcias-docs-item-row">
				<button
					type="button"
					class="fcias-docs-item"
					:class="{ 'is-active': index === activeIndex }"
					@click="activeIndex = index">
					{{ doc.label ?? doc.name }}
				</button>
				<button
					type="button"
					class="fcias-docs-download"
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
