# AP VerifyPerRow v1.0: verification you ask for

> **Status: proposal, 2026-09-03.** From the review of the Duplicates page:
> *"remove. that action could take hours and run over metered storage.
> instead we add a verify button to each row."*

## Why

**Verify hashes** recomputes every file in the listing from its content. That
is not a cheap button. A page of fifty groups can hold hundreds of files;
reading each one means pulling its bytes — from external storage, from an
object store, from whatever the account has mounted. On metered storage that
costs money, and on slow storage it costs hours. The app already takes this
seriously enough to have a rule type for it: `exclude` exists precisely for
"storage that is slow or costs money to read".

So a single button that silently commits to all of it is the wrong shape. The
cost should sit under the finger of whoever chooses to pay it, at the
granularity they choose.

**Only matching** goes with it. It filters the results of that bulk run, and
does nothing at all until one has happened — a control that silently ignores
you is worse than no control. With verification becoming per row, a
page-wide filter over verification state has nothing coherent to filter.

## What changes

- The page-wide **Verify hashes** button and the **Only matching** checkbox
  are removed from `DuplicateListing`.
- A group header gains **Verify all** — every file in *that* group.
- A file row gains **Verify** — that one file.
- What each verification reports is unchanged: the tick, the cross with the
  hash it found instead, and the group header's matched/mixed badge. Only
  who asks for it, and for how much, changes.

## The rate limit stays, scoped

Recalculation is capped at 20 a minute per account, and a large group can
exceed that on its own. The existing behaviour is kept and scoped to what
was asked for: the run stops where the limit answered, says so, marks
nothing it never asked about, and a second click resumes rather than
replaying. That logic is `useDuplicates`'s already; it is invoked with one
group, or one file, instead of the page.

## Blocks

1. **[TASK] Verify what was asked for.** `useDuplicates` gains a
   single-file verification beside the group one, both sharing the loop that
   already handles the limit and the resume. The page-wide button and the
   filter go; `DuplicateGroup` gains the two buttons and emits what to
   verify. Vitest for one file and for one group, including the limit.
2. **[TASK] The e2e and the docs.** The `duplicates` spec verifies through
   the group button rather than the page one, and the *Only matching* case
   goes with the checkbox. User guide rewritten for both buttons and the
   reason; CHANGELOG under Changed.

## Open decisions

1. Whether **Verify all** should warn before a large group — a confirm
   above, say, 50 files. Recommended not for now: the button names its
   scope, and the group header already says how many files it holds.
2. Whether a verified file should stay marked after a reload. It does not
   today (verification is page state, never stored) and this AP keeps that;
   storing it would mean writing a judgement about content we did not
   re-read.

## Gate

PHP suite (unchanged by this, run anyway), `npm run lint`,
`npx vitest run`, `npm run build`, the `duplicates` e2e; full e2e at the end.

## Change History

- v1.0 (2026-09-03): proposal.
