<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * One rule row. Vue's `{{ }}` interpolation auto-escapes every field below —
 * no manual escapeHtml() calls are needed here, unlike the vanilla-JS
 * predecessor.
 */
import { computed } from 'vue'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import MdiIcon from '../components/MdiIcon.vue'
import { ICON_BIN, ICON_PAUSE, ICON_PENCIL, ICON_PLAY, ICON_REFRESH } from '../components/icons'
import { priorityLabel, selectorLabel } from './bands'
import type { GroupFolderOption, Rule } from './types'

const props = defineProps<{
	rule: Rule
	variant: 'admin' | 'personal'
	/** Whether this row's handle may be grabbed to start a drag. */
	canDrag?: boolean
	/** Set while this row is the one being dragged. */
	isDragging?: boolean
	/** Set while another row is being dragged over this one. */
	isDragOver?: boolean
	/** Set on the first row of each band, which carries the band label. */
	startsBand?: boolean
	/** What the groupfolders app calls itself, for the Scope column. */
	groupFoldersLabel?: string | null
	/** The group folders that exist, so the Scope column can name them. */
	groupFolders?: GroupFolderOption[]
	/** The rule names a provider that is gone, so it can never match. */
	providerMissing?: boolean
}>()

const emit = defineEmits<{
	(e: 'edit', rule: Rule): void
	(e: 'toggle', rule: Rule): void
	(e: 'apply', rule: Rule): void
	(e: 'delete', rule: Rule): void
	(e: 'rowDragstart', rule: Rule, event: DragEvent): void
	(e: 'rowDragover', rule: Rule, event: DragEvent): void
	(e: 'rowDragleave', rule: Rule): void
	(e: 'rowDrop', rule: Rule, event: DragEvent): void
	(e: 'rowDragend'): void
}>()

const scopeLabel = computed(() => selectorLabel(props.rule.selector, {
	groupFolderTerm: props.groupFoldersLabel,
	groupFolders: props.groupFolders,
}))

/** An ignore/exclude rule computes nothing, so it has no algorithms or mode. */
const computesHashes = computed(() => (props.rule.type ?? 'include') === 'include')

/**
 * Re-apply queues the rule's full sweep — only meaningful for a rule that
 * both computes something and is switched on: applying an ignore/exclude
 * marks nothing, and the server refuses a disabled rule at submission.
 */
const canReapply = computed(() => props.rule.enabled && computesHashes.value)
</script>

