<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Sidebar section header with an optional help popover.
 */
import HelpPopover from '../../components/HelpPopover.vue'

defineProps<{
	title: string
	help?: string
}>()

</script>

<template>
	<div class="section-header">
		<h4>{{ title }}</h4>
		<HelpPopover :text="help" :label="title" />
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

</style>
