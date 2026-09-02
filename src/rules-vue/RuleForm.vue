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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcEllipsisedOption from '@nextcloud/vue/components/NcEllipsisedOption'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import HelpPopover from '../components/HelpPopover.vue'
import { toAlgoOptions } from '../algorithms'
import AlgoMultiselect from '../settings-vue/AlgoMultiselect.vue'
import { BAND_LABELS, bandOfKind, selectorKind, selectorTarget } from './bands'
import type { GroupFolderOption, RuleDraft } from './types'

const props = defineProps<{
	rule: RuleDraft | null
	variant: 'admin' | 'personal'
	supportedAlgos: string[]
	/** Admin variant only: user ids offered in the User Scope select. */
	availableUsers?: string[]
	/** Admin variant only: group ids offered when the scope is a group. */
	availableGroups?: string[]
	/** Whether the groupfolders app is installed and enabled. */
	groupFoldersAvailable?: boolean
	/** What the groupfolders app calls itself ("Team Folders"); null when absent. */
	groupFoldersLabel?: string | null
	/** Admin variant only: the group folders offered when the app is there. */
	availableGroupFolders?: GroupFolderOption[]
	/**
	 * Renders Path and User Scope read-only. Used for the global rule, whose
	 * `**` / `all` reach is what makes it the global default.
	 */
	lockScope?: boolean
	/** Overrides the dialog title. */
	title?: string
	/**
	 * A failed save's message, shown inside the dialog — the page behind it
	 * is the wrong place for an error about the form still on screen.
	 */
	errorMessage?: string | null
}>()

const emit = defineEmits<{
	(e: 'save', draft: RuleDraft): void
	(e: 'cancel'): void
}>()

const ids = props.variant === 'admin'
	? {
		form: 'fcias-rule-form',
		path: 'fcias-rule-path',
		userscope: 'fcias-cron-userscope',
		scopeTarget: 'fcias-cron-scope-target',
		type: 'fcias-rule-type',
		algos: 'fcias-rule-algos',
		mode: 'fcias-rule-mode',
		adminEnforced: 'fcias-rule-admin-enforced',
		save: 'fcias-btn-save-rule',
		cancel: 'fcias-btn-cancel-rule',
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
	selector: 'home:*',
	admin_enforced: false,
})

/**
 * The selector is edited as two controls — kind, then the target — because
 * picking "Group" has to reveal the group picker. `selector` stays the single
 * stored string the server expects; these two only compose it.
 */
const selectorChoice = ref<'homeAll' | 'group' | 'user' | 'groupfolder' | 'storage' | 'universal'>('homeAll')
const selectorTargetValue = ref('')

function seed(rule: RuleDraft | null): void {
	draft.id = rule?.id
	draft.type = rule?.type || 'include'
	draft.path = rule?.path || '/'
	draft.mode = rule?.mode || 'auto'
	draft.algos = rule?.algos?.length ? rule.algos.slice() : ['sha1']
	draft.selector = rule?.selector || 'home:*'
	draft.admin_enforced = rule?.admin_enforced === true

	selectorChoice.value = selectorKind(draft.selector)
	selectorTargetValue.value = selectorTarget(draft.selector) ?? ''
}

watch(() => props.rule, seed, { immediate: true })

/** Recompose `selector` whenever either half of the control changes. */
watch([selectorChoice, selectorTargetValue], ([kind, target]) => {
	switch (kind) {
	case 'universal':
		draft.selector = '*'
		break
	case 'homeAll':
		draft.selector = 'home:*'
		break
	case 'group':
		draft.selector = target ? `group:${target}` : ''
		break
	case 'groupfolder':
		draft.selector = target ? `groupfolder:${target}` : ''
		break
	case 'storage':
		draft.selector = target ? `storage:${target}` : ''
		break
	default:
		draft.selector = target ? `home:${target}` : ''
	}
})

/** Only an include rule computes anything, so only it has algorithms and a mode. */
const computesHashes = computed(() => draft.type === 'include')

/**
 * Which band the rule being described would land in.
 *
 * Band is derived, never chosen — showing it live is what makes that legible
 * while editing, instead of only after saving. Derived from the *kind*, not
 * the composed selector: the target never affects the band, and an as-yet
 * unpicked target must not make every kind preview as band 8.
 */
const previewBand = computed(() => bandOfKind(selectorChoice.value, draft.admin_enforced === true))

/** One option shape for every searchable picker below. */
interface PickerOption {
	id: string
	label: string
}

/**
 * Proxy between a searchable picker's option object and the plain target
 * string the selector stores. The getter tolerates a target the option list
 * does not contain (a deleted user, a removed group folder): the raw value
 * still shows instead of silently blanking an existing rule.
 */
function pickerProxy(options: () => PickerOption[]) {
	return computed<PickerOption | null>({
		get: () => {
			if (!selectorTargetValue.value) return null
			return options().find((option) => option.id === selectorTargetValue.value)
				?? { id: selectorTargetValue.value, label: selectorTargetValue.value }
		},
		set: (option) => {
			selectorTargetValue.value = option?.id ?? ''
		},
	})
}

