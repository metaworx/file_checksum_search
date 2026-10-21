<script setup lang="ts">
/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * One duplicates listing: the filters, the groups, the pages.
 *
 * Written once and rendered twice — the Duplicates tab passes no scope and
 * gets one's own files; the Others tab passes the accounts the
 * picker named. Everything below the picker is identical between them, so
 * it lives here rather than being copied per tab, and each instance owns
 * its own {@see useDuplicates} state so switching tabs does not reset the
 * other's filters.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AlgorithmSelect from '../../components/AlgorithmSelect.vue'
import HelpPopover from '../../components/HelpPopover.vue'
import DeleteDialog from './DeleteDialog.vue'
import PageDeleteDialog from './PageDeleteDialog.vue'
import DuplicateGroup from './DuplicateGroup.vue'
import { useDuplicates, type DuplicateScope, type DuplicateGroup as GroupType } from '../composables/useDuplicates'
import {
	deleteGroups,
	deleteRows,
	restoreRow,
	restoreRows,
	type GroupKey,
	type GroupResults,
	type GroupRows,
	type RestoreAs,
	type RowAction,
	type RowResult,
} from '../duplicateActions'
import { sameParams, type ListingParams } from '../urlState'
import { compilePattern, MAX_PATTERN_LENGTH } from '../pathPattern'
import { chooseMatching, chosenIn, groupKey, stillListed, withGroupChoice } from '../pageSelection'
import { type AlgoOption } from '../../algorithms'
import { toastError, toastSuccess, toastUndo } from '../../toast'
import { n, t } from '../../l10n'

const props = withDefaults(
	defineProps<{
		/** Whose files to list; null is the viewer's own. */
		scope?: DuplicateScope | null
		/** Algorithm ids the instance computes, loaded once by the page. */
		algorithmIds: string[]
		/** Shown in place of the listing while no target is named. */
		emptyScopeText?: string
		/**
		 * Prefix for this instance's control ids. Two listings render at
		 * once, and an id may not repeat in a document — the labels point at
		 * their own fields through it.
		 */
		idPrefix?: string
		/**
		 * The filters and the page to show, from the URL. Applied when
		 * given and whenever it changes; what the controls then do is
		 * reported back through `update:params`, so the URL can follow.
		 */
		params?: ListingParams | null
	}>(),
	{
		scope: null,
		emptyScopeText: '',
		idPrefix: 'fcias-duplicates',
		params: null,
	},
)

const emit = defineEmits<{
	(e: 'canSudo', value: boolean): void
	(e: 'update:params', value: ListingParams): void
}>()

const ALL_ALGORITHMS: AlgoOption = { id: '', label: t('file_checksum_search', 'All algorithms') }

/** What each control decides, for the help button beside its label. */
const HELP = {
	algo: t('file_checksum_search', 'Only groups of this algorithm, or every algorithm at once. The list holds the algorithms this server computes; one the administrator has not allowed is not offered.'),
	min: t('file_checksum_search', 'The smallest group to list: how many files must share a checksum before they count as duplicates. Two lists every duplicate; a higher number lists only the widely copied ones.'),
	limit: t('file_checksum_search', 'How many groups one page shows. Nothing here is read from disk until you verify a group or a file, so a larger page costs a longer query, not longer reads.'),
	// TRANSLATORS: "Match anywhere in the hash" is the switch beside the field; translate it as that switch does
	hash: t('file_checksum_search', 'Show only groups whose hash matches what you type. Whole values come first, then those that start with what you typed. Turn on "Match anywhere in the hash" to match it in the middle of a hash too. Upper case is fine.'),
	// TRANSLATORS: "Select matching" is the button beside the field; translate it as that button does
	selectPattern: t('file_checksum_search', 'Select matching chooses, in every group shown, the rows this path matches, as the Path filter matches them. It never chooses every copy of a group outside the trash; such a group is left alone, and says so.'),
	// TRANSLATORS: Backup, *.bak and /Photos/2019 are examples of what to type; keep them as they are
	path: t('file_checksum_search', 'Show only groups with a file at this path; the group keeps its other files. A name, such as Backup or *.bak, matches anywhere, a file of that name or a folder and everything in it. With a slash, such as /Photos/2019, it matches from the top of your files. * stands for any part of a name, ** for any number of folders, ? for one character. Upper case is fine.'),
	empty: t('file_checksum_search', 'Every empty file has the same checksum, so all of them form one large group per algorithm, and deleting one frees no space. They are left out unless this is on.'),
}

// The space before "…" is a no-break space (U+00A0), as Nextcloud writes it.
// It is the character itself: the translation tool would keep an escape as
// text, and the text would no longer match its translation.
const SEARCHING = t('file_checksum_search', 'Searching …')

