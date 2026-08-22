<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Create/edit form, shared by the admin and personal settings pages and by
 * the admin page's global rule. Rendered inside an NcDialog; the parent
 * mounts this component only while the form should be open.
 *
 * Element ids are kept per-variant and identical to the previous
 * vanilla-JS markup (`#fcias-cron-*` / `#fcias-personal-*`) so the
 * existing Cypress e2e selectors (tests/e2e/rules.cy.js) keep working
 * unmodified.
 */
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import HelpPopover from '../components/HelpPopover.vue'
import { toAlgoOptions } from '../algorithms'
import AlgoMultiselect from '../settings-vue/AlgoMultiselect.vue'
import type { RuleDraft } from './types'

const props = defineProps<{
	rule: RuleDraft | null
	variant: 'admin' | 'personal'
	supportedAlgos: string[]
	/** Admin variant only: user ids offered in the User Scope select. */
	availableUsers?: string[]
	/**
	 * Renders Path and User Scope read-only. Used for the global rule, whose
	 * `**` / `all` reach is what makes it the global default.
	 */
	lockScope?: boolean
	/** Overrides the dialog title. */
	title?: string
}>()

const emit = defineEmits<{
	(e: 'save', draft: RuleDraft): void
	(e: 'cancel'): void
}>()

const ids = props.variant === 'admin'
	? {
		form: 'fcias-cron-form',
		path: 'fcias-cron-path',
		userscope: 'fcias-cron-userscope',
		algos: 'fcias-cron-algos',
		mode: 'fcias-cron-mode',
		adminEnforced: 'fcias-cron-admin-enforced',
		save: 'fcias-btn-save-definition',
		cancel: 'fcias-btn-cancel-definition',
	}
	: {
		form: 'fcias-personal-form',
		path: 'fcias-personal-path',
		userscope: '',
		algos: 'fcias-personal-algos',
		mode: 'fcias-personal-mode',
		adminEnforced: '',
		save: 'fcias-personal-save',
		cancel: 'fcias-personal-cancel',
	}

const draft = reactive<RuleDraft>({
	id: undefined,
	path: '/',
	mode: 'auto',
	algos: ['sha1'],
	userScope: 'all',
	admin_enforced: false,
})

function seed(rule: RuleDraft | null): void {
	draft.id = rule?.id
	draft.path = rule?.path || '/'
	draft.mode = rule?.mode || 'auto'
	draft.algos = rule?.algos?.length ? rule.algos.slice() : ['sha1']
	draft.userScope = rule?.userScope || 'all'
	draft.admin_enforced = rule?.admin_enforced === true
}

watch(() => props.rule, seed, { immediate: true })

const dialogName = computed(
	() => props.title ?? (props.rule?.id !== undefined ? 'Edit rule' : 'New rule'),
)

const HELP = {
	userScope: 'Which users this rule applies to. "All Users" covers everyone on this instance; '
		+ 'otherwise the rule only applies to the files of the single user you pick.',
	path: 'Glob pattern the file path must match. "**" matches every file; "/Documents/**" matches '
		+ 'everything below that folder. Rules are checked in order and the first match wins.',
	algos: 'Checksum algorithms computed for matching files. Each algorithm you add is indexed '
		+ 'separately, so more algorithms means more work per file.',
	mode: 'What happens to a file that already has a hash. "Auto" only recomputes a stale one, '
		+ '"Missing" also fills gaps, "Force" discards and recomputes everything, and "Lazy" '
		+ 'clears the hashes now and lets a later run recompute them.',
	adminEnforced: 'When set, users cannot override or disable this rule from their personal settings.',
}

function submit(): void {
	emit('save', { ...draft, algos: draft.algos.slice() })
}

const formEl = ref<HTMLElement | null>(null)

/**
 * Put the caret on the first field the admin actually edits.
 *
 * The dialog otherwise focuses the first focusable descendant, which is the
 * User Scope help button. Buttons are excluded here so focus lands on the
 * first real control — the User Scope select, the Path input, or, when the
 * global rule's fixed fields are plain text, the algorithm select.
 */
