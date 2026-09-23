# AP MockCleanup v1.0: real components in the specs

> **Status: proposal, 2026-09-23.** Since `6f93fc6` the Vitest config inlines
> `@nextcloud/vue`, so a spec can mount the real `NcSelect`, `NcDialog` or
> `NcActions` instead of a stand-in. Sixteen specs still carry 48
> `vi.mock('@nextcloud/vue/components/…')` lines from before that was
> possible, most of them with a template or logic of their own. Each such
> stand-in is a component the spec author wrote, and what the spec asserts
> against is that author's idea of Nextcloud's control, not the control.
> Nothing in `lib/` or `src/` outside the specs changes.

## Analysis

The mocks exist for one reason that no longer holds: the library's
components import their stylesheets, and externalised through Node's
loader that was a hard error. Inlined through Vite it is not. The sidebar
tab's spec, written after the change, mounts the real components and
passes; on the way it showed what the stand-ins had been hiding — the real
`NcSelect` needed more of `@nextcloud/l10n` and of `../algorithms` than the
partial mocks gave, which is the kind of gap a stand-in never reports.

Three kinds of mock, by what the spec does with them:

1. **Render-nothing stand-ins** — `NcLoadingIcon`, `NcRichText`, most
   `NcPopover`, `NcNoteCard`, `NcEllipsisedOption`, `NcButton`. The spec
   never touches them. Delete the mock, mount the real thing, nothing
   else changes. About half of the 48.
2. **Driven stand-ins** — `NcSelect` in six specs, `NcCheckboxRadioSwitch`
   in five, `NcActions`/`NcActionButton` in four, `NcDialog` in three. The
   spec emits into them (`vm.$emit('search', …)`), reads a made-up
   attribute (`data-filterable`), or clicks a `<button>` the stand-in's
   template put where the real menu entry would be. Each case has to be
   rewritten to drive the real control: open the dropdown and click the
   option, open the menu and click the entry, find the dialog where it
   teleports to. One helper per control, shared by every spec.
3. **A stand-in for a network call** — `NcSettingsSelectGroup`, in two
   specs, which fetches the instance's groups from core's OCS API on mount.
   The mock is standing in for the request, not the control. Decision 1.

What the real controls need from the runner: `happy-dom` has no
`ResizeObserver`, and `NcPopover`/`NcDialog` position through floating-ui
and teleport to `document.body`. A setup file providing the observer and
`matchMedia`, and `attachTo: document.body` where a dialog or menu is
opened, is the expected cost; the sidebar spec needed neither, since it
opens nothing.

## Blocks

1. **[TASK] The setup file, and the stand-ins nobody drives.** `vitest.
   setup.ts` with `ResizeObserver` and `matchMedia` stubs, named in the
   config. Then every kind-1 mock deleted, spec by spec, the suite green
   after each file. Partial mocks of `@nextcloud/l10n` and other modules
   that the real components now read more of spread the original, as the
   sidebar spec does. Vitest: unchanged assertions, real components.

2. **[TASK] `NcSelect`.** A helper `pickOption( wrapper, inputId, label )`
   that opens the real vue-select and clicks the option, and `typeToSearch`
   for the search event. `AlgorithmSelect.spec`, `TargetPicker.spec`,
   `RuleForm.spec`, `PermissionSection.spec` and the two `App.spec`s on it;
   the `data-filterable` assertion becomes what it stood for — typing past
   the prefill threshold fetches, under it does not.

3. **[TASK] `NcCheckboxRadioSwitch`, `NcActions`, `NcDialog`.** The switch
   clicked as a switch; the menu opened and its entry clicked, mounted on
   the body; the dialog found where it teleports to, its buttons clicked
   there. `RuleRow`, `RuleTable`, `RuleForm`, `SudoTokensSection`,
   `PermissionSection` and the two `App.spec`s.

4. **[TASK] `NcSettingsSelectGroup`.** Per decision 1: the OCS request
   mocked at `fetch`/axios, the control real; or the one component mock
   left, with a comment saying why it is the exception.

5. **[TASK] Keep it out.** An ESLint rule for `src/**/*.spec.ts` refusing
   `vi.mock('@nextcloud/vue/…')` — `no-restricted-syntax` on the call —
   so the pattern cannot creep back; the exception of block 4, if kept, by
   a disable comment on its line. `tests/e2e/README.md` or the TS testing
   baseline says specs mount the real components. The comment in
   `vitest.config.ts` shortened to what remains true.

## Tests that must exist before this ships

- The suite green with zero `vi.mock('@nextcloud/vue` lines, or one, per
  decision 1.
- Every case that drove a stand-in drives the real control and asserts
  the same outcome.
- The lint rule fails on a spec that adds such a mock.

## Not in this AP

- Any change to `lib/` or to `src/` outside `*.spec.ts`, the setup file
  and the config. A spec that cannot pass against the real component has
  found a defect; that is a `[FIX]` of its own, gated on its own.
- Mocks of `@nextcloud/router`, `@nextcloud/l10n`, `@nextcloud/dialogs`
  and the app's own modules. Those stand in for the server or the page,
  which the runner has not got.

## Open decisions

1. **`NcSettingsSelectGroup`.** Mock the request (recommended: the control
   is then real, and the spec says what the page does with the groups the
   server names), or keep the one component mock as a marked exception.
2. **Duration budget.** The suite runs in 5 s. Real controls will cost;
   recommended ceiling 15 s for the whole suite, checked at each gate, and
   the block that breaks it is rethought before it lands.
3. **Order.** By control (recommended: one helper, every spec at once) or
   by spec file (one file green at a time, helpers growing as they go).

## Gate

`npx vitest run` and `npm run lint` after every block; `npm run build`
once, since nothing shipped changes; the duration of decision 2 reported
at each gate. One commit per block. No CHANGELOG bullet: `src/**/*.spec.ts`
and the config are not shipped, and the exemption is said in each commit.

## Change History

- v1.0 (2026-09-23): written at the user's request after `6f93fc6` inlined
  the library and the sidebar spec mounted the first real components.