/** The fields' tooltips, too long for the template's one line. */
const TITLES = {
	min: t('file_checksum_search', 'Smallest group to list: files sharing a checksum, 2 to 100'),
	limit: t('file_checksum_search', 'Groups per page, 1 to 500'),
	anywhere: t('file_checksum_search', 'Match the term anywhere in the hash, not only at its start'),
	hash: t('file_checksum_search', 'Show only groups whose hash matches this'),
	hashPlaceholder: t('file_checksum_search', 'Whole hash or part of one'),
	path: t('file_checksum_search', 'Show only groups with a file at this path'),
	// TRANSLATORS: the placeholder of the Duplicates page's path filter
	pathPlaceholder: t('file_checksum_search', 'Folder, name or pattern'),
	keepOne: t('file_checksum_search', 'Selecting by path or the versions leaves each group a copy. Turned off, it selects every match, a content\'s last copy included.'),
}

const {
	canSudo,
	canRecalc,
	hash,
	anywhere,
	path,
	includeEmpty,
	// Renamed: the prop is the caller's wish, this ref is what the composable
	// currently asks the server for. The watcher below copies one to the other.
	scope: activeScope,
	algo,
	minCount,
	limit,
	groups,
	loading,
	loadingMore,
	hasMore,
	verifying,
	error,
	load,
	loadMore,
	reload,
	verifyGroups,
	verifyFile,
	fileUrl,
} = useDuplicates()

/**
 * The hash field reloads as it is typed, but not per keystroke: a hash is
 * long, and each character would otherwise cost a query whose answer is
 * thrown away by the next one.
 */
let hashTimer: ReturnType<typeof setTimeout> | undefined

function onHashInput(value: string | number): void {
	hash.value = String(value)
	clearTimeout(hashTimer)
	hashTimer = setTimeout(load, 300)
}

/** The path filter reloads as it is typed, after the same pause as the hash. */
let pathTimer: ReturnType<typeof setTimeout> | undefined

function onPathInput(value: string | number): void {
	path.value = String(value)
	clearTimeout(pathTimer)
	pathTimer = setTimeout(load, 300)
}

function onAnywhere(value: boolean): void {
	anywhere.value = value
	// Only worth re-asking when there is a term for it to change.
	if (hash.value.trim()) {
		load()
	}
}

// NcTextField emits string | number; the composable wants a bounded integer.
function bounded(value: string | number, min: number, max: number, fallback: number): number {
	const n = Math.trunc(Number(value))
	return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : fallback
}

/** A scoped listing with nothing named yet has nothing to ask for. */
const awaitingScope = computed(
	() => props.emptyScopeText !== ''
		&& props.scope !== null
		&& !props.scope.all
		&& props.scope.users.length === 0
		&& props.scope.groups.length === 0,
)

watch(() => props.scope, (value) => {
	activeScope.value = value
	if (!awaitingScope.value) {
		load()
	} else {
		groups.value = []
	}
}, { deep: true })

// Reported after every load rather than on change: the page holds an
// Others address until it hears whether the tab may be offered, and a
// "no" is the answer it never heard while only a change was reported —
// the value starts at false.
watch(loading, (now, before) => {
	if (before && !now) {
		emit('canSudo', canSudo.value)
	}
})

function refresh(): void {
	load()
}

// Every control asks for its own reload, rather than a watcher reloading on
// any change of the value: a set of values arriving together from the URL
// is then one load, not one per field.
function onAlgo(value: string | string[] | null): void {
	algo.value = typeof value === 'string' ? value : ''
	refresh()
}

function onIncludeEmpty(value: boolean): void {
	includeEmpty.value = value
	refresh()
}

// Clamped first, and reloaded only when the clamped value is new: "1000"
// typed over a limit of 500 is still 500, and not a second query.
function onMinCount(value: string | number): void {
	const next = bounded(value, 2, 100, 2)
	if (next === minCount.value) {
		return
	}
	minCount.value = next
	refresh()
}

function onLimit(value: string | number): void {
	const next = bounded(value, 1, 500, 50)
	if (next === limit.value) {
		return
	}
	limit.value = next
	refresh()
}

function currentParams(): ListingParams {
	return {
		hash: hash.value,
		anywhere: anywhere.value,
		path: path.value,
		algo: algo.value,
		includeEmpty: includeEmpty.value,
		minCount: minCount.value,
		limit: limit.value,
	}
}

/**
 * Show what the URL says. Nothing happens when it already says what is
 * shown, which is what the page's own writes come back as.
 */
function applyParams(next: ListingParams, force = false): void {
	if (!force && sameParams(next, currentParams())) {
		return
	}
	// A hash or path typed a moment ago must not load over what the address says.
	clearTimeout(hashTimer)
	clearTimeout(pathTimer)
	hash.value = next.hash
	anywhere.value = next.anywhere
	path.value = next.path
	algo.value = next.algo
	includeEmpty.value = next.includeEmpty
	minCount.value = next.minCount
	limit.value = next.limit
	if (!awaitingScope.value) {
		load()
	} else {
		groups.value = []
	}
}

