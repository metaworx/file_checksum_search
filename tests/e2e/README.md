# Cypress E2E tests

End-to-end tests for the File Checksum Index & Search (FCIAS) Nextcloud app,
run against a live Nextcloud instance (NC 33/34) via Cypress.

## Specs

Cypress runs specs in alphabetical order. **Every spec builds its own state**,
so any one of them can be run on its own — which was not true before, and is
what the specs below spend their `before()` hooks on.

| Spec                  | What it does                                                                                                                                                                                                        |
|-----------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `app-enable.cy.js`    | Enables/disables the app via the UI + `occ`, leaving it **enabled** for the following specs.                                                                                                                        |
| `checksums.cy.js`     | Uploads two identical files via WebDAV and computes their sha1 through the sidebar **"Recalc SHA-1"** action — the one spec that makes the app hash something for real. Also asserts inline "Find duplicates".      |
| `duplicates.cy.js`    | Creates three files, resets, states their hashes from a fixture, then asserts the page's group, verifies hashes end to end, and exercises the "Only matching" filter against a stub.                                |
| `global-search.cy.js` | Creates one file with a hash nothing else has, then opens the unified search, verifies the **"File Checksums"** provider appears under **"Places"**, and checks a hit and a miss.                                   |
| `rules-admin.cy.js`   | Live, no stubs: quiet start and the idle banner, the banded table, creating a rule through the dialog, placeholder rows, the in-dialog error card, the provider-missing badge, and the row action menu.              |
| `rules-api.cy.js`     | No browser: the rules API's permission matrix across admin, alice and bob — who may read what, whose rule may be mutated by whom, and what a non-administrator's selector is turned into.                            |
| `rules-personal.cy.js`| Alice's own page, an enforced rule she may not touch, bob refused a recalculation by *alice's* rule on a file she shares with him, and a hash search returning nothing to someone who cannot reach the file.         |
| `status.cy.js`        | The admin status panel: hash count, versions, an empty queue reported as empty, what a reset disowns and what finishing it leaves, the background-job heartbeat, and Refresh.                                        |

The stub-era `rules.cy.js` was removed: it stubbed `/settings/cron/*` endpoints
that no longer exist and waited for UI text that had changed, failing 4/4
against a live instance. `rules-admin.cy.js` replaces it.

### Two things worth knowing about the rules page

- **The row action menu is an `NcActions` popover.** Its trigger
  (`.action-item__menutoggle`) is inside the `<tr>`; the items it opens render
  **outside** it, in `.action-item__popper`, so an item lookup cannot be scoped
  to the row. NcActions also leaves a popper in the DOM after it closes —
  reload between two menus rather than asserting that the second one lacks
  something the first one had.
- **The page asks for `?scope=all`.** That is the difference between `canEdit`
  true and false on a rule whose selector is not the caller's own home, and so
  between a row that has an action menu and one that reads "Read-only". A
  `cy.ocs()` assertion has to request the same view the page does.

### Writing API assertions

- **`cy.request()` shares the browser's cookie jar**, and Nextcloud issues a
  session on the first authenticated call. Without clearing cookies, every
  later request is attributed to the *first* caller whatever credentials it
  carries — measured while writing `rules-api.cy.js`, an anonymous request came
  back 200 and alice was allowed to write a rule for bob, both because admin's
  session was still in the jar. `cy.ocs()` clears cookies on every call for
  exactly this reason; a raw `cy.request()` has to do it itself.
- **`cy.ocs()` clears cookies, which ends any browser session the spec had.**
  Convenient in an API-only spec, a trap in a UI one: log in again after
  calling it, or the next `cy.visit()` lands on the login page.
- **The unified-search provider is a *core* OCS route**, so unlike this app's
  own endpoints it does wrap its answer in `{ocs:{data}}`. Assert on
  `body.ocs.data.entries`.
- **Chain `invoke()`, never `then()`, when asserting on rendered text.** A
  `then()` resolves once and hands on a plain value, so the assertion after it
  stops retrying the DOM — and every cell on the status panel renders a
  placeholder first and its value when the request answers. Written with
  `then()`, a passing panel reads as a broken one.
- **Build state next to the assertion that needs it**, not once in `before()`.
  A spec that resets or disowns on purpose invalidates its own earlier counts,
  and a number established at the top stops being true a test or two later.
- **Measure the status code before asserting it.** Two of this matrix's rows
  were written from a plausible guess and were wrong in a way that mattered: a
  non-administrator's selector is not validated and refused, it is *replaced*
  with their own home. Asserting 403 would have passed for the wrong reason on
  the day the replacement stopped happening.

