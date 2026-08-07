import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

export default createAppConfig(
	{
		duplicates: resolve(join('src', 'duplicates-vue', 'main.ts')),
		'duplicates-legacy': resolve(join('src', 'duplicates.ts')),
		sidebar: resolve(join('src', 'sidebar.ts')),
		'settings-admin': resolve(join('src', 'settings-admin.ts')),
	},
	{
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
	},
)
