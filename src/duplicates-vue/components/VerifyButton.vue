<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Button to trigger hash verification of all displayed groups.
 */

defineProps<{
	verifying: boolean
	hasVerified: boolean
}>()

const emit = defineEmits<{
	verify: []
}>()
</script>

<template>
	<button
		class="verify-btn"
		:disabled="verifying"
		:title="hasVerified ? 'All groups verified' : 'Recalculate all hashes from file content'"
		@click="emit('verify')"
	>
		{{ verifying ? 'Verifying …' : hasVerified ? '\u2713 Verified' : 'Verify hashes' }}
	</button>
</template>

<style scoped>
.verify-btn {
	padding: 6px 10px;
	font-size: 13px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}
.verify-btn:disabled {
	opacity: 0.5;
	cursor: default;
}
</style>
