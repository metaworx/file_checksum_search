<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Reusable NcSelect multiselect for algorithm selection.
 *
 * Bound with `v-model` over the selected algorithm ids. The mapping between
 * ids and `AlgoOption`s is a writable computed rather than a seeded ref: both
 * `modelValue` and `options` arrive asynchronously on the settings pages, and
 * a snapshot taken at setup time would filter an empty option list and leave
 * the widget permanently blank.
 */
import { computed } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import type { AlgoOption } from '../algorithms'

const props = defineProps<{
	modelValue: string[]
	options: AlgoOption[]
	label?: string
	placeholder?: string
}>()

const emit = defineEmits<{
	(e: 'update:modelValue', value: string[]): void
}>()

const selected = computed<AlgoOption[]>({
	get: () => props.options.filter((o) => props.modelValue.includes(o.id)),
	set: (value) => emit('update:modelValue', value.map((o) => o.id)),
})
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
