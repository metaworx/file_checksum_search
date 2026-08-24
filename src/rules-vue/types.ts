/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Shared rule shapes for the admin and personal settings pages, superseding
 * the near-duplicate `DefinitionData`/`PersonalRule` interfaces that used to
 * live separately in settings-admin.ts/settings-personal.ts.
 */

export interface Rule {
	id: string | number
	enabled: boolean
	mode: string
	algos: string[]
	path: string
	userScope: string
	admin_enforced: boolean
	/** `include` (default), `ignore`, or `exclude` — what the rule does when it matches. */
	type?: string
	/** True for the single catch-all default rule, which is undeletable and always evaluates last. */
	pinned?: boolean
	/** Derived priority band 1–7 (lower evaluates first). Server-computed; never sent back. */
	band?: number
	/** 1-based position within the band, among the rules this caller can see. */
	position?: number
	/** Whether the current user may edit this rule. */
	canEdit?: boolean
}

export interface RuleDraft {
	id?: string | number
	/** `include` (default), `ignore`, or `exclude`. */
	type?: string
	mode: string
	algos: string[]
	path: string
	userScope: string
	admin_enforced: boolean
	/** The catch-all default; set only by the global-rule path. */
	pinned?: boolean
	enabled?: boolean
}