watch(() => props.params, (next) => {
	if (next) {
		applyParams(next)
	}
}, { deep: true })

// The URL follows the controls. Reported on every change, a keystroke in
// the hash field included; the page replaces the address rather than
// pushing it, so that costs no history.
watch([hash, anywhere, path, algo, includeEmpty, minCount, limit], () => {
	emit('update:params', currentParams())
})

// A URL may name an algorithm before the list of what the instance computes
// has arrived. Once it has, one it does not name falls back to all of them.
watch(() => props.algorithmIds, (ids) => {
	if (ids.length > 0 && algo.value && !ids.includes(algo.value)) {
		onAlgo('')
	}
})

/**
 * $params, less an algorithm the instance's list — when known — does not
 * name. The watcher above covers a list that arrives after the listing;
 * this covers one that arrived before it, which is the Others listing's
 * case on every load from an address: the tab opens once the ordinary
 * listing has answered, and by then the list is in, so the watcher never
 * fires and an unknown `algo` in the address was kept for good.
 */
function withKnownAlgo(params: ListingParams): ListingParams {
	const ids = props.algorithmIds
	return ids.length > 0 && params.algo !== '' && !ids.includes(params.algo)
		? { ...params, algo: '' }
		: params
}

/**
 * Verification is asked for per group or per file, never for the page:
 * reading every file costs time and, on metered storage, money.
 */
async function onVerifyGroup(group: GroupType): Promise<void> {
	await verifyGroups([group])
}

/** The page acts on the viewer's own files only. */
const canAct = computed(() => props.scope === null)

/** Whether rows are being chosen on the page: every group's, a checkbox each. */
const selecting = ref(false)

/** The rows chosen, by file id: one choice for the page, whichever group lists a file. */
const selected = ref<Set<number>>(new Set())

/** The groups the last rule left alone, by key: it would have taken every copy outside the trash. */
const leftAlone = ref<Set<string>>(new Set())

/** The pattern the selection bar selects by. */
const selectPattern = ref('')

/**
 * D8-A: whether a rule leaves each group a copy, as it starts. Off, it
 * takes every match: the content holds a password, say, and must go
 * everywhere. The confirmation still says where no copy stays.
 */
const keepOne = ref(true)

function startSelecting(): void {
	selecting.value = true
}

function stopSelecting(): void {
	selecting.value = false
	selected.value = new Set()
	leftAlone.value = new Set()
	selectPattern.value = ''
	keepOne.value = true
}

/** A group's rows chosen by hand: the group's own choice, which no rule then stands for. */
function onGroupSelected(group: GroupType, fileIds: number[]): void {
	selected.value = withGroupChoice(selected.value, group, fileIds)
	leftAlone.value = new Set([...leftAlone.value].filter((key) => key !== groupKey(group)))
}

/** One row's box, ticked or not. */
function onChoose(group: GroupType, fileId: number, chosen: boolean): void {
	onGroupSelected(group, chosen
		? [...chosenIn(group, selected.value), fileId]
		: chosenIn(group, selected.value).filter((id) => id !== fileId))
}

/** The rows a rule picks added, across the groups shown; D1-A, D7-A and D8-A in {@link chooseMatching}. */
function chooseBy(matches: (file: GroupType['files'][number]) => boolean): void {
	const chosen = chooseMatching(groups.value, selected.value, matches, keepOne.value)
	selected.value = chosen.selected
	leftAlone.value = new Set(chosen.leftAlone)
}

function selectVersions(): void {
	chooseBy((file) => file.version !== undefined)
}

function selectMatching(): void {
	const matcher = compilePattern(selectPattern.value)
	if (matcher) {
		chooseBy((file) => matcher(file.path))
	}
}

function selectNone(): void {
	selected.value = new Set()
	leftAlone.value = new Set()
}

// Groups read again keep what is chosen in them, less rows no longer listed.
watch(groups, (now) => {
	selected.value = stillListed(selected.value, now)
})

const selectionCount = computed(() => n('file_checksum_search', '%n row selected', '%n rows selected', selected.value.size))

// TRANSLATORS: in the bar for choosing rows on the Duplicates page: the groups in which a pattern or Select versions selected nothing, since it matched every copy
const leftAloneCount = computed(() => n('file_checksum_search', '%n group left alone, as every copy in it matched', '%n groups left alone, as every copy in them matched', leftAlone.value.size))

/** True while a deletion or a restore runs: the menus wait for it. */
const acting = ref(false)

/** A deletion asked for and dry-run, waiting for its confirmation. */
interface PendingDeletion {
	group: GroupType
	fileIds: number[]
	forGood: boolean
	/** Rows chosen in a group: the way, and where the versions go, are chosen in the dialog. */
	choosing: boolean
	/** The file that takes the deleted files' versions; null for none, undefined while not chosen. */
	mergeInto?: number | null
	results: RowResult[]
}

