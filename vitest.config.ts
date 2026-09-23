import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
	plugins: [vue()],
	test: {
		globals: true,
		environment: 'happy-dom',
		// @nextcloud/vue 9 ships its components as ES modules that import
		// their stylesheets, ninety-odd `import './assets/….css'` across the
		// dist. Left external, they go through Node's own loader, which has no
		// idea what a .css file is — every spec in this suite mocked every
		// Nextcloud component to stay clear of that, and tested a stand-in.
		// Inlined, they go through Vite, which knows. Measured on the whole
		// suite: no difference in duration.
		server: { deps: { inline: [/@nextcloud\/vue/] } },
		// What the runner's DOM must not do for those components; see the file.
		setupFiles: ['./vitest.setup.ts'],
		include: ['src/**/*.spec.ts'],
		exclude: [
			'**/node_modules/**',
			'tests/**',
			'vendor/**',
			'app.nc_checksum/**',
			'nextcloud-v33/**',
			'nextcloud-v34/**',
		],
	},
})
