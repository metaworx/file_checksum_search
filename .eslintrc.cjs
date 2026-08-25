module.exports = {
	// Stop the eslintrc cascade here: nothing above this directory is part of
	// the project, so a stray config in a parent must not reach these files.
	root: true,
	// The Vue 3 + TypeScript preset. The bare '@nextcloud' shorthand resolves
	// to the plain-JS/Vue 2 one, whose Babel parser cannot read a
	// `<script setup lang="ts">` block at all — every SFC came back as a
	// parse error rather than being linted.
	extends: [
		'@nextcloud/eslint-config/vue3',
	],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		// Do not *demand* a tag per parameter: the TypeScript signature already
		// names and types them, so the tag can only restate it, and the autofix
		// inserts exactly that — a bare "@param rule" turning a one-line
		// docblock into three lines saying no more.
		'jsdoc/require-param': 'off',
		'jsdoc/require-returns': 'off',
		// But a tag someone *did* write must say something. This is the half
		// worth keeping: it catches the empty tag rather than mandating it.
		'jsdoc/require-param-description': 'warn',
		'vue/first-attribute-linebreak': 'off',
	},
}
