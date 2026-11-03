# AP NextcloudVue v1.0: The shared frontend from the package

## TL;DR

The frontend's help popover, toasts, `t()`/`n()`, clipboard composable
and spec helpers are the same code as `@metaworx/nextcloud-vue` 0.1.2
under another class prefix.
This app takes them from the package, as Large File Previews did,
and deletes its own copies.

- `.npmrc`: `@metaworx` → GitLab's registry; `^0.1.2`
- specs: `src/test-utils/` → `@metaworx/nextcloud-vue/testing`;
  `vitest.setup.ts` → `setupNextcloudDom()`
- runtime: `src/l10n.ts`, `src/toast.ts`, `toast.css`, `HelpPopover`,
  `useClipboard` → the package; 53 import lines in 32 files (V2-A)
- texts: `help` and `saved` only, both translated already (V1-A);
  `src/texts.ts`, `setTexts()` in all four entries
- `fcias-help*` → `mwx-help*`; the popover 260 → 320px wide
- out: `ocsCall`, `NumberField`, `OccCommand`
- contract §3.6, §3.7; one CHANGELOG bullet, amended

### Blocks

1. The specs: the package installed, the runner setup and the helpers from it, `src/test-utils/` deleted
2. The runtime: `texts.ts` and the entries, the imports, the copies deleted, the `mwx-` selectors

## Discussion

- **Origin.**
  The library session announced the package on 2026-11-03;
  Large File Previews took it the same day,
  in the commit `[TASK] Settings page: the shared components and helpers come from @metaworx/nextcloud-vue`,
  the worked example here.
  The user's `PLAN vue` asked for the survey, and `PLAN++ vue` for this plan.
- **Decided at the TL;DR gate of 2026-11-03,** as recommended:
  - V1-A: the app hands the package `help` and `saved`,
    the two texts its own components and toasts show,
    both translated here already.
    The other seven belong to `NumberField` and `OccCommand`, which this app does not use;
    they keep the package's English defaults, and no new string reaches translators.
  - V2-A: the app imports from the package, as LFP does,
    and `src/l10n.ts`, `src/toast.ts` and the rest are deleted,
    rather than kept as one-line re-exports.
- **Left out:**
  - `ocsCall()`: no abort signal, never throws, other headers;
    the app's callers rely on all three.
  - `NumberField`: the Tunables section would be redesigned around it,
    with three new texts.
  - `OccCommand`: nothing here shows a command box.
- **Visible change:** the help popover's text is 320px wide at most, not 260,
  and its trigger's classes are `mwx-help`, `mwx-help-icon`, `mwx-help-body`.

## Analysis

- **What is the same.**
  `l10n.ts` and `toast.ts` match the package's line for line,
  but for `toastSaved()`'s default, `texts().saved`.
  `useClipboard.ts`, `ncActions.ts`, `ncSelect.ts` and `settle.ts` differ in comments only.
  `vitest.setup.ts` is `setupNextcloudDom()`:
  the quiet `ResizeObserver`, teleports in place, auto-unmount, `sessionStorage` cleared.
  `HelpPopover.vue` differs in its prefix, its width, its `aria-label`
  (`texts().help(label)`) and a default slot this app does not fill.
- **Import sites,** outside the files deleted:
  31 `l10n` lines, 10 `toast`, 10 `HelpPopover.vue`, 2 `useClipboard`, in 32 files;
  10 `test-utils/` in 10 specs.
- **Entries:** `duplicates-vue/main.ts`, `settings-admin-vue/main.ts`,
  `settings-personal-vue/main.ts` and `sidebar.ts`.
  Every one renders a `HelpPopover`, and the sidebar's custom element has no shadow root,
  so the package's stylesheet applies wherever it is imported.
  Each calls `setTexts()` and imports `@metaworx/nextcloud-vue/style.css`.
- **The texts.**
  `'Help: {label}'` and `'Saved.'` move from `HelpPopover.vue` and `toast.ts` to `src/texts.ts`,
  unchanged; `scripts/l10n.sh update` moves their references, and no translation changes.
  `texts.ts` exports a function the entry calls,
  so the texts are translated when the page starts,
  not when a module loads (`/AGENTS.md` §3.7).
