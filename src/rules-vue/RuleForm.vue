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
import { BAND_LABELS, bandOf, SCOPE_GROUP_PREFIX, scopeGroupId, scopeKind } from './bands'
import type { RuleDraft } from './types'

const props = defineProps<{
	rule: RuleDraft | null
	variant: 'admin' | 'personal'
	supportedAlgos: string[]
	/** Admin variant only: user ids offered in the User Scope select. */
	availableUsers?: string[]
	/** Admin variant only: group ids offered when the scope is a group. */
	availableGroups?: string[]
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
		scopeTarget: 'fcias-cron-scope-target',
		type: 'fcias-cron-type',
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
		scopeTarget: '',
		type: 'fcias-personal-type',
		algos: 'fcias-personal-algos',
		mode: 'fcias-personal-mode',
		adminEnforced: '',
		save: 'fcias-personal-save',
		cancel: 'fcias-personal-cancel',
	}

const draft = reactive<RuleDraft>({
	id: undefined,
	type: 'include',
	path: '/',
	mode: 'auto',
	algos: ['sha1'],
	userScope: 'all',
	admin_enforced: false,
})

/**
 * Scope is edited as two controls — kind, then which group or user — because
 * picking "Group" has to reveal the group picker. `userScope` stays the single
 * stored string the server expects; these two only compose it.
 */
const scopeChoice = ref<'global' | 'group' | 'user'>('global')
const scopeTarget = ref('')

function seed(rule: RuleDraft | null): void {
	draft.id = rule?.id
	draft.type = rule?.type || 'include'
	draft.path = rule?.path || '/'
	draft.mode = rule?.mode || 'auto'
	draft.algos = rule?.algos?.length ? rule.algos.slice() : ['sha1']
	draft.userScope = rule?.userScope || 'all'
	draft.admin_enforced = rule?.admin_enforced === true

	scopeChoice.value = scopeKind(draft.userScope)
	scopeTarget.value = scopeChoice.value === 'group'
		? (scopeGroupId(draft.userScope) ?? '')
		: (scopeChoice.value === 'user' ? draft.userScope : '')
}

watch(() => props.rule, seed, { immediate: true })

/** Recompose `userScope` whenever either half of the scope control changes. */
watch([scopeChoice, scopeTarget], ([kind, target]) => {
	if (kind === 'global') {
		draft.userScope = 'all'
	} else if (kind === 'group') {
		draft.userScope = target ? `${SCOPE_GROUP_PREFIX}${target}` : ''
	} else {
		draft.userScope = target
	}
})

/** Only an include rule computes anything, so only it has algorithms and a mode. */
const computesHashes = computed(() => draft.type === 'include')

/**
 * Which band the rule being described would land in.
 *
 * Band is derived, never chosen — showing it live is what makes that legible
 * while editing, instead of only after saving.
 */
const previewBand = computed(() => bandOf({
	userScope: draft.userScope,
	admin_enforced: draft.admin_enforced,
	pinned: props.rule?.pinned,
}))

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
	adminEnforced: 'When set, users cannot override or disable this rule from their personal settings. '
		+ 'Enforced rules are evaluated before every user rule, so they cannot be outrun.',
	type: 'What happens when this rule matches. "Include" computes the checksums below. '
		+ '"Ignore" stops automatic hashing but still lets someone recalculate a file by hand. '
		+ '"Exclude" blocks hashing entirely — use it for storage that must not be read, such as '
		+ 'a metered external mount.',
	band: 'Rules are evaluated in band order and the first match decides the file. A rule\'s band '
		+ 'follows from its scope and whether it is enforced — it is not chosen directly, and '
		+ 'reordering only moves a rule within its own band.',
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
			<div class="fcias-cron-form-row">
				<label :for="ids.type">Type</label>
				<select :id="ids.type" v-model="draft.type">
					<option value="include">Include (compute checksums)</option>
					<option value="ignore">Ignore (no automatic hashing; still allowed on request)</option>
					<option value="exclude">Exclude (never hash these files)</option>
				</select>
				<HelpPopover :text="HELP.type" label="Type" />
			</div>

			<div v-if="variant === 'admin'" class="fcias-cron-form-row">
				<label :for="lockScope ? undefined : ids.userscope">User Scope</label>
				<span v-if="lockScope" :id="ids.userscope" class="fcias-cron-form-static">
					{{ draft.userScope === 'all' ? 'All Users' : draft.userScope }}
				</span>
				<select v-else :id="ids.userscope" v-model="scopeChoice">
					<option value="global">All Users</option>
					<option value="group">A group</option>
					<option value="user">A single user</option>
				</select>
				<HelpPopover :text="HELP.userScope" label="User Scope" />
			</div>

			<div v-if="variant === 'admin' && !lockScope && scopeChoice === 'group'" class="fcias-cron-form-row">
				<label :for="ids.scopeTarget">Group</label>
				<select :id="ids.scopeTarget" v-model="scopeTarget">
					<option value="">— pick a group —</option>
					<option v-for="gid in availableGroups ?? []" :key="gid" :value="gid">
						{{ gid }}
					</option>
				</select>
			</div>

			<div v-if="variant === 'admin' && !lockScope && scopeChoice === 'user'" class="fcias-cron-form-row">
				<label :for="ids.scopeTarget">User</label>
				<select :id="ids.scopeTarget" v-model="scopeTarget">
					<option value="">— pick a user —</option>
					<option v-for="uid in availableUsers ?? []" :key="uid" :value="uid">
						{{ uid }}
					</option>
				</select>
			</div>

			<p v-if="variant === 'personal'" class="fcias-hint fcias-cron-form-static">
				This is your own rule. It applies only to your files and is evaluated after any rule
				an administrator has enforced.
			</p>

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

			<div v-if="computesHashes" class="fcias-cron-form-row">
				<label>Algorithms</label>
				<div :id="ids.algos" class="fcias-algo-select">
					<AlgoMultiselect
						v-model="draft.algos"
						:options="toAlgoOptions(supportedAlgos)" />
				</div>
				<HelpPopover :text="HELP.algos" label="Algorithms" />
			</div>

			<div v-if="computesHashes" class="fcias-cron-form-row">
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

			<p class="fcias-hint fcias-band-preview">
				Evaluates in band <strong>{{ previewBand }}</strong> — {{ BAND_LABELS[previewBand] }}
				<HelpPopover :text="HELP.band" label="Priority band" />
			</p>

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
