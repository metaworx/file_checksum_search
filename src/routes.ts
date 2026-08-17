/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Central route path constants for FCIAS frontend.
 * Keep in sync with PHP controller #[ApiRoute] / #[FrontpageRoute] attributes.
 */

const APP_BASE = '/apps/file_checksum_search'

/** OCS API v1 endpoints (PublicApiController) */
export const OCS_API_V1 = {
	/** GET    /api/v1/file/{fileId}/hashes */
	getHashes: `${APP_BASE}/api/v1/file/{fileId}/hashes`,
	/** GET    /api/v1/status */
	getStatus: `${APP_BASE}/api/v1/status`,
	/** GET    /api/v1/duplicates */
	findAllDuplicates: `${APP_BASE}/api/v1/duplicates`,
	/** GET    /api/v1/file/{fileId}/duplicates */
	findDuplicates: `${APP_BASE}/api/v1/file/{fileId}/duplicates`,
	/** GET    /api/v1/lookup */
	lookup: `${APP_BASE}/api/v1/lookup`,
	/** POST   /api/v1/file/{fileId}/recalc */
	recalcHash: `${APP_BASE}/api/v1/file/{fileId}/recalc`,
} as const

/** OCS settings endpoints (SettingsController) */
export const OCS_SETTINGS = {
	/** GET    /settings/status */
	getStatus: `${APP_BASE}/settings/status`,
	/** GET    /settings/cron/definitions */
	listRules: `${APP_BASE}/settings/cron/definitions`,
	/** POST   /settings/cron/save */
	saveRule: `${APP_BASE}/settings/cron/save`,
	/** POST   /settings/cron/delete */
	deleteRule: `${APP_BASE}/settings/cron/delete`,
	/** POST   /settings/cron/toggle */
	toggleRule: `${APP_BASE}/settings/cron/toggle`,
	/** GET    /settings/cron/snippet */
	getCrontabSnippet: `${APP_BASE}/settings/cron/snippet`,
	/** GET    /settings/admin-options */
	getAdminOptions: `${APP_BASE}/settings/admin-options`,
	/** POST   /settings/admin-options/save */
	saveAdminOptions: `${APP_BASE}/settings/admin-options/save`,
} as const

/** OCS personal settings endpoints (PersonalSettingsController) */
export const OCS_PERSONAL = {
	/** GET    /personal/rules */
	getRules: `${APP_BASE}/personal/rules`,
	/** POST   /personal/rules/save */
	saveRule: `${APP_BASE}/personal/rules/save`,
	/** POST   /personal/rules/delete */
	deleteRule: `${APP_BASE}/personal/rules/delete`,
	/** POST   /personal/rules/toggle */
	toggleRule: `${APP_BASE}/personal/rules/toggle`,
} as const

/** OCS admin endpoints (PageController) */
export const OCS_ADMIN = {
	/** GET    /admin/docs */
	getDocs: `${APP_BASE}/admin/docs`,
} as const

/** Frontend routes (Files app) */
export const FRONTEND = {
	/** /apps/files/files/{fileid} */
	fileLink: '/apps/files/files/{fileid}',
} as const
