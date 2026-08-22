<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Small "i" button that reveals a short help text in an NcPopover.
 *
 * Extracted from the sidebar's SectionHeader so the settings forms can put
 * the same affordance next to individual fields.
 */
import { onMounted, onUnmounted, ref } from 'vue'
import NcPopover from '@nextcloud/vue/components/NcPopover'

defineProps<{
	/** The help text itself. Nothing renders when this is empty. */
	text?: string
	/** Accessible name for the trigger, normally the label of the field it explains. */
	label: string
}>()

const infoIcon = '<svg fill="currentColor" width="20" height="20" viewBox="0 0 24 24" class="material-design-icon__svg"><path d="M11,9H13V7H11M12,20C7.59,20 4,16.41 4,12C4,7.59 7.59,4 12,4C16.41,4 20,7.59 20,12C20,16.41 16.41,20 12,20M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M11,17H13V11H11V17Z"/></svg>'

const shown = ref(false)

/**
 * Escape closes the popover and nothing else.
 *
 * Both key events have to be consumed, not just the keydown: NcModal closes
 * an enclosing dialog on **keyup**, so stopping the keydown alone still let
 * the dialog disappear behind the popover. Listening on `window` in the
 * capture phase puts this ahead of the document- and element-level handlers,
 * and stopImmediatePropagation is required rather than stopPropagation: the
 * latter still runs other listeners bound to the same node.
 */
let swallowKeyup = false

function onKeydown(event: KeyboardEvent): void {
	if (event.key !== 'Escape' || !shown.value) {
		return
	}
	shown.value = false
	swallowKeyup = true
	event.preventDefault()
	event.stopImmediatePropagation()
}

function onKeyup(event: KeyboardEvent): void {
	if (event.key !== 'Escape' || !swallowKeyup) {
		return
	}
	swallowKeyup = false
	event.preventDefault()
	event.stopImmediatePropagation()
}

onMounted(() => {
	window.addEventListener('keydown', onKeydown, true)
	window.addEventListener('keyup', onKeyup, true)
})

onUnmounted(() => {
	window.removeEventListener('keydown', onKeydown, true)
	window.removeEventListener('keyup', onKeyup, true)
})
</script>

<template>
	<NcPopover v-if="text" v-model:shown="shown" popover-role="dialog">
		<template #trigger>
			<button
				class="fcias-help-icon"
				type="button"
				:aria-label="`Help: ${ label }`"
				@click.prevent>
				<span v-html="infoIcon"></span>
			</button>
		</template>
		<p class="fcias-help-body">{{ text }}</p>
	</NcPopover>
</template>

<style scoped>
.fcias-help-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 34px;
	min-width: 34px;
	min-height: 34px;
	padding: 0;
	border: none;
	border-radius: 8px;
	background-color: transparent;
	color: var(--color-primary-element);
	cursor: pointer;
}

.fcias-help-icon:hover {
	background-color: var(--color-background-hover);
}

.fcias-help-body {
	margin: 0;
	/* The popover frame supplies no inner spacing of its own. */
	padding: 10px 14px;
	font-size: 13px;
	line-height: 1.4;
	max-width: 260px;
}
</style>
