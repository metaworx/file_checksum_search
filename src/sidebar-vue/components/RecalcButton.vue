<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Recalculate button with inline loading spinner and error state.
 *
 * When `algo` is set, the spinner/error state tracks that specific algorithm;
 * when `algo` is null the button reflects any in-flight/errored recalc.
 */
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

const props = withDefaults(
	defineProps<{
		algo?: string | null
		label: string
		recalculating: string | null
		recalcError: string | null
	}>(),
	{
		algo: null,
	},
)

const emit = defineEmits<{
	(e: 'recalc', algo: string | null): void
}>()

const isRecalculating = computed(() =>
	props.algo === null ? props.recalculating !== null : props.recalculating === props.algo,
)

const hasError = computed(() =>
	props.algo === null ? props.recalcError !== null : props.recalcError === props.algo,
)
</script>

<template>
	<button
		class="fcias-recalc-btn"
		:data-algo="algo ?? undefined"
		:disabled="recalculating !== null"
		@click="emit('recalc', algo)">
		<NcLoadingIcon v-if="isRecalculating" :size="14" />
		<span v-else>{{ hasError ? t('file_checksum_search', 'Error') : label }}</span>
	</button>
</template>

<style scoped>
.fcias-recalc-btn {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin: 0;
	padding: 4px 10px;
	font-size: 12px;
	cursor: pointer;
	min-width: fit-content;
	flex-shrink: 0;
	white-space: nowrap;
}

.fcias-recalc-btn:disabled {
	opacity: 0.5;
	cursor: default;
}
</style>
