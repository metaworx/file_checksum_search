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
	/** GET    /api/v1/sudo/duplicates — every account; password confirmation, sudoers only */
	sudoFindAllDuplicates: `${APP_BASE}/api/v1/sudo/duplicates`,
	/** GET    /api/v1/sudo/selectable — the groups and accounts the caller may name */
	sudoSelectable: `${APP_BASE}/api/v1/sudo/selectable`,
	/** GET    /api/v1/file/{fileId}/duplicates */
	findDuplicates: `${APP_BASE}/api/v1/file/{fileId}/duplicates`,
	/** GET    /api/v1/lookup */
	lookup: `${APP_BASE}/api/v1/lookup`,
	/** POST   /api/v1/file/{fileId}/recalc */
	recalcHash: `${APP_BASE}/api/v1/file/{fileId}/recalc`,
	getAlgorithms: `${APP_BASE}/api/v1/algorithms`,
	/** GET/PUT /api/v1/preferences/{key} — the caller's own; first key `preferred_algorithm` */
	preference: `${APP_BASE}/api/v1/preferences/{key}`,
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
	/** POST   /api/v1/rules/{id}/apply */
	apply: `${APP_BASE}/api/v1/rules/{id}/apply`,
} as const

/** OCS settings endpoints (SettingsController) */
export const OCS_SETTINGS = {
	/** GET    /settings/status */
	getStatus: `${APP_BASE}/settings/status`,
	/** GET    /settings/global — every instance-wide option, one resource */
	getGlobal: `${APP_BASE}/settings/global`,
	/** PUT    /settings/global — only the fields sent are changed */
	saveGlobal: `${APP_BASE}/settings/global`,
	/** POST   /settings/idle-banner/ack */
	ackIdleBanner: `${APP_BASE}/settings/idle-banner/ack`,
	/** GET    /settings/personal/sudo-tokens — the caller's app passwords with their grants */
	mySudoTokens: `${APP_BASE}/settings/personal/sudo-tokens`,
	/** PUT    /settings/personal/sudo-tokens/{id} — {granted: bool}; granting asks for the password */
	mySudoToken: `${APP_BASE}/settings/personal/sudo-tokens/{id}`,
	/** GET    /settings/sudo-tokens — every grant on the instance (admin) */
	allSudoTokens: `${APP_BASE}/settings/sudo-tokens`,
	/** DELETE /settings/sudo-tokens/{uid}/{id} — revoke anybody's grant (admin) */
	revokeSudoToken: `${APP_BASE}/settings/sudo-tokens/{uid}/{id}`,
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
