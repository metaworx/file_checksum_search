# AP StrayCookie v1.0: Stop the stray session cookie

## TL;DR

Two intermittent CI failures in `duplicates.cy.js` come from one cause, and
the server was ready both times: Nextcloud's Files service worker writes
session cookies into the browser's jar on its own schedule. Stop it from
being registered, and check a fresh account's home once.

- Writer: `preview-service-worker.js`, scope `/`, registered whenever
  Files opens — `cy.login`'s `validate()` opens it on every restore
- stable33: MKCOL with a lone stray session cookie → 412 strict cookie
- stable34: session swapped between login page and submit → CSRF failure
- Fix: no `navigator.serviceWorker` on test pages; existing registrations
  removed before each spec
- Home: PROPFIND retried up to ten times → one PROPFIND, 207 asserted

### Blocks

1. `tests/e2e/support/e2e.js`: block and unregister service workers, one
   home check, comments that name the real cause.

## Discussion

- D1-A, as the TL;DR gate recommended: stop the writer. Sending API calls
  from Node (`cy.task`) would leave the form login exposed; a retry on 412
  or a CSRF failure is the blind retry the user ruled out.
- D2-A: one PROPFIND that must answer 207. It creates the home and fails
  with the status it got, ahead of a spec's first write.

## Analysis

**CI, 2026-11-04.**

- Run 41831517675, job 126579612312, NC stable33: the account's OCS create
  answered 200, its PROPFIND 207 on the first try, two `cy.clearCookies()`
  followed, and the MKCOL went out carrying one cookie, the session
  (`ociz7lqjerg2`), without the passphrase or the same-site cookies. The
  server answered 412, `Strict Cookie has not been found in request`:
  `lib/base.php` refuses a `remote.php` request that carries a session
  but not `nc_sameSiteCookiestrict`.
- Run 41845932037 (attempt 1), job 126629295421, NC stable34: the failure
  screenshot shows the login page with "Session error — your session token
  has expired": `LoginController::tryLogin()` failed its CSRF check, so the
  session the form was submitted with was not the one the page was rendered
  in.

**The writer.** The Files app registers `/index.php/apps/files/preview-service-worker.js`
with scope `/`. Cypress clears the page, cookies and storage between tests,
but leaves service workers registered. The browser checks a registered
worker for updates after in-scope navigations, through `index.php`, which
starts a session. A check sent while the jar is empty or half-cleared
(Cypress deletes cookies one at a time) comes back with a new session
cookie, and only with what the request lacked: a passphrase present and a
session absent yields the session cookie alone — the stable33 shape.

**Reproduced** on the NC34 harness with a probe (Electron, first run): the
jar was cleared at 62859 ms, and at 63121 ms it held
`oc_sessionPassphrase` and the session cookie, set by a GET of
`preview-service-worker.js` that had gone out without a session.

**Readiness.** The OCS create answers 200 once the account exists, and
`RootCollection` calls `getUserFolder()`, which creates `/<uid>/files`
within the first authenticated WebDAV request. Nothing completes later. The
retry loop added on 2026-09-25 treated the same two symptoms — a 412 on the
first write and once the login page — as slow home setup.

## Implementation Plan

### Block 1: no service worker, one home check

1. `Cypress.on( 'window:before:load' )`: remove `serviceWorker` from the
   page's `Navigator.prototype`, so `'serviceWorker' in navigator` is false
   and Nextcloud's pages register none (Files, Photos, Notifications all
   test for it).
2. The support file's `before()`: unregister every registration for the
   origin, through the spec frame's own `navigator.serviceWorker`, for a
   profile an earlier run left one in (`cypress open` keeps its profile).
3. `warmHomeOf()` → one PROPFIND as the account, `expect` 207 with the
   status in the message; no recursion, no wait.
4. Comments: `cy.fciasMakeAccount()` and the home check say what the server
   does; the service-worker block says why it is there.

**Verification:**

- `npm run lint` on the changed file.
- The probe in `GUIDELINES/temp/probe/` against NC34: no registration, and
  the jar stays empty after it is cleared.
- `CYPRESS_baseUrl=https://nextcloud-34.ddev.site CYPRESS_occ="$HOME/projects/nextcloud_testing/scripts/nc-test 34 --env OC_PASS occ" npx cypress run --spec tests/e2e/duplicates.cy.js`,
  and the same against 33.

## Proposed commit message

```
[FIX] The e2e suite keeps Nextcloud's service worker out of its cookie jar

Nextcloud's Files service worker wrote session cookies into a jar a test had just cleared or filled, so a fresh account's first WebDAV write met the strict-cookie check and a login failed its CSRF check. No test page registers it now, and a fresh account's home is checked once rather than retried.

Key changes:
- `tests/e2e/support/e2e.js`: test pages load without `navigator.serviceWorker`, and each spec first
  unregisters any worker an earlier run left
- `cy.fciasMakeAccount`: one PROPFIND on the new account's root that must answer 207, in place of up to ten
- the comments that blamed slow home setup say what the server does: the account exists once the OCS
  call answers, and the first WebDAV request creates its home
- AP StrayCookie v1.0 registered with its one block
- No CHANGELOG bullet: tests/ and GUIDELINES/ only, outside the shipped paths

On CI the stable33 job's MKCOL carried a lone session cookie and was refused 412, and the stable34 job's login form came back with "Session error"; the OCS create had answered 200 and the PROPFIND 207, so the server was ready. Cypress clears cookies and the page between tests but leaves service workers registered, and the browser fetches the worker's script on its own, through index.php, which starts a session: a probe on the harness saw a cleared jar refilled that way a quarter of a second later. The retry added in "The e2e suite waits for a minted account's home and ignores a Chrome notification" read the same symptoms as slow home setup, and a retry on 412 or a CSRF failure would have been as blind. AP StrayCookie v1.0, Block 1.
```

Its `File-revision-v1` and `Co-Authored-By` trailers are added at the gate.

## Change History

- v1.0 (2026-11-04): first version.
