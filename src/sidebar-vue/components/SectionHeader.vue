<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Sidebar section header with an optional NcPopover help popover.
 */
import NcPopover from '@nextcloud/vue/components/NcPopover'

defineProps<{
	title: string
	help?: string
}>()

const infoIcon = '<svg fill="currentColor" width="20" height="20" viewBox="0 0 24 24" class="material-design-icon__svg"><path d="M11,9H13V7H11M12,20C7.59,20 4,16.41 4,12C4,7.59 7.59,4 12,4C16.41,4 20,7.59 20,12C20,16.41 16.41,20 12,20M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M11,17H13V11H11V17Z"/></svg>'
</script>

<template>
	<div class="section-header">
		<h4>{{ title }}</h4>
		<NcPopover v-if="help" popover-role="dialog">
			<template #trigger>
				<button class="hint-icon" type="button" :aria-label="title">
					<span v-html="infoIcon"></span>
				</button>
			</template>
			<p class="hint-body">{{ help }}</p>
		</NcPopover>
	</div>
</template>

<style scoped>
.section-header {
	display: flex;
	align-items: center;
	gap: 4px;
	height: 38px;
	margin: 2px 0;
	padding-bottom: 4px;
}

.section-header h4 {
	font-size: 16px;
	font-weight: 600;
	line-height: 24px;
	margin: 0;
}

.hint-icon {
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

.hint-icon:hover {
	background-color: var(--color-background-hover);
}

.hint-body {
	margin: 0;
	font-size: 13px;
	line-height: 1.4;
	max-width: 260px;
}
</style>