const userOptions = computed<PickerOption[]>(
	() => (props.availableUsers ?? []).map((uid) => ({ id: uid, label: uid })),
)
const groupOptions = computed<PickerOption[]>(
	() => (props.availableGroups ?? []).map((gid) => ({ id: gid, label: gid })),
)
const groupFolderOptions = computed<PickerOption[]>(
	() => (props.availableGroupFolders ?? []).map((folder) => ({
		id: String(folder.id),
		label: `${folder.name} (#${folder.id})`,
	})),
)

/**
 * The groupfolders app's own name for itself — "Team Folders" on current
 * releases — so this dialog says what the rest of the settings UI says.
 * With the app gone there is nobody to ask, and the slug names the missing
 * provider honestly.
 */
const groupFolderTerm = computed(
	() => props.groupFoldersLabel ?? (props.groupFoldersAvailable ? 'Team folders' : 'app:groupfolders'),
)

const selectedUser = pickerProxy(() => userOptions.value)
const selectedGroup = pickerProxy(() => groupOptions.value)
const selectedGroupFolder = pickerProxy(() => groupFolderOptions.value)

const dialogName = computed(
	() => props.title ?? (props.rule?.id !== undefined ? 'Edit rule' : 'New rule'),
)

const HELP = {
	selector: 'Which slice of the file universe this rule addresses: all home folders, one '
		+ 'group\'s members, a single user, one group folder, one storage by its raw id, or '
		+ 'everything — every storage there is, external mounts and group folders included.',
	path: 'Glob pattern the file path must match. "**" matches every file; "/Documents/**" matches '
		+ 'everything below that folder. Rules are checked in order and the first match wins.',
	algos: 'Checksum algorithms computed for matching files. Each algorithm you add is indexed '
		+ 'separately, so more algorithms means more work per file.',
	mode: 'What happens to a file that already has a hash. "Auto" only recomputes an outdated one, '
		+ '"Missing" also fills gaps, "Force" discards and recomputes everything, and "Lazy" '
		+ 'clears the hashes now and lets a later run recompute them.',
	adminEnforced: 'When set, users cannot override or disable this rule from their personal settings. '
		+ 'Enforced rules are evaluated before every user rule, so they cannot be outrun.',
	type: 'What happens when this rule matches. "Include" computes the checksums below. '
		+ '"Ignore" stops automatic hashing but still lets someone recalculate a file by hand. '
		+ '"Exclude" blocks hashing entirely — use it for storage that must not be read, such as '
		+ 'a metered external mount.',
	user: 'The one user whose home folder this rule addresses.',
	group: 'The rule addresses the home folder of every member of this group.',
	groupfolder: 'The team folder this rule addresses. Every member sees the same files, and the '
		+ 'rule follows the folder — not whoever happens to look at it.',
	storage: 'The raw id from the storages table, matched exactly — whatever kind of storage it '
		+ 'names. Use this for external mounts, or anything the other choices cannot say.',
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
		<div :id="ids.form" ref="formEl" class="fcias-rule-form">
			<div class="fcias-rule-form-row">
				<label :for="ids.type">Type</label>
				<select :id="ids.type" v-model="draft.type">
					<option value="include">
						Include (compute checksums)
					</option>
					<option value="ignore">
						Ignore (no automatic hashing; still allowed on request)
					</option>
					<option value="exclude">
						Exclude (never hash these files)
					</option>
				</select>
				<HelpPopover :text="HELP.type" label="Type" />
			</div>

			<div v-if="variant === 'admin'" class="fcias-rule-form-row">
				<label :for="lockScope ? undefined : ids.userscope">Applies to</label>
				<span v-if="lockScope" :id="ids.userscope" class="fcias-rule-form-static">
					{{ draft.selector }}
				</span>
				<select
					v-else
					:id="ids.userscope"
					v-model="selectorChoice"
					@change="selectorTargetValue = ''">
					<option value="homeAll">
						All home folders
					</option>
					<option value="group">
						A group
					</option>
					<option value="user">
						A single user
					</option>
					<option v-if="groupFoldersAvailable" value="groupfolder">
						{{ groupFolderTerm }} — one folder
					</option>
					<option value="storage">
						A storage (raw id)
					</option>
					<option value="universal">
						Everything — every storage
					</option>
				</select>
				<HelpPopover :text="HELP.selector" label="Applies to" />
			</div>

			<div v-if="variant === 'admin' && !lockScope && selectorChoice === 'group'" class="fcias-rule-form-row">
				<label :for="ids.scopeTarget">Group</label>
				<div class="fcias-rules-dialog-select">
					<NcSelect
						v-model="selectedGroup"
						:input-id="ids.scopeTarget"
						:options="groupOptions"
						placeholder="Search groups…"
						track-by="id">
						<template #selected-option="option">
							<!--
								The picked option's id, which NcSelect otherwise
								keeps in reactive state and nowhere in the page:
								its default #selected-option slot renders the
								label alone and drops the rest of the option. A
								caller cannot read back what is selected, only
								what it is called — and what it is called is a
								composed label, so a test asserting on it is
								really asserting how we happen to phrase things.

								Overriding the slot is the supported way to say
								otherwise. NcEllipsisedOption is what the default
								renders, so this changes nothing anyone sees.
							-->
							<NcEllipsisedOption
								:name="option.label"
								:data-selected-id="option.id" />
						</template>
					</NcSelect>
				</div>
				<HelpPopover :text="HELP.group" label="Group" />
			</div>

			<div v-if="variant === 'admin' && !lockScope && selectorChoice === 'user'" class="fcias-rule-form-row">
				<label :for="ids.scopeTarget">User</label>
				<div class="fcias-rules-dialog-select">
					<NcSelect
						v-model="selectedUser"
						:input-id="ids.scopeTarget"
						:options="userOptions"
						placeholder="Search users…"
						track-by="id">
						<template #selected-option="option">
							<NcEllipsisedOption
								:name="option.label"
								:data-selected-id="option.id" />
						</template>
					</NcSelect>
				</div>
				<HelpPopover :text="HELP.user" label="User" />
			</div>

			<div v-if="variant === 'admin' && !lockScope && selectorChoice === 'groupfolder'" class="fcias-rule-form-row">
				<label :for="ids.scopeTarget">{{ groupFolderTerm }}</label>
				<div class="fcias-rules-dialog-select">
					<NcSelect
						v-model="selectedGroupFolder"
						:input-id="ids.scopeTarget"
						:options="groupFolderOptions"
						:placeholder="`Search ${groupFolderTerm}…`"
						track-by="id">
						<template #selected-option="option">
							<NcEllipsisedOption
								:name="option.label"
								:data-selected-id="option.id" />
						</template>
					</NcSelect>
				</div>
				<HelpPopover :text="HELP.groupfolder" :label="groupFolderTerm" />
			</div>

			<div
				v-if="variant === 'admin' && !lockScope && selectorChoice === 'storage'"
				class="fcias-rule-form-row">
				<label :for="ids.scopeTarget">Storage id</label>
				<input
					:id="ids.scopeTarget"
					v-model="selectorTargetValue"
					type="text"
					placeholder="local::/path/ or smb::…">
				<HelpPopover :text="HELP.storage" label="Storage id" />
			</div>

			<p v-if="variant === 'personal'" class="fcias-hint fcias-rule-form-static">
				This is your own rule. It applies only to your files and is evaluated after any rule
				an administrator has enforced.
			</p>

			<div class="fcias-rule-form-row">
				<label :for="lockScope ? undefined : ids.path">Path (glob)</label>
				<span
					v-if="lockScope"
					:id="ids.path"
					class="fcias-rule-form-static"
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

			<div v-if="computesHashes" class="fcias-rule-form-row">
				<label>Algorithms</label>
				<div :id="ids.algos" class="fcias-rules-dialog-select">
					<AlgoMultiselect
						v-model="draft.algos"
						:options="toAlgoOptions(supportedAlgos)" />
				</div>
				<HelpPopover :text="HELP.algos" label="Algorithms" />
			</div>

			<div v-if="computesHashes" class="fcias-rule-form-row">
				<label :for="ids.mode">Mode</label>
				<select :id="ids.mode" v-model="draft.mode">
					<option value="auto">
						Auto (recalc existing only if outdated)
					</option>
					<option value="missing">
						Missing (recalc existing + missing)
					</option>
					<option value="force">
						Force (delete all, recalc all)
					</option>
					<option value="lazy">
						Lazy (delete hashes, recalc later)
					</option>
				</select>
				<HelpPopover :text="HELP.mode" label="Mode" />
			</div>

			<div v-if="variant === 'admin'" class="fcias-rule-form-row">
				<label :for="ids.adminEnforced">Enforced</label>
				<span class="fcias-rule-form-fill">
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
				<span class="fcias-band-preview-text">
					Evaluates in band <strong>{{ previewBand }}</strong> — {{ BAND_LABELS[previewBand] }}
				</span>
				<HelpPopover :text="HELP.band" label="Priority band" />
			</p>

			<NcNoteCard
				v-if="errorMessage"
				type="error"
				class="fcias-form-error">
				{{ errorMessage }}
			</NcNoteCard>

			<div class="fcias-rule-form-actions">
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

<style scoped>
/* The target pickers reuse .fcias-rules-dialog-select from the page stylesheet —
   the two-part release of NcSelect's 260px min-width pin and its
   content-sized inner toggle — so they fill the row exactly like the
   algorithm select above them. */

.fcias-band-preview {
	display: flex;
	align-items: center;
	gap: 4px;
}

.fcias-band-preview-text {
	flex: 1;
}
</style>
