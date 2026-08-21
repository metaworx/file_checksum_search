<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Reusable NcSelect multiselect for algorithm selection.
 *
 * Initialised from a one-shot `initial` prop and reports changes through the
 * `onChange` callback so it can be mounted into vanilla DOM containers.
 */
import { ref, watch } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import type { AlgoOption } from '../algorithms'

const props = defineProps<{
	initial: string[]
	options: AlgoOption[]
	label?: string
	placeholder?: string
	onChange?: (value: string[]) => void
}>()

const selected = ref<AlgoOption[]>(
	props.options.filter((o) => props.initial.includes(o.id)),
)

watch(
	selected,
	(val) => {
		props.onChange?.(val.map((o) => o.id))
	},
	{ deep: true },
)
</script>

<template>
	<NcSelect
		v-model="selected"
		:multiple="true"
		:options="options"
		:input-label="label"
		:placeholder="placeholder"
		label-outside
		track-by="id" />
</template>