<template>
	<tr
		:data-id="String(rule.id)"
		:data-band="rule.band"
		:class="[
			`fcias-band-${rule.band ?? 0}`,
			{
				'fcias-band-start': startsBand,
				'fcias-dragging': isDragging,
				'fcias-drag-over': isDragOver,
				'fcias-rule-default': rule.isDefault,
			},
		]"
		@dragover="emit('rowDragover', rule, $event)"
		@dragleave="emit('rowDragleave', rule)"
		@drop="emit('rowDrop', rule, $event)">
		<td class="fcias-drag-handle-cell">
			<span
				v-if="canDrag"
				class="fcias-drag-handle"
				draggable="true"
				aria-hidden="true"
				title="Drag to reorder within this band"
				@dragstart="emit('rowDragstart', rule, $event)"
				@dragend="emit('rowDragend')">⠿</span>
		</td>
		<td class="fcias-priority-cell" :title="`Band ${rule.band}, position ${rule.position}`">
			{{ priorityLabel(rule) }}
		</td>
		<td :title="scopeLabel">
			{{ scopeLabel }}
			<span
				v-if="providerMissing"
				class="fcias-provider-missing"
				title="The app or storage this rule names is not available, so the rule can never match.">
				provider missing
			</span>
		</td>
		<td :title="rule.path || '/'">
			{{ rule.path || '/' }}
		</td>
		<td>
			<span :class="`fcias-rule-type fcias-rule-type-${rule.type ?? 'include'}`">
				{{ rule.type ?? 'include' }}
			</span>
		</td>
		<td :title="computesHashes ? (rule.algos || []).join(', ') : ''">
			<span v-if="computesHashes">{{ (rule.algos || []).join(', ') }}</span>
			<span v-else class="fcias-muted">—</span>
		</td>
		<td>
			<span v-if="computesHashes">{{ rule.mode || 'auto' }}</span>
			<span v-else class="fcias-muted">—</span>
		</td>
		<td>
			<span :class="rule.enabled ? 'fcias-compat-pass' : 'fcias-compat-fail'">
				{{ rule.enabled ? 'Enabled' : 'Disabled' }}
			</span>
		</td>
		<td>{{ rule.admin_enforced ? 'Yes' : 'No' }}</td>
		<td class="fcias-rules-actions">
			<span v-if="rule.canEdit" class="fcias-row-actions">
				<button
					class="fcias-icon-btn"
					data-action="edit"
					type="button"
					title="Edit rule"
					:aria-label="`Edit the rule on ${rule.path || '/'}`"
					@click="emit('edit', rule)">
					<MdiIcon :path="ICON_PENCIL" :size="16" />
				</button>
				<NcActions :aria-label="`More actions for the rule on ${rule.path || '/'}`">
					<NcActionButton data-action="edit" @click="emit('edit', rule)">
						<template #icon>
							<MdiIcon :path="ICON_PENCIL" />
						</template>
						Edit
					</NcActionButton>
					<NcActionButton data-action="toggle" @click="emit('toggle', rule)">
						<template #icon>
							<MdiIcon :path="rule.enabled ? ICON_PAUSE : ICON_PLAY" />
						</template>
						{{ rule.enabled ? 'Disable' : 'Enable' }}
					</NcActionButton>
					<NcActionButton v-if="canReapply" data-action="apply" @click="emit('apply', rule)">
						<template #icon>
							<MdiIcon :path="ICON_REFRESH" />
						</template>
						Re-apply
					</NcActionButton>
					<NcActionButton data-action="delete" @click="emit('delete', rule)">
						<template #icon>
							<MdiIcon :path="ICON_BIN" />
						</template>
						Delete
					</NcActionButton>
				</NcActions>
			</span>
			<span v-else class="fcias-muted">Read-only</span>
		</td>
	</tr>
</template>

<style scoped>
/* The pen sits beside the actions menu as a first-class shortcut: editing
   is the one action frequent enough to deserve a click, not a menu trip.
   The custom properties are supplied by the Nextcloud server's theme at
   runtime; the IDE cannot see them, hence the suppression and fallbacks. */
/* noinspection CssUnresolvedCustomProperty */
.fcias-icon-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: var(--default-clickable-area, 34px);
	height: var(--default-clickable-area, 34px);
	margin: 0;
	padding: 0;
	background: transparent;
	border: none;
	border-radius: var(--border-radius-element, 8px);
	color: var(--color-main-text, inherit);
	cursor: pointer;
	vertical-align: middle;
}

/* noinspection CssUnresolvedCustomProperty */
.fcias-icon-btn:hover,
.fcias-icon-btn:focus-visible {
	background-color: var(--color-background-hover, rgba(127, 127, 127, 0.15));
}

/* A rule whose provider is gone is inert by construction; the badge says
   so rather than leaving the reader to wonder why it never matches. */
/* noinspection CssUnresolvedCustomProperty */
.fcias-provider-missing {
	display: inline-block;
	margin-inline-start: 6px;
	padding: 1px 6px;
	border-radius: var(--border-radius, 3px);
	background-color: var(--color-warning, #f0ad4e);
	color: var(--color-primary-text, #fff);
	font-size: 0.8em;
	white-space: nowrap;
}

/* Pen and menu read as one control group, centred on a shared axis —
   the menu trigger brings its own height, the pen matches it. */
.fcias-row-actions {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	vertical-align: middle;
}

.fcias-rules-actions {
	white-space: nowrap;
}
</style>
