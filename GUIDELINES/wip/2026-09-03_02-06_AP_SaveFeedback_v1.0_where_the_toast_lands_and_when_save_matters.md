# AP SaveFeedback v1.0: where the toast lands, and when Save matters

> **Status: approved 2026-09-03, in execution.** Follows Toasts v1.0 (e0665af),
> which made the toasts fire at all.

## Findings

**The toast appeared top-left because the app never imported the library's
stylesheet.** `@nextcloud/dialogs` 7.5 renders its own toast container (it no
longer uses toastify-js, and core's `toast.scss` styles only the old one).
`dist/style.css` positions that container:

```
position: fixed; left: var(--body-container-margin); bottom: var(--body-container-margin);
```

plus a `left: calc(var(--navigation-width) + …)` offset while the navigation is
open, and a green left border on success (`--color-element-success`). Without
the import the container has no positioning and collapses to the page origin —
the top-left corner of the screenshot.

So the placement asked for is the library's own: **bottom**, clear of the
navigation, green on success. It sits at the start edge rather than centred;
that is where every other Nextcloud 34 app's toast appears, so this AP takes
the platform's placement and does not centre it.

**Notifications are not the alternative.** The bell is for events that happen
while nobody is looking — an app updated overnight, a share arriving. It
persists until dismissed and needs the notifications app. Feedback for a save
the administrator just clicked is a toast, which is what Nextcloud's own
settings pages use. Not implemented.

## Decision

Three commits.

**1. The toast lands where the platform puts it, and says what was saved.**
`import '@nextcloud/dialogs/style.css'` in both settings entry points.
Per-section texts return: "Permissions saved.", "Algorithms saved.",
"Preferred algorithm saved." `toastSaved()` keeps "Saved." as its default for
callers with nothing more specific to say.

**2. Save says whether there is anything to save.** Both admin sections
snapshot what the server gave them and compare on every change. Clean: the
button is disabled. Dirty: `NcButton variant="warning"` — yellow, as asked.
Red is the `error` variant and reads as destructive in Nextcloud's palette,
so it is not used for "you have unsaved changes". The plain `<button
class="fcias-btn">` becomes `NcButton` in both sections; the algorithm
section keeps `#fcias-btn-save-algorithms` and the permission section gains
`#fcias-btn-save-permissions`. Saving refreshes the snapshot, so the button
goes quiet again. The personal page saves on select and has no button.

**3. The e2e suite stops swallowing our own exceptions.** The
`uncaught:exception` handler in `tests/e2e/support/e2e.js` ignores everything;
it hid nineteen dead `OC.Notification` calls for as long as they existed. It
now fails the spec when the error comes from this app — `file_checksum_search`
in the message or the stack — and keeps ignoring core's dashboard noise, with
the message logged either way.

## Scope

Commit 1: `settings-admin-vue/main.ts`, `settings-personal-vue/main.ts`,
`PermissionSection.vue`, `AlgorithmSection.vue`, `PreferenceSection.vue`,
their specs, CHANGELOG (amend the Toasts bullet forward).

Commit 2: `PermissionSection.vue`, `AlgorithmSection.vue`, their specs
(dirty → enabled and warning; save → clean again), CHANGELOG.

Commit 3: `tests/e2e/support/e2e.js`, CHANGELOG.

Verification per commit: lint, vitest, build, and the settings e2e specs
(rules-admin, rules-personal, status); commit 3 runs the full suite, since it
is the suite that changed. The toast's placement on screen is the user's to
confirm — headless I can only show that the stylesheet is in the bundle.

## Open decisions

- Placement is the library's: bottom, start edge, offset by the navigation.
  Centring is a one-rule override if it is wanted after seeing it.

## Change History

- v1.0 (2026-09-03): initial.
