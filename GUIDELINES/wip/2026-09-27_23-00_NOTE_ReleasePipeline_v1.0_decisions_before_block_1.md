# NOTE ReleasePipeline v1.0: decisions before block 1

Accompanies `2026-09-27_22-31_AP_ReleasePipeline_v1.0_sign_verify_publish_confirm.md`,
which stays as written. Where the two differ, this note holds.

## A step before block 1: the workflows' repeated blocks become actions

The steps the workflows repeat move into composite actions under
`.github/actions/`, and `test.yml` and the post-publish suite are switched
to them as a pure restructuring, before the plan's blocks rewrite the
release workflows on top of them:

- `nextcloud-server` — the server at a branch and PHP version, with the
  e2e settings as an option (replaces `nextcloud-e2e`; the one place the
  setup action's pin lives);
- `node-deps` — Node.js 24 with npm's cache, and `npm ci`;
- `app-into-server` — the checkout copied into the server and enabled,
  with the dev dependencies for PHPUnit or the built frontend for Cypress;
- `nextcloud-serve` — the web server on a given port, a port below 1024
  through the capability rather than sudo;
- `cypress-run` — the specs in Chrome and the screenshots kept on failure.

The actions the later blocks need — the release's version and kind, the
install from a store, the store listing — are written with those blocks.

The database stays a service in each job: a composite action cannot
declare one, and starting MariaDB inside an action would hide a moving
part. In `test.yml` a YAML anchor keeps one definition for its two jobs,
and another the one-run-per-release condition for its four; GitHub
accepts anchors in workflow files since 2025, which the first run
confirms.

## Block 3: the fake store serves the real listing

The fake store is a small proxy in front of the real store, not a copy of
one app's entry:

- it downloads the full `api/v1/apps.json` once per job, the file every
  server reads (the platform listing is the store's pre-filtered answer:
  492 apps and 2,840 releases for 34.0.3 against 833 and 17,191, and a
  server never reads it);
- it changes only this app's entry, putting the new release first in its
  release list with the GitHub asset, the signature and the nightly flag;
  the certificate and every other app stay as the store has them;
- every other request — categories, the AppAPI list, Discover, icons —
  passes through to the real store.

The mirrors' lag does not matter here: the one entry it could affect is
the one replaced.

## Block 3: the Apps page reached as an administrator reaches it

The spec opens the Files category, scrolls the long list to this app's
row, opens it, and clicks Download and enable. The direct URL remains only
to confirm the sidebar shows the version under test.