- **Specs.**
  The 11 that mock `../toast` mock the package partially,
  spreading `importOriginal()`, as the contract's §3.6 asks.
  `HelpPopover.spec.ts`, `l10n.spec.ts` and `useClipboard.spec.ts` go with their sources:
  the package holds the first two, and the library is offered the third,
  since it has no spec of the composable.
  LFP's run saw its old `HelpPopover.spec.ts` raise a focus-trap error the package's does not.
- **The runner** inlines `@nextcloud/dialogs` and the package beside `@nextcloud/vue`:
  the package's build keeps its import of `@nextcloud/dialogs/style.css`.
- **`.eslintrc.cjs`**: the toast rule matches the function names and stays;
  the spec rules' messages name `src/test-utils/`, which becomes the package's `./testing`.
- **Selectors:** `settings-admin.css` (2), `DuplicateListing.vue` (1),
  `RuleTable.spec.ts` (2), `tests/e2e/duplicates.cy.js` (1).
- **Install.**
  One `.npmrc` line, no token; the lock file records a gitlab.com tarball
  with a sha1 integrity, the only hash GitLab's registry gives.
  `.npmrc` joins `.nc.publish.ignore`.
  `@nextcloud/auth`, a peer, is in the tree already through `@nextcloud/vue`.
- **CHANGELOG**: one bullet under `### Changed`,
  written in Block 1 and amended in Block 2 (`GUIDELINES/shared/COMMIT.md` §5.2).

## Implementation Plan

### Block 1: The specs

1. `.npmrc`: `@metaworx:registry=https://gitlab.com/api/v4/packages/npm/`;
   `npm install @metaworx/nextcloud-vue@^0.1.2`; `package-lock.json`;
   `.nc.publish.ignore`: `/.npmrc`.
2. `vitest.setup.ts` → `setupNextcloudDom()`;
   `vitest.config.ts` inlines `@nextcloud/dialogs` and the package.
3. The ten specs: `../test-utils/…` → `@metaworx/nextcloud-vue/testing`;
   `src/test-utils/` deleted.
4. `.eslintrc.cjs`: the three spec-rule messages and the comment name the package's `./testing`.
5. Contract §3.6: the helpers and the runner setup are the package's; `sync-docs.sh`.
6. `CHANGELOG.md` `### Changed`: the bullet.

**Verification:** `npx vitest run` as before, every spec; `npm run lint`, `typecheck`;
`changelog.sh check`; `tools/check`.

### Block 2: The runtime

1. `src/texts.ts`: `appTexts(): Partial<Texts>` with `help` and `saved`.
2. The four entries: `setTexts(appTexts())` and the package's stylesheet.
3. The import lines: `l10n`, `toast`, `HelpPopover.vue`, `useClipboard` → `@metaworx/nextcloud-vue`.
4. Deleted: `src/l10n.ts`, `l10n.spec.ts`, `toast.ts`, `toast.css`,
   `components/HelpPopover.vue` and its spec, `composables/` with its spec.
5. The 11 toast mocks → partial mocks of the package.
6. `fcias-help-icon` → `mwx-help-icon` in the four files.
7. Contract §3.7: `t` and `n` come from the package; `src/texts.ts`; `sync-docs.sh`.
8. `scripts/l10n.sh update`, `build`; the CHANGELOG bullet amended.

**Verification:** `npx vitest run`, `npm run lint`, `stylelint`, `typecheck`, `build`;
`scripts/l10n.sh check`; `tools/check`;
on the harness's 34, after the build,
`duplicates.cy.js`, `rules-admin.cy.js` and `l10n-german.cy.js`.

## Proposed commit messages

- `[UPDATE] APs: +NextcloudVue v1.0`
- `[TASK] Frontend specs: the runner setup and the helpers come from @metaworx/nextcloud-vue`
  (`AP NextcloudVue v1.0, Block 1`)
- `[TASK] The help popover, the toasts and t() come from @metaworx/nextcloud-vue`
  (`AP NextcloudVue v1.0, Block 2`)
- `[UPDATE] APs: -NextcloudVue`, offered at the completion gate

## Change History

- v1.0: the plan, from the user's `PLAN++ vue` on the TL;DR gate of 2026-11-03, with V1-A and V2-A.