onMounted(async () => {
	await nextTick()
	requestAnimationFrame(() => {
		formEl.value
			?.querySelector<HTMLElement>('input:not([type="hidden"]), select, textarea')
			?.focus()
	})
})

/** The X button and a click outside resolve to the same cancel. */
function onOpenChange(open: boolean): void {
	if (!open) {
		emit('cancel')
	}
}

/**
 * Escape cancels the dialog.
 *
 * NcDialog does not emit `update:open` for Escape here, so the key is handled
 * directly. Two things get first refusal on the key:
 * An open select dropdown or help popover takes the key first: both mark it
 * consumed (HelpPopover stops it in the capture phase, vue-select calls
 * preventDefault), so only an unconsumed Escape reaches the dialog.
 */
function onEscape(event: KeyboardEvent): void {
	if (event.key === 'Escape' && !event.defaultPrevented) {
		emit('cancel')
	}
}

onMounted(() => document.addEventListener('keydown', onEscape))
onUnmounted(() => document.removeEventListener('keydown', onEscape))
</script>

<template>
	<NcDialog
		:open="true"
		:name="dialogName"
		:no-close="true"
		size="normal"
		@update:open="onOpenChange">
		<div :id="ids.form" ref="formEl" class="fcias-cron-form">
			<div v-if="variant === 'admin'" class="fcias-cron-form-row">
				<label :for="lockScope ? undefined : ids.userscope">User Scope</label>
				<span v-if="lockScope" :id="ids.userscope" class="fcias-cron-form-static">
					{{ draft.userScope === 'all' ? 'All Users' : draft.userScope }}
				</span>
				<select v-else :id="ids.userscope" v-model="draft.userScope">
					<option value="all">All Users</option>
					<option v-for="uid in availableUsers ?? []" :key="uid" :value="uid">
						{{ uid }}
					</option>
				</select>
				<HelpPopover :text="HELP.userScope" label="User Scope" />
			</div>

			<div class="fcias-cron-form-row">
				<label :for="lockScope ? undefined : ids.path">Path (glob)</label>
				<span
					v-if="lockScope"
					:id="ids.path"
					class="fcias-cron-form-static"
					:title="draft.path">
					{{ draft.path }}
				</span>
				<input
					v-else
					:id="ids.path"
					v-model="draft.path"
					type="text"
					placeholder="/"
					:title="draft.path">
				<HelpPopover :text="HELP.path" label="Path (glob)" />
			</div>

			<p v-if="lockScope" class="fcias-hint">
				The global rule always applies to every user and every path, so these two cannot be changed.
			</p>

			<div class="fcias-cron-form-row">
				<label>Algorithms</label>
				<div :id="ids.algos" class="fcias-algo-select">
					<AlgoMultiselect
						v-model="draft.algos"
						:options="toAlgoOptions(supportedAlgos)" />
				</div>
				<HelpPopover :text="HELP.algos" label="Algorithms" />
			</div>

			<div class="fcias-cron-form-row">
				<label :for="ids.mode">Mode</label>
				<select :id="ids.mode" v-model="draft.mode">
					<option value="auto">Auto (recalc existing only if stale)</option>
					<option value="missing">Missing (recalc existing + missing)</option>
					<option value="force">Force (delete all, recalc all)</option>
					<option value="lazy">Lazy (delete hashes, recalc later)</option>
				</select>
				<HelpPopover :text="HELP.mode" label="Mode" />
			</div>

			<div v-if="variant === 'admin'" class="fcias-cron-form-row">
				<label :for="ids.adminEnforced">Enforced</label>
				<span class="fcias-cron-form-fill">
					<NcCheckboxRadioSwitch
						:id="ids.adminEnforced"
						v-model="draft.admin_enforced"
						type="switch">
						Users may not edit this rule
					</NcCheckboxRadioSwitch>
				</span>
				<HelpPopover :text="HELP.adminEnforced" label="Enforced" />
			</div>

			<div class="fcias-cron-form-actions">
				<button :id="ids.save" class="fcias-btn" @click="submit">
					Save
				</button>
				<button :id="ids.cancel" class="fcias-btn" @click="emit('cancel')">
					Cancel
				</button>
			</div>
		</div>
	</NcDialog>
</template>