const confirming = ref<PendingDeletion | null>(null)

const NETWORK_ERROR = t('file_checksum_search', 'Network error')

/** How each restore names what it did; {path} is where the result is. */
const RESTORED: Record<RestoreAs, (path: string) => string> = {
	original: (path) => t('file_checksum_search', 'Restored to {path}.', { path }),
	// TRANSLATORS: after a version of a trashed file went into the file now at that place; {path} is that file
	version: (path) => t('file_checksum_search', 'Added as a version of {path}.', { path }),
	// TRANSLATORS: after a version was saved as a new file; {path} is the new file
	file: (path) => t('file_checksum_search', 'Saved as {path}.', { path }),
}

/** What a row's menu entry does: a deletion is asked first, a restore is done. */
async function onAct(group: GroupType, file: GroupType['files'][number], action: RowAction): Promise<void> {
	if (action === 'trash' || action === 'delete') {
		await askToDelete(group, [file.fileid], action === 'delete')
	} else if (action !== 'mergeInto') {
		await restore(group, file.fileid, action === 'restore' ? 'original' : action === 'restoreAsVersion' ? 'version' : 'file')
	}
}

/** Rows chosen in a group: to the trash at first, the dialog offering the rest. */
async function onDeleteSelected(group: GroupType, fileIds: number[]): Promise<void> {
	await askToDelete(group, fileIds, false, true)
}

/**
 * A dry run first: the confirmation shows what the server says would
 * happen. Rows chosen in a group start with the versions' target the
 * group allows, and are dry-run again with it.
 */
async function askToDelete(group: GroupType, fileIds: number[], forGood: boolean, choosing = false): Promise<void> {
	acting.value = true
	try {
		const pending: PendingDeletion = { group, fileIds, forGood, choosing, results: [] }
		pending.results = await deleteRows(group, fileIds, { forGood, dryRun: true })
		if (choosing) {
			pending.mergeInto = defaultTarget(pending)
			if (pending.mergeInto) {
				pending.results = await deleteRows(group, fileIds, { forGood, dryRun: true, mergeInto: pending.mergeInto })
			}
		}
		confirming.value = pending
	} catch {
		toastError(NETWORK_ERROR)
	} finally {
		acting.value = false
	}
}

/** The files of the group that stay and may take the deleted files' versions. */
function targetsOf(pending: PendingDeletion): GroupType['files'] {
	return pending.group.files.filter((file) => !pending.fileIds.includes(file.fileid)
		&& !file.version && !file.trash && file.actions?.includes('mergeInto') === true)
}

/**
 * Where the versions go before anyone chooses: nowhere where none go
 * along or no file stays to take them, the one file that stays where one
 * does, and the person's choice where several do.
 */
function defaultTarget(pending: PendingDeletion): number | null | undefined {
	const targets = targetsOf(pending)
	if (targets.length === 0 || !pending.results.some((result) => (result.versions?.length ?? 0) > 0)) {
		return null
	}
	return targets.length === 1 ? targets[0].fileid : undefined
}

/** A choice changed in the dialog: what the server says now. */
async function rechoose(changes: { forGood?: boolean, mergeInto?: number | null }): Promise<void> {
	const pending = confirming.value
	if (!pending) {
		return
	}
	const next: PendingDeletion = { ...pending, ...changes }
	acting.value = true
	try {
		next.results = await deleteRows(next.group, next.fileIds, { forGood: next.forGood, dryRun: true, mergeInto: next.mergeInto ?? null })
		confirming.value = next
	} catch {
		toastError(NETWORK_ERROR)
	} finally {
		acting.value = false
	}
}

async function onConfirmDelete(): Promise<void> {
	const pending = confirming.value
	if (!pending) {
		return
	}
	acting.value = true
	try {
		const results = await deleteRows(pending.group, pending.fileIds, { forGood: pending.forGood, dryRun: false, mergeInto: pending.mergeInto ?? null })
		confirming.value = null
		// Done with: a row that went to the trash is listed still, and chosen
		// again it would go for good.
		selected.value = new Set([...selected.value].filter((id) => !pending.fileIds.includes(id)))
		reportDeleted([{ group: pending.group, results }])
	} catch {
		toastError(NETWORK_ERROR)
	} finally {
		acting.value = false
		reload()
	}
}

/** A deletion of the page's choice, dry-run, waiting for its confirmation. */
interface PendingPage {
	rows: GroupRows[]
	forGood: boolean
	/** D2-A: the deleted files' versions go to a copy that stays, or with their files. */
	keepVersions: boolean
	answers: GroupResults[]
}

const confirmingPage = ref<PendingPage | null>(null)

/** The order the server deletes a group's rows in: a version before the file that would take it along. */
function kindOrder(file: GroupType['files'][number]): number {
	if (file.version && file.trash) {
		return 0
	}
	return file.version ? 1 : file.trash ? 2 : 3
}

