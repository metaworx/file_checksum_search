<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Button to trigger hash verification of all displayed groups.
 *
 * Keeps the `verify-btn` class: the e2e suite addresses it, and a class
 * that names what the button does is worth more than one that names the
 * component it happens to be built from.
 */
import NcButton from '@nextcloud/vue/components/NcButton'

defineProps<{
	verifying: boolean
	hasVerified: boolean
}>()

const emit = defineEmits<{
	verify: []
}>()
</script>

<template>
	<NcButton
		class="verify-btn"
		:disabled="verifying"
		:title="hasVerified ? 'All groups verified' : 'Recalculate all hashes from file content'"
		@click="emit('verify')">
		{{ verifying ? 'Verifying …' : hasVerified ? '✓ Verified' : 'Verify hashes' }}
	</NcButton>
</template>
