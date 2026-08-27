<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * One inline MDI glyph. The project carries no icon library; paths live in
 * ./icons.ts and render as currentColor, so every context themes itself.
 */
defineProps<{
	/** An MDI 24×24 path, from ./icons.ts. */
	path: string
	/** Rendered size in px; MDI's grid is 24. */
	size?: number
}>()
</script>

<template>
	<!-- The wrapper mirrors vue-material-design-icons' markup: NcActions and
	     friends centre their slotted icons by styling .material-design-icon,
	     and a bare <svg> would sit on the text baseline instead. -->
	<span
		class="material-design-icon fcias-mdi-icon"
		aria-hidden="true">
		<svg
			class="material-design-icon__svg"
			viewBox="0 0 24 24"
			:width="size ?? 20"
			:height="size ?? 20"
			focusable="false">
			<path fill="currentColor" :d="path" />
		</svg>
	</span>
</template>

<style scoped>
/* Self-sufficient centring for hosts that do not style
   .material-design-icon themselves (like the plain pen button). */
.fcias-mdi-icon {
	display: flex;
	align-items: center;
	justify-content: center;
}
</style>
