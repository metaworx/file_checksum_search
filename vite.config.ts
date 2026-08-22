import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

export default createAppConfig(
	{
		duplicates: resolve(join('src', 'duplicates-vue', 'main.ts')),
		sidebar: resolve(join('src', 'sidebar.ts')),
		'settings-admin': resolve(join('src', 'settings-admin-vue', 'main.ts')),
		'settings-personal': resolve(join('src', 'settings-personal-vue', 'main.ts')),
	},
	{
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
	},
)
