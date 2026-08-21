# Cypress E2E tests

End-to-end tests for the File Checksum Index & Search (FCIAS) Nextcloud app,
run against a live Nextcloud instance (NC 33/34) via Cypress.

## Specs and execution order

Cypress runs specs in alphabetical order, and the order matters. Do **not** run
`duplicates.cy.js` or `global-search.cy.js` standalone — both depend on the data
produced by `checksums.cy.js`.

| Order | Spec                  | What it does                                                                                                                                                                                                                                                                 |
|-------|-----------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1     | `app-enable.cy.js`    | Enables/disables the app via the UI + `occ`, leaving it **enabled** for the following specs.                                                                                                                                                                                 |
| 2     | `checksums.cy.js`     | Uploads two identical files via WebDAV, computes their sha1 through the sidebar **"Recalc SHA-1"** action, verifies hash display and the recalc / "Find duplicates" buttons, then finds the duplicate **inline**. Produces the real duplicate pair the next spec asserts on. |
| 3     | `duplicates.cy.js`    | Asserts the duplicates page shows the real duplicate group, verifies hashes end-to-end, and exercises the "Only matching" filter.                                                                                                                                            |
| 4     | `global-search.cy.js` | Opens the unified search, verifies the **"File Checksums"** provider appears under **"Places"**, shows no files for an unknown hash, lists the indexed files for the real hash, and links to the file details view.                                                          |
| 5     | `rules.cy.js`         | Adds, edits, deletes, and toggles admin rule definitions against a stateful stubbed API.                                                                                                                                                                                     |

## Data strategy

- **Real files** are created via WebDAV `cy.request` (MKCOL/PUT). This indexes
  them in the filecache, so no `occ files:scan` is required.
- **Hashes** are computed through the sidebar's **"Recalc SHA-1"** action (the
  real `recalc` API), not via `occ file-checksum-search:generate`.
- **`cy.intercept` stubs** are used only where a deterministic state is needed —
  e.g. the "Only matching" filter needs one verified and one mixed group.
- **File ids** are resolved from a DAV `PROPFIND` (`oc:fileid`) request with an
  explicit `<d:propfind>` body.
- **Re-runnable without a DB reset**: each run uploads into a unique timestamped
  folder, and the hashed content (hence the search token) is constant, so
  repeated local runs simply accumulate files rather than collide. CI always
  starts from a fresh instance.

## Environment contract

- `CYPRESS_baseUrl` — Nextcloud base URL (e.g. `https://nextcloud-34.ddev.site`).
- `CYPRESS_occ` — shell prefix used to invoke `occ`
  (e.g. `cd ~/projects/nextcloud_testing/instances/34 && ddev exec php occ`).
- `CYPRESS_NC_ADMIN_USER` / `CYPRESS_NC_ADMIN_PASSWORD` — admin credentials
  (default `admin`/`admin`).
- Specs read these via `cy.env()` — the Cypress config sets
  `allowCypressEnv: false`, so `Cypress.env()` must not be used.

## Running

See [`../../.aiassistant/TESTING.md`](../../.aiassistant/TESTING.md) §9 for the
local and CI run commands. To run the whole suite:

```bash
CYPRESS_baseUrl=https://nextcloud-34.ddev.site \
CYPRESS_occ='cd ~/projects/nextcloud_testing/instances/34 && ddev exec php occ' \
npx cypress run
```