/**
 * D2-A: the copy that stays takes a group's deleted files' versions — the
 * one, or the most recently changed where several do. One the page deletes
 * elsewhere, listed under another algorithm, does not stay.
 */
function versionsTarget(group: GroupType, going: number[]): number | null {
	const staying = group.files.filter((file) => !going.includes(file.fileid) && !selected.value.has(file.fileid)
		&& !file.version && !file.trash && file.actions?.includes('mergeInto') === true)
	if (staying.length === 0) {
		return null
	}
	return staying.reduce((newest, file) => (file.mtime ?? 0) > (newest.mtime ?? 0) ? file : newest).fileid
}

/** The page's choice as rows of groups: each file once, in the first group listing it, in the order they go. */
function pageRows(keepVersions: boolean): GroupRows[] {
	const taken = new Set<number>()
	const rows: GroupRows[] = []
	for (const group of groups.value) {
		const going = group.files
			.filter((file) => selected.value.has(file.fileid) && !taken.has(file.fileid))
			.sort((a, b) => kindOrder(a) - kindOrder(b))
			.map((file) => file.fileid)
		if (going.length === 0) {
			continue
		}
		going.forEach((id) => taken.add(id))
		rows.push({ algo: group.algo, hash_value: group.hash_value, fileIds: going, mergeInto: keepVersions ? versionsTarget(group, going) : null })
	}
	return rows
}

/** The copies that take the versions, by group, for the confirmation to name. */
const pageTargets = computed<Record<string, number>>(() => Object.fromEntries((confirmingPage.value?.rows ?? [])
	.filter((row) => row.mergeInto)
	.map((row) => [groupKey(row), row.mergeInto as number])))

const deleteSelectedLabel = computed(() => n('file_checksum_search', 'Delete %n selected', 'Delete %n selected', selected.value.size))

/** Delete selected, for the page: a dry run first, to the trash, the versions kept. */
async function askToDeletePage(): Promise<void> {
	await dryRunPage({ rows: pageRows(true), forGood: false, keepVersions: true, answers: [] })
}

/** A choice changed in the page's confirmation: what the server says now. */
async function rechoosePage(changes: { forGood?: boolean, keepVersions?: boolean }): Promise<void> {
	const pending = confirmingPage.value
	if (!pending) {
		return
	}
	const keepVersions = changes.keepVersions ?? pending.keepVersions
	await dryRunPage({ ...pending, ...changes, rows: keepVersions === pending.keepVersions ? pending.rows : pageRows(keepVersions) })
}

async function dryRunPage(pending: PendingPage): Promise<void> {
	acting.value = true
	try {
		const answers = await deleteGroups(pending.rows, { forGood: pending.forGood, dryRun: true })
		confirmingPage.value = { ...pending, answers }
	} catch {
		toastError(NETWORK_ERROR)
	} finally {
		acting.value = false
	}
}

async function onConfirmPage(): Promise<void> {
	const pending = confirmingPage.value
	if (!pending) {
		return
	}
	acting.value = true
	try {
		const answers = await deleteGroups(pending.rows, { forGood: pending.forGood, dryRun: false })
		confirmingPage.value = null
		const going = new Set(pending.rows.flatMap((row) => row.fileIds))
		selected.value = new Set([...selected.value].filter((id) => !going.has(id)))
		reportDeleted(answers.map((answer) => ({ group: answer, results: answer.results })))
	} catch {
		toastError(NETWORK_ERROR)
	} finally {
		acting.value = false
		reload()
	}
}

/**
 * What a deletion did, of one group or of many, a toast each: what went to
 * the trash can be taken back, all of it at once.
 */
function reportDeleted(answers: Array<{ group: GroupKey, results: RowResult[] }>): void {
	const all = answers.flatMap((answer) => answer.results)
	const trashed = answers.flatMap((answer) => answer.results
		.filter((result) => result.status === 'trashed')
		.map((result) => ({ group: answer.group, fileId: result.fileid })))
	const deleted = all.filter((result) => result.status === 'deleted').length
	const missed = all.filter((result) => result.status === 'refused' || result.status === 'failed').length

	if (trashed.length > 0) {
		toastUndo(n('file_checksum_search', 'Moved %n file to the trash.', 'Moved %n files to the trash.', trashed.length), () => {
			undoTrash(trashed)
		})
	}
	if (deleted > 0) {
		toastSuccess(n('file_checksum_search', 'Deleted %n item permanently.', 'Deleted %n items permanently.', deleted))
	}
	if (missed > 0) {
		toastError(n('file_checksum_search', 'Could not delete %n item.', 'Could not delete %n items.', missed))
	}
}

/**
 * The Undo of a move to the trash: each file restored to its place, 500 a
 * request, so a large Undo is not stopped by the rate limit a row at a time
 * would reach. One message per reason, not one per row.
 */
