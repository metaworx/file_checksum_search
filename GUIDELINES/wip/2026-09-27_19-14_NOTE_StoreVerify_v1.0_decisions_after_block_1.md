# NOTE StoreVerify v1.0: decisions after block 1

Accompanies `2026-09-27_18-22_AP_StoreVerify_v1.0_publish_behind_review_verify_from_the_store.md`,
which stays as written. Where the two differ, this note holds.

## Block 2 dropped

The pinned commit of `nextcloud/setup-server-action` (the commit "The setup
action pinned to its Node 24 commit, and the suite watches for its next
release") removes the Node 20 warning, and the test workflow's `upstream`
job says when a release makes the pin replaceable. Replacing the action is
not worth a block. The steps our jobs repeat after it — the e2e settings,
copying the app in, starting the web server — move into a local composite
action in block 4, where a third copy would otherwise appear; it keeps
calling the pinned setup action.

## Block 3: the environment, rules undecided

The store job runs in the `appstore` environment either way. Whether it
waits for a required reviewer, a wait timer, or nothing is the
environment's configuration, not the workflow's, and stays the
maintainer's to set. `APPSTORE_PUBLISH` is no longer read.

## Block 4: what the post-publish suite checks

- **Both install modes for every release**, stable and nightly: `occ
  app:install`, and the Apps page's Download and enable.
- **Only for a nightly:** `occ` gets `--allow-unstable`, and the Apps-page
  variant first sets `updater.release.channel` to `daily`. A stable release
  runs both on a server left as installed. `beta` is not enough: the
  server's app fetcher admits nightlies only on `daily` or `git`
  (`AppFetcher::fetch()`, stable34).
- **A smoke test, not the full e2e suite:** the sidebar tab, the Duplicates
  page, search by hash.
- **The install steps checked**, after each install: the app's migrations
  recorded in `oc_migrations`, the indices they create present,
  `installed_version` equal to the release, the two default rules created,
  the background jobs registered, and the filecache copy queued.
