<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Root component for the duplicates page.
 */

import { ref, computed, watch, onMounted } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import DuplicateGroup from './components/DuplicateGroup.vue'
import VerifyButton from './components/VerifyButton.vue'
import { useDuplicates } from './composables/useDuplicates'
import type { DuplicateGroup as GroupType } from './composables/useDuplicates'

const {
	algo,
	minCount,
	limit,
	offset,
	groups,
	loading,
	hasMore,
	verifying,
	error,
	load,
	verifyGroups,
	fileUrl,
	resetOffset,
	prevPage,
	nextPage,
} = useDuplicates()

const algoOptions = [
	{ value: '', label: 'All algorithms' },
	{ value: 'sha1', label: 'SHA-1' },
	{ value: 'md5', label: 'MD5' },
	{ value: 'sha256', label: 'SHA-256' },
	{ value: 'sha512', label: 'SHA-512' },
	{ value: 'sha3-256', label: 'SHA3-256' },
	{ value: 'sha3-512', label: 'SHA3-512' },
	{ value: 'crc32', label: 'CRC32' },
]

const verifiedOnly = ref(false)

const filteredGroups = computed<GroupType[]>(() => {
	if (!verifiedOnly.value) return groups.value
	return groups.value.filter((g) => (g.mismatch_count ?? 0) === 0)
})

const hasVerified = computed(() =>
	groups.value.every((g) => g.match_count !== undefined && g.mismatch_count !== undefined),
)

watch([algo, minCount, limit], () => {
	resetOffset()
	load()
})

watch(offset, () => {
	load()
})

function refresh(): void {
	resetOffset()
	load()
}

async function onVerify(): Promise<void> {
	await verifyGroups(groups.value)
}

onMounted(() => {
	load()
})
</script>

<template>
	<NcContent app-name="file_checksum_search">
		<NcAppContent :class="$style.content">
			<div class="db-wrap">
				<div class="db-controls">
					<select v-model="algo" class="db-select">
						<option v-for="opt in algoOptions" :key="opt.value" :value="opt.value">
							{{ opt.label }}
						</option>
					</select>
					<label class="db-label">
						Min:
						<input v-model.number="minCount" type="number" min="2" max="100" class="db-input-narrow">
					</label>
					<label class="db-label">
						Limit:
						<input v-model.number="limit" type="number" min="1" max="500" class="db-input-narrow">
					</label>
					<button class="db-btn primary" @click="refresh">Refresh</button>
					<VerifyButton :verifying="verifying" :has-verified="hasVerified" @verify="onVerify" />
					<label class="db-label" title="Show only groups where all files were confirmed matching">
						<input v-model="verifiedOnly" type="checkbox"> Only matching
					</label>
				</div>

				<div class="db-scroll">
					<div v-if="loading" class="db-loading">Searching …</div>
					<div v-else-if="error" class="db-error">{{ error }}</div>
					<div v-else-if="filteredGroups.length === 0" class="db-empty">
						{{ groups.length === 0 ? 'No duplicate files found.' : 'No matching duplicate files found.' }}
					</div>
					<DuplicateGroup
						v-for="(group, idx) in filteredGroups"
						:key="`${group.algo}-${group.hash_value}-${idx}`"
						:group="group"
						:file-url="fileUrl"
					/>
				</div>

				<div class="db-pagination">
					<button v-if="offset > 0" class="db-btn" @click="prevPage">← Previous</button>
					<button v-if="hasMore" class="db-btn" @click="nextPage">Next →</button>
				</div>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style module>
.content {
	display: flex;
	justify-content: center;
	margin: 16px;
}
</style>

<style scoped>
.db-wrap {
	max-width: 960px;
	margin: 0 auto;
	padding: 16px;
	width: 100%;
}
.db-controls {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
	flex-wrap: wrap;
	align-items: center;
}
.db-select,
.db-input-narrow,
.db-btn {
	padding: 6px 10px;
	font-size: 13px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.db-btn {
	cursor: pointer;
}
.db-btn:disabled {
	opacity: 0.5;
	cursor: default;
}
.db-btn.primary {
	background: var(--color-primary-element);
	color: #fff;
	border-color: var(--color-primary-element);
}
.db-input-narrow {
	width: 55px;
}
.db-label {
	font-size: 13px;
}
.db-scroll {
	max-height: calc(100vh - 180px);
	overflow-y: auto;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}
.db-pagination {
	display: flex;
	gap: 8px;
	justify-content: center;
	margin-top: 16px;
}
.db-empty,
.db-loading {
	text-align: center;
	padding: 32px;
	color: var(--color-text-maxcontrast);
}
.db-error {
	color: var(--color-error);
	text-align: center;
	padding: 16px;
}
</style>
