import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
	plugins: [vue()],
	test: {
		globals: true,
		environment: 'happy-dom',
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
