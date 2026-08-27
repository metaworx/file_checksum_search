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

/**
 * Hash-generation rules (RulesController).
 *
 * One resource for both settings pages: what a caller may do follows from who
 * they are, not from which URL they used. The only thing a caller chooses is
 * the view — `?scope=own` (default) or `?scope=all` (admin) — which is what
 * keeps the personal page personal even for an administrator.
 */
export const API_RULES = {
	/** GET    /api/v1/rules[?scope=own|all] */
	list: `${APP_BASE}/api/v1/rules`,
	/** POST   /api/v1/rules */
	create: `${APP_BASE}/api/v1/rules`,
	/** PUT    /api/v1/rules/{id} — enabling/disabling is an update of `enabled` */
	update: `${APP_BASE}/api/v1/rules/{id}`,
	/** DELETE /api/v1/rules/{id} */
	remove: `${APP_BASE}/api/v1/rules/{id}`,
	/** PUT    /api/v1/rules/order */
	order: `${APP_BASE}/api/v1/rules/order`,
} as const

/** OCS settings endpoints (SettingsController) */
export const OCS_SETTINGS = {
	/** GET    /settings/status */
	getStatus: `${APP_BASE}/settings/status`,
	/** GET    /settings/admin-options */
	getAdminOptions: `${APP_BASE}/settings/admin-options`,
	/** POST   /settings/admin-options/save */
	saveAdminOptions: `${APP_BASE}/settings/admin-options/save`,
	/** POST   /settings/idle-banner/ack */
	ackIdleBanner: `${APP_BASE}/settings/idle-banner/ack`,
} as const

/** OCS admin endpoints (PageController) */
export const OCS_ADMIN = {
	/** GET    /admin/docs (admin only) */
	getDocs: `${APP_BASE}/admin/docs`,
	/** GET    /help (public, all authenticated users) */
	getHelp: `${APP_BASE}/help`,
} as const

/** Frontend routes (Files app) */
export const FRONTEND = {
	/** /apps/files/files/{fileid} */
	fileLink: '/apps/files/files/{fileid}',
} as const
