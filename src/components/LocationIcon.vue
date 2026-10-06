<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * One glyph for where a file lives, or what a rule addresses.
 *
 * A location reads `home:alice//…`, `groupfolder:3//…`, `storage:<id>//…` or,
 * for a file the viewer holds through a share, `share:<id>//…`, and a
 * selector `home:<uid>`, `group:<gid>`, `home:*`, `groupfolder:<id>`,
 * `storage:<id>` or `*`. The prefix says which kind of place that is, and a
 * reader has to parse it; the glyph says it at a glance. The text stays as
 * it is — the glyph is never the only cue, and carries its kind as a title.
 */
import { computed } from 'vue'
import MdiIcon from './MdiIcon.vue'
import {
	ICON_ACCOUNT,
	ICON_ACCOUNT_GROUP,
	ICON_ASTERISK,
	ICON_FOLDER_ACCOUNT,
	ICON_HARDDISK,
	ICON_HOME,
	ICON_HOME_GROUP,
	ICON_SHARE,
} from './icons'
import { t } from '../l10n'

/**
 * The kinds a location or a selector can be. `home` and `user` are the
 * same place seen from two sides — a file's home, a rule's `home:<uid>`.
 * `own` is the viewer's own file: a house, not a person, because it is
 * not somebody's — it is theirs — and so that every row carries a glyph
 * and the labels line up.
 */
export type LocationKind = 'own' | 'home' | 'share' | 'user' | 'group' | 'homeAll' | 'groupfolder' | 'storage' | 'universal'

const props = withDefaults(
	defineProps<{
		kind: LocationKind
		/** Rendered size in px. */
		size?: number
	}>(),
	{ size: 16 },
)

const GLYPHS: Record<LocationKind, { path: string, title: string }> = {
	own: { path: ICON_HOME, title: t('file_checksum_search', 'Your own file') },
	home: { path: ICON_ACCOUNT, title: t('file_checksum_search', 'A home folder') },
	share: { path: ICON_SHARE, title: t('file_checksum_search', 'A share') },
	user: { path: ICON_ACCOUNT, title: t('file_checksum_search', 'One account\'s home folder') },
	group: { path: ICON_ACCOUNT_GROUP, title: t('file_checksum_search', 'The home folders of a group\'s members') },
	homeAll: { path: ICON_HOME_GROUP, title: t('file_checksum_search', 'All home folders') },
	groupfolder: { path: ICON_FOLDER_ACCOUNT, title: t('file_checksum_search', 'A team folder') },
	storage: { path: ICON_HARDDISK, title: t('file_checksum_search', 'A storage') },
	universal: { path: ICON_ASTERISK, title: t('file_checksum_search', 'Everything') },
}

const glyph = computed(() => GLYPHS[props.kind])
</script>

<template>
	<span
		class="fcias-location-icon"
		:data-kind="kind"
		role="img"
		:title="glyph.title"
		:aria-label="glyph.title">
		<MdiIcon :path="glyph.path" :size="size" />
	</span>
</template>

<style scoped>
/* Sits in running text before a label, on the text's own line. */
.fcias-location-icon {
	display: inline-flex;
	vertical-align: -0.2em;
	margin-inline-end: 4px;
	color: var(--color-text-maxcontrast);
}
</style>