async function undoTrash(rows: Array<{ group: GroupKey, fileId: number }>): Promise<void> {
	acting.value = true
	try {
		const results = await restoreRows(rows, 'original')
		const reasons = new Set(results.filter((result) => result.status !== 'restored').map((result) => result.reason ?? NETWORK_ERROR))
		for (const reason of reasons) {
			toastError(reason)
		}
	} catch {
		toastError(NETWORK_ERROR)
	} finally {
		acting.value = false
		reload()
	}
}

async function restore(group: GroupType, fileId: number, as: RestoreAs): Promise<void> {
	acting.value = true
	try {
		const result = await restoreRow(group, fileId, as)
		if (result.status === 'restored' && result.restored) {
			toastSuccess(RESTORED[as](result.restored.path))
		} else {
			toastError(result.reason ?? NETWORK_ERROR)
		}
	} catch {
		toastError(NETWORK_ERROR)
	} finally {
		acting.value = false
		reload()
	}
}

onMounted(() => {
	activeScope.value = props.scope
	if (props.params) {
		applyParams(withKnownAlgo(props.params), true)
	} else if (!awaitingScope.value) {
		load()
	}
})

onBeforeUnmount(() => {
	// The Others listing is destroyed on leaving the tab; a pending hash
	// load would otherwise fire into a component that is gone.
	clearTimeout(hashTimer)
	clearTimeout(pathTimer)
})
</script>

