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
	selector: string
	admin_enforced: boolean
	/** `include` (default), `ignore`, or `exclude` — what the rule does when it matches. */
	type?: string
	/**
	 * True when the rule's path is a bare catch-all (`**`, `/`, or empty), which puts it in the
	 * trailing defaults partition of its own segment. Several rules are default-shaped — the app
	 * ships two — and any of them can be deleted; a repair step recreates the shipped pair, disabled.
	 * Derived from the path, never stored.
	 */
	isDefault?: boolean
	/** Derived priority band, 1–8 (lower evaluates first). Server-computed; never sent back. */
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
	selector: string
	admin_enforced: boolean
	/** Read-only here: the server derives it from `path` and ignores whatever a draft claims. */
	isDefault?: boolean
	enabled?: boolean
}

/** One group folder the groupfolders app knows, offered in the selector picker. */
export interface GroupFolderOption {
	id: number
	name: string
}
