import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

export default createAppConfig(
	{
		duplicates: resolve(join('src', 'duplicates-vue', 'main.ts')),
		'duplicates-legacy': resolve(join('src', 'duplicates.ts')),
		sidebar: resolve(join('src', 'sidebar.ts')),
		'settings-admin': resolve(join('src', 'settings-admin.ts')),
		'settings-admin-docs': resolve(join('src', 'docs-vue', 'main.ts')),
		'settings-admin-permission': resolve(join('src', 'settings-admin-vue', 'main.ts')),
		'settings-personal': resolve(join('src', 'settings-personal.ts')),
	},
	{
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
	},
)