<template>
	<div>
		<!-- Labelled columns that flow on one row where there is room. The
		     labels are the page's own: NcTextField with label-outside renders
		     none, and drops its class on the input rather than its root, which
		     is why each field is sized by the wrapper around it. -->
		<div class="db-controls">
			<div class="db-field db-field--algo">
				<span class="db-label">
					<label :for="`${props.idPrefix}-algorithm`">{{ t('file_checksum_search', 'Algorithm') }}</label>
					<HelpPopover :text="HELP.algo" :label="t('file_checksum_search', 'Algorithm')" />
				</span>
				<AlgorithmSelect
					:model-value="algo"
					:algorithms="algorithmIds"
					:leading="ALL_ALGORITHMS"
					:input-id="`${props.idPrefix}-algorithm`"
					:label="t('file_checksum_search', 'Algorithm')"
					@update:model-value="onAlgo" />
			</div>
			<div class="db-field db-field--narrow">
				<span class="db-label">
					<!-- TRANSLATORS: a number field's label: the smallest number of files a listed group has, 2 to 100 -->
					<label :for="`${props.idPrefix}-min`">{{ t('file_checksum_search', 'Min. files') }}</label>
					<HelpPopover :text="HELP.min" :label="t('file_checksum_search', 'Min. files')" />
				</span>
				<NcTextField
					:id="`${props.idPrefix}-min`"
					:model-value="minCount"
					type="number"
					:label="t('file_checksum_search', 'Min. files')"
					label-outside
					min="2"
					max="100"
					:title="TITLES.min"
					@update:model-value="onMinCount" />
			</div>
			<div class="db-field db-field--narrow">
				<span class="db-label">
					<!-- TRANSLATORS: a number field's label: how many groups one page lists, 1 to 500 -->
					<label :for="`${props.idPrefix}-limit`">{{ t('file_checksum_search', 'Per page') }}</label>
					<HelpPopover :text="HELP.limit" :label="t('file_checksum_search', 'Per page')" />
				</span>
				<NcTextField
					:id="`${props.idPrefix}-limit`"
					:model-value="limit"
					type="number"
					:label="t('file_checksum_search', 'Per page')"
					label-outside
					min="1"
					max="500"
					:title="TITLES.limit"
					@update:model-value="onLimit" />
			</div>
			<div class="db-field db-field--hash">
				<span class="db-label">
					<label :for="`${props.idPrefix}-hash`">{{ t('file_checksum_search', 'Hash') }}</label>
					<HelpPopover :text="HELP.hash" :label="t('file_checksum_search', 'Hash')" />
					<!-- Beside the field it governs: it changes how the hash is
					     matched and does nothing on its own. -->
					<span :data-testid="`${props.idPrefix}-anywhere`" class="db-anywhere">
						<NcCheckboxRadioSwitch
							:model-value="anywhere"
							type="switch"
							:title="TITLES.anywhere"
							@update:model-value="onAnywhere">
							<!-- TRANSLATORS: a switch beside the hash filter: the typed text may match anywhere inside a checksum, not only at its start -->
							{{ t('file_checksum_search', 'Match anywhere in the hash') }}
						</NcCheckboxRadioSwitch>
					</span>
				</span>
				<NcTextField
					:id="`${props.idPrefix}-hash`"
					:model-value="hash"
					:label="t('file_checksum_search', 'Hash')"
					label-outside
					:placeholder="TITLES.hashPlaceholder"
					:title="TITLES.hash"
					@update:model-value="onHashInput" />
			</div>
			<div class="db-field db-field--path">
				<span class="db-label">
					<label :for="`${props.idPrefix}-path`">{{ t('file_checksum_search', 'Path') }}</label>
					<HelpPopover :text="HELP.path" :label="t('file_checksum_search', 'Path')" />
				</span>
				<NcTextField
					:id="`${props.idPrefix}-path`"
					:model-value="path"
					:label="t('file_checksum_search', 'Path')"
					label-outside
					:maxlength="MAX_PATTERN_LENGTH"
					:placeholder="TITLES.pathPlaceholder"
					:title="TITLES.path"
					@update:model-value="onPathInput" />
			</div>
			<!-- A filter with no field below it: on the fields' bottom edge,
			     beside the button, with its help where every label has it. -->
			<div class="db-empty-files" :data-testid="`${props.idPrefix}-include-empty`">
				<NcCheckboxRadioSwitch
					:model-value="includeEmpty"
					type="switch"
					@update:model-value="onIncludeEmpty">
					<!-- TRANSLATORS: a switch among the Duplicates page's filters: also list the groups of empty (zero-byte) files -->
					{{ t('file_checksum_search', 'Show empty files') }}
				</NcCheckboxRadioSwitch>
				<HelpPopover :text="HELP.empty" :label="t('file_checksum_search', 'Show empty files')" />
			</div>
			<div class="db-actions">
				<NcButton variant="primary" @click="refresh">
					{{ t('file_checksum_search', 'Refresh') }}
				</NcButton>
				<!-- Choosing rows across the page, on the viewer's own listing. -->
				<NcButton v-if="canAct && !selecting" data-testid="fcias-start-selecting" @click="startSelecting">
					{{ t('file_checksum_search', 'Select') }}
				</NcButton>
			</div>
		</div>

		<!-- While rows are chosen: what chooses across every group shown,
		     and what has been chosen. A group's own menu chooses in it. -->
		<div v-if="selecting && canAct" class="db-selection-bar" data-testid="fcias-selection-bar">
			<span class="db-field db-field--path">
				<span class="db-label">
					<label :for="`${props.idPrefix}-select-pattern`">{{ t('file_checksum_search', 'Select by path') }}</label>
					<HelpPopover :text="HELP.selectPattern" :label="t('file_checksum_search', 'Select by path')" />
				</span>
				<NcTextField
					:id="`${props.idPrefix}-select-pattern`"
					:model-value="selectPattern"
					:label="t('file_checksum_search', 'Select by path')"
					label-outside
					:maxlength="MAX_PATTERN_LENGTH"
					:placeholder="TITLES.pathPlaceholder"
					@update:model-value="selectPattern = String($event)"
					@keyup.enter="selectMatching" />
			</span>
			<NcButton data-testid="fcias-select-matching" :disabled="!selectPattern.trim()" @click="selectMatching">
				{{ t('file_checksum_search', 'Select matching') }}
			</NcButton>
			<NcButton data-testid="fcias-select-versions" @click="selectVersions">
				{{ t('file_checksum_search', 'Select versions') }}
			</NcButton>
			<span class="db-keep-one" data-testid="fcias-keep-one">
				<NcCheckboxRadioSwitch
					v-model="keepOne"
					type="switch"
					:title="TITLES.keepOne">
					<!-- TRANSLATORS: a switch in the bar for choosing rows on the Duplicates page: selecting by path or the versions leaves each group of duplicates one copy -->
					{{ t('file_checksum_search', 'Keep one copy') }}
				</NcCheckboxRadioSwitch>
			</span>
			<NcButton data-testid="fcias-select-none" :disabled="selected.size === 0" @click="selectNone">
				{{ t('file_checksum_search', 'Select none') }}
			</NcButton>
			<NcButton data-testid="fcias-delete-page"
				variant="error"
				:disabled="selected.size === 0 || acting"
				@click="askToDeletePage">
				{{ deleteSelectedLabel }}
			</NcButton>
			<NcButton data-testid="fcias-stop-selecting" @click="stopSelecting">
				{{ t('file_checksum_search', 'Cancel') }}
			</NcButton>
			<span class="db-selection-count" data-testid="fcias-selection-count">{{ selectionCount }}</span>
			<span v-if="leftAlone.size > 0" class="db-selection-left-alone" data-testid="fcias-selection-left-alone">{{ leftAloneCount }}</span>
			<!-- TRANSLATORS: after the count of groups left alone in the bar for choosing rows; "Keep one copy" is the switch beside it, translate it as that switch does -->
			<span v-if="leftAlone.size > 0" class="db-selection-left-alone">{{ t('file_checksum_search', 'Turn off Keep one copy to select them too.') }}</span>
		</div>

		<div class="db-scroll">
			<div v-if="awaitingScope" class="db-empty" data-testid="fcias-awaiting-scope">
				{{ emptyScopeText }}
			</div>
			<div v-else-if="loading" class="db-loading">
				{{ SEARCHING }}
			</div>
			<div v-else-if="error" class="db-error">
				{{ error }}
			</div>
			<div v-else-if="groups.length === 0" class="db-empty">
				{{ t('file_checksum_search', 'No duplicate files found.') }}
			</div>
			<DuplicateGroup
				v-for="(group, idx) in groups"
				:key="`${group.algo}-${group.hash_value}-${idx}`"
				:group="group"
				:file-url="fileUrl"
				:verifying="verifying"
				:can-verify="canRecalc"
				:can-act="canAct"
				:acting="acting"
				:selecting="selecting && canAct"
				:selected="chosenIn(group, selected)"
				:left-alone="leftAlone.has(groupKey(group))"
				@verify-group="onVerifyGroup"
				@verify-file="verifyFile"
				@act="onAct"
				@delete-selected="onDeleteSelected"
				@update:selected="onGroupSelected"
				@choose="onChoose"
				@start-selecting="startSelecting"
				@stop-selecting="stopSelecting" />
		</div>

		<DeleteDialog v-if="confirming"
			:group="confirming.group"
			:results="confirming.results"
			:for-good="confirming.forGood"
			:busy="acting"
			:choosing="confirming.choosing"
			:targets="targetsOf(confirming)"
			:merge-into="confirming.mergeInto"
			@update:for-good="rechoose({ forGood: $event })"
			@update:merge-into="rechoose({ mergeInto: $event })"
			@confirm="onConfirmDelete"
			@close="confirming = null" />

		<PageDeleteDialog v-if="confirmingPage"
			:groups="groups"
			:answers="confirmingPage.answers"
			:for-good="confirmingPage.forGood"
			:keep-versions="confirmingPage.keepVersions"
			:targets="pageTargets"
			:busy="acting"
			@update:for-good="rechoosePage({ forGood: $event })"
			@update:keep-versions="rechoosePage({ keepVersions: $event })"
			@confirm="onConfirmPage"
			@close="confirmingPage = null" />

		<!-- Appends, rather than paging: what is shown, and what is chosen
		     among it, stays, so a choice can span more than one load. -->
		<div v-if="hasMore && !loading && !awaitingScope" class="db-pagination">
			<NcButton data-testid="fcias-load-more" :disabled="loadingMore" @click="loadMore">
				<!-- TRANSLATORS: a button under the duplicate groups: show the next ones after those already shown -->
				{{ t('file_checksum_search', 'Load more') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.db-controls {
	display: flex;
	gap: 8px 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
	/* The labels line up along the top; the select is a few pixels taller
	   than a text field, and that difference is better hidden at the bottom. */
	align-items: flex-start;
}

.db-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.db-field--algo {
	width: 260px;
}

.db-field--algo :deep(.v-select.select) {
	min-width: 0;
	width: 100%;
}

.db-field--narrow {
	width: 100px;
}

/* Wide enough for a sha1 to be read back, and it wraps with the rest. */
/* Wide enough for a sha1 to be read back, and for the switch that sits in
   its label row. */
.db-field--hash {
	width: 420px;
}

/* A folder and a pattern, seldom longer than a few names. */
.db-field--path {
	width: 260px;
}

/* The switch is a caption-sized control here, not a settings row. */
.db-anywhere :deep(.checkbox-radio-switch__text) {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.db-label {
	display: flex;
	align-items: center;
	gap: 2px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.db-label :deep(.fcias-help-icon) {
	width: 24px;
	height: 24px;
	min-width: 24px;
	min-height: 24px;
}

.db-empty-files {
	display: flex;
	align-items: center;
	gap: 2px;
	align-self: flex-end;
}

.db-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	align-items: center;
	/* On the fields' bottom edge, the labels being above the fields. */
	align-self: flex-end;
}

/* The choosing's own row, set off from the filters as a mode is. */
.db-selection-bar {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 12px;
	align-items: flex-end;
	margin-bottom: 12px;
	padding: 8px 12px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
}

/* A switch among buttons, on their middle line. */
.db-keep-one {
	align-self: center;
}

.db-selection-count {
	align-self: center;
	font-weight: 600;
}

.db-selection-left-alone {
	align-self: center;
	color: var(--color-warning-text);
}

.db-scroll {
	max-height: calc(100vh - 180px);
	overflow-y: auto;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.db-pagination {
	display: flex;
	gap: 8px;
	justify-content: center;
	margin-top: 16px;
}

.db-empty,
.db-loading {
	text-align: center;
	padding: 32px;
	color: var(--color-text-maxcontrast);
}

.db-error {
	/* The palette's text red; --color-error is the background one. */
	color: var(--color-error-text);
	text-align: center;
	padding: 16px;
}
</style>
