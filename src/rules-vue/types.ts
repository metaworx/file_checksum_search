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
	/** Personal variant only: whether the current user may edit this rule. Always editable for the admin variant. */
	canEdit?: boolean
}

export interface RuleDraft {
	id?: string | number
	mode: string
	algos: string[]
	path: string
	userScope: string
	admin_enforced: boolean
}
