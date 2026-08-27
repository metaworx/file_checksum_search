/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Composable for the personal settings page's rule list.
 *
 * Rule CRUD lives in the shared useRules composable — this page is simply the
 * `own` view of it. Requesting that view is what keeps the page personal even
 * for an administrator: the server lists only rules concerning their own files
 * and marks only their own as editable, so the capability an administrator has
 * is not exercised from a page that is not about it.
 */

import { useRules } from '../../rules-vue/composables/useRules'

export function usePersonalSettings() {
	const rules = useRules('own')

	return {
		rules: rules.rules,
		canEditAny: rules.canCreate,
		supportedAlgos: rules.supportedAlgos,
		modes: rules.modes,
		types: rules.types,
		loading: rules.loading,
		error: rules.error,

		loadRules: rules.load,
		saveRule: rules.saveRule,
		deleteRule: rules.deleteRule,
		toggleRule: rules.toggleRule,
		applyRule: rules.applyRule,
		reorderSegment: rules.reorderSegment,
	}
}
