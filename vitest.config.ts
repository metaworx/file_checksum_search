import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
	plugins: [vue()],
	// @nextcloud/vue reads two globals the consuming app's bundler is
	// expected to define; NcSettingsSelectGroup keys its session cache on
	// them. Free identifiers otherwise, and a ReferenceError in a mounted
	// hook.
	define: {
		appName: JSON.stringify('file_checksum_search'),
		appVersion: JSON.stringify('test'),
	},
	test: {
		globals: true,
		environment: 'happy-dom',
		// @nextcloud/vue 9 ships its components as ES modules that import
		// their stylesheets, ninety-odd `import './assets/….css'` across the
		// dist. Left external, they go through Node's own loader, which has no
		// idea what a .css file is; inlined, they go through Vite, which knows.
		// This is what lets a spec mount the real component rather than a
		// stand-in, and .eslintrc.cjs holds the specs to that.
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
