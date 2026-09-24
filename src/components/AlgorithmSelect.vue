<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The one algorithm picker. Every place a user chooses an algorithm — the
 * rule dialog, the Duplicates filter, the sidebar, a user's preference, the
 * administrator's allowlist — is this component, so they look the same,
 * announce the same, and cannot drift into five copies of the same wiring.
 *
 * Bound with `v-model` over algorithm **ids**: one string, or a list when
 * `multiple`. The mapping between ids and `AlgoOption`s is a writable
 * computed rather than a seeded ref: both the bound value and `algorithms`
 * arrive asynchronously on every page that uses this, and a snapshot taken
 * at setup time would filter an empty list and leave the widget blank for
 * good.
 *
 * `leading` prepends one pseudo-option — *All algorithms* on a filter,
 * Default (SHA1)* on a preference — whose id is what the caller stores for
 * "none in particular", usually the empty string.
 */
import { computed } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { type AlgoOption, toAlgoOptions } from '../algorithms'

const props = withDefaults(
	defineProps<{
		modelValue: string | string[]
		/** Algorithm ids to offer, in the order to offer them. */
		algorithms: readonly string[]
		multiple?: boolean
		leading?: AlgoOption | null
		label?: string
		placeholder?: string
		inputId?: string
		disabled?: boolean
	}>(),
	{
		multiple: false,
		leading: null,
		label: undefined,
		placeholder: undefined,
		inputId: undefined,
		disabled: false,
	},
)

const emit = defineEmits<{
	(e: 'update:modelValue', value: string | string[]): void
}>()

const options = computed<AlgoOption[]>(() => [
	...(props.leading ? [props.leading] : []),
	...toAlgoOptions([...props.algorithms]),
])

const selected = computed<AlgoOption | AlgoOption[] | null>({
	get: () => {
		if (props.multiple) {
			const ids = Array.isArray(props.modelValue) ? props.modelValue : []
			// The bound order, not the option order: for an allowlist the first
			// id is the default, and the widget must show it first.
			return ids
				.map((id) => options.value.find((o) => o.id === id))
				.filter((o): o is AlgoOption => o !== undefined)
		}
		const id = typeof props.modelValue === 'string' ? props.modelValue : ''
		return options.value.find((o) => o.id === id) ?? props.leading ?? null
	},
	set: (value) => {
		if (props.multiple) {
			emit('update:modelValue', (Array.isArray(value) ? value : []).map((o) => o.id))
			return
		}
		const one = Array.isArray(value) ? value[0] : value
		emit('update:modelValue', one?.id ?? props.leading?.id ?? '')
	},
})
</script>

<template>
	<NcSelect
		v-model="selected"
		:multiple="multiple"
		:options="options"
		:input-label="label"
		:input-id="inputId"
		:placeholder="placeholder"
		:disabled="disabled"
		:close-on-select="!multiple"
		label="label"
		label-outside />
</template>