## Data strategy

- **Real files** are created via WebDAV `cy.request` (MKCOL/PUT). This indexes
  them in the filecache, so no `occ files:scan` is required.
- **Hashes** are computed through the sidebar's **"Recalc SHA-1"** action (the
  real `recalc` API), not via `occ file-checksum-search:generate`.
- **`cy.intercept` stubs** are used only where a deterministic state is needed —
  e.g. the "Only matching" filter needs one verified and one mixed group.
- **File ids** are resolved from a DAV `PROPFIND` (`oc:fileid`) request with an
  explicit `<d:propfind>` body.
- **Re-runnable**: fixed directories and a reset, not accumulation. Specs upload
  into a fixed folder so a re-run overwrites the same files, and
  `cy.resetFciasState()` clears the hashes before a spec states its own. This
  paragraph used to claim that repeated runs "simply accumulate files rather
  than collide" — the accumulation was the bug: one hash group reached 145 files
  and Verify hashes started hitting the per-user recalculation rate limit
  partway through, failing for a reason that had nothing to do with the page.
- **Selectors are text-free.** Assert on `data-*` attributes, ids and API
  payload fields, never on the app's own visible strings: the app is not
  translated yet, and every `cy.contains( 'Enable' )` becomes a failure the day
  it is. Untranslated Nextcloud core chrome (the login page, the apps list) is
  the one exception.

## State and fixtures

Two support commands put the instance in a known state instead of accumulating
whatever earlier specs left behind. Both take the same `occ` prefix the specs
already resolve from `CYPRESS_occ`.

| Command | What it does |
|---|---|
| `cy.resetFciasState( occ )` | `fcias:reset --hashes --status --force --now`, then `fcias:repair --step selector-model --step metadata-keys`. `--now` because the default reset leaves the clearing to a background job, and a spec cannot wait for a job it does not control. Those two steps and no others: a whole repair would run `rebuild-from-filecache`, which copies checksums back out of `oc_filecache.checksum` — a column a hash reset deliberately leaves alone — and so undoes the reset it follows. |
| `cy.importFciasFixture( occ, name, user = 'admin' )` | Reads `tests/e2e/fixtures/<name>.json` and states the hashes outright, rather than computing them and waiting. |
| `cy.fciasResetRules( occ )` | Deletes every rule, drops the idle-banner acknowledgement, and lets `fcias:repair` recreate the two shipped defaults — both disabled, which is the quiet-start state. Through `occ`, because REST refuses some of these mutations by permission design. |
| `cy.fciasEnsureUsers( occ )` | Creates `alice` and `bob` (password `SecretPass123!`). The developer instance has them; CI starts with `admin` alone, so a cross-user spec has to make its own. |
| `cy.ocs( { method, url, body, user, password } )` | One authenticated call to this app's API. The endpoints answer under `/ocs/v2.php` **only**, the body is plain JSON with no `ocs` envelope (the controllers extend `ApiController`), and `failOnStatusCode` defaults to `false` so a wrong status is an assertion failure with both numbers rather than an abort. |

A fixture names **no storage**, so its paths are read as relative to `user`'s
files directory — which is what lets one fixture serve any instance. The spec
creates the files; the fixture gives them their hashes. It travels in on
standard input, because `cy.exec()` runs on the host while `occ` may run inside
a container and the two do not agree on where the repository is.

Both allow five minutes rather than `cy.exec()`'s default sixty seconds:
clearing hashes in the foreground rewrites one metadata document per file, which
measures at roughly five files a second against ddev.

## Environment contract

- `CYPRESS_baseUrl` — Nextcloud base URL (e.g. `https://nextcloud-34.ddev.site`).
- `CYPRESS_occ` — shell prefix used to invoke `occ`
  (e.g. `cd ~/projects/nextcloud_testing/instances/34 && ddev exec php occ`).
- `CYPRESS_NC_ADMIN_USER` / `CYPRESS_NC_ADMIN_PASSWORD` — admin credentials
  (default `admin`/`admin`).
- Specs read these via `cy.env()` — the Cypress config sets
  `allowCypressEnv: false`, so `Cypress.env()` must not be used.

## Running

Set the environment contract above, then run the whole suite:

```bash
CYPRESS_baseUrl=https://nextcloud-34.ddev.site \
CYPRESS_occ='cd ~/projects/nextcloud_testing/instances/34 && ddev exec php occ' \
npx cypress run
```
