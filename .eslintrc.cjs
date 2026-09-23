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
	overrides: [
		{
			// A spec mounts the real Nextcloud components. The runner inlines
			// the library and defines what it needs (vitest.config.ts,
			// vitest.setup.ts), and src/test-utils/ drives the controls that
			// open menus; a stand-in written for a spec tests the author's idea
			// of the control, which is what forty-eight of them once did. A mock
			// of the server or of the page — @nextcloud/router, @nextcloud/axios,
			// @nextcloud/l10n — stays fair game.
			files: ['src/**/*.spec.ts'],
			rules: {
				'no-restricted-syntax': [
					'error',
					{
						selector: 'CallExpression[callee.object.name="vi"][callee.property.name="mock"] > Literal[value=/^@nextcloud\\u002fvue\\u002f/]',
						message: 'Mount the real Nextcloud component; the runner can load it. See vitest.setup.ts and src/test-utils/.',
					},
				],
			},
		},
	],
}
