# AP AppUpdate v1.0: The app's own update command

## TL;DR

`occ fcias:update` updates the app from the store, nightlies included,
through `mwx/nextcloud-app` 0.1.1, as `lfp:update` does in Large File Previews.
The package is scoped into a tracked `lib/Vendor/` by Strauss,
and the server's own installer downloads, verifies and installs.

- `lib/Command/Update.php`: `file-checksum-search:update`, alias `fcias:update`;
  `--check`, `--stable|--nightly`, `--output`; exit 0, 1, 2
- `lib/Vendor/`: the package under `OCA\FileChecksumSearch\Vendor\`;
  `composer vendor:scope`, `vendor:check`; CI fails on drift
- `OC\Installer`, the one internal class: named in `psalm.xml`'s inventory
  comment (U1-A), pinned by an integration test
- ECS and Psalm leave `lib/Vendor/` alone
- `scripts/fcias` stays, as it is (U2-B)
- `-o` comes with the library's 0.1.2, in a later update
- proven on the harness's `34-upd`, never on the bind-mounted `34`

### Blocks

1. The command: the vendored package, `Update.php`, its tests, Psalm and ECS, CI, the contract, the CHANGELOG
2. The README and the FAQ: installing and updating, the CLI reference, 24 commands
3. On the harness: a real update on `34-upd`, recorded in a NOTE

## Discussion

- **Origin.**
  The library session published `mwx/nextcloud-app` 0.1.1 on 2026-11-03,
  and Large File Previews took it the same day,
  in `[TASK] lfp:update: the app updates itself from the store, nightlies included`
  and `[UPDATE] mwx/nextcloud-app 0.1.1: the command loads what it needs before the installer swaps the files`,
  the worked example here.
  The user's `PLAN update` asked for the survey, and `PLAN++ update U2-B` for this plan.
- **Why a command of the app's own.**
  A release below 1.0.0 reaches the store as a nightly
  (`docs/app-store-publishing.md`).
  The Apps page never offers one, and `occ app:update --allow-unstable` reads the
  fetcher's cached list, filtered for the stable channel, until the store's ETag changes.
  The command clears that cache and the fetcher's failure hold,
  then lets `OC\Installer` take the release.
- **Decided at the TL;DR gate of 2026-11-03:**
  - U1-A, as recommended: `OC\Installer` is listed in `psalm.xml`'s inventory comment,
    where this app keeps its non-public server classes, with its pin test named;
    no contract section.
    Psalm needs no suppression: the package names the class as a string.
  - U2-B, the user's choice: `scripts/fcias` stays as a fallback, unchanged.
    It reads the store's platform list, which applies the Nextcloud maximum
    the server ignores (LFP's finding); a fix to it is not part of this plan.
- **The `-o` shortcut.**
  The package's `--output` has none, and every other command here takes `-o`.
  The library accepted a hook, `outputShortcut()`, that defaults to `o`
  and a subclass can turn off; it ships as 0.1.2.
  This plan vendors 0.1.1; taking 0.1.2 is a later `[UPDATE]` commit.
- **No maintenance mode**, as the Apps page and `occ app:update` set none:
  the request finishes on classes already loaded, which 0.1.1 preloads.

## Analysis

- **The copy from LFP**, the names changed:
  - `composer.json`: `mwx/nextcloud-app ^0.1.1` in `require-dev`;
    `vendor:scope` (the phar held to its SHA-256, Strauss, `scripts/vendor-scope.php`)
    and `vendor:check` (`vendor:scope`, then no change under `lib/Vendor`);
    `extra.strauss` with `namespace_prefix` `OCA\FileChecksumSearch\Vendor\`,
    `classmap_prefix` `FCIAS_Vendor_`, `packages` naming the package alone,
    since `bamarni/composer-bin-plugin` is in `require` here,
    and LFP's `exclude_from_copy`.
  - `scripts/vendor-scope.php`: places the classes by PSR-4 and keeps the package's MIT licence.
  - `lib/Command/Update.php` over the scoped `UpdateCommand`; `appinfo/info.xml`.
  - `tests/Unit/Command/UpdateTest.php`: the name, the alias, the app id.
  - `tests/Integration/InstallerPinTest.php`: `OC\Installer` resolves from the container,
    and its two methods accept what the package's `Installer` declares,
    on 33 and 34; on this app's `DatabaseTestCase` or a plain `TestCase`, whichever its setup needs.
  - `ecs.php` skips `lib/Vendor`; `psalm.xml` ignores it and scans it.
  - `.github/workflows/test.yml`'s Lint job: `composer vendor:check` after ECS.
- **The phar.**
  Strauss 0.31.1, 11.5 MB from GitHub;
  LFP's checkout holds it under `vendor-bin/strauss/`, its SHA-256 the pin's,
  so it is copied, not fetched. CI fetches its own.
- **Size.**
  About 2,000 lines, about 1,400 of them `lib/Vendor/`.
  `package.sh` ships `lib/`, so the release carries the package;
  `vendor-bin/` and `scripts/` stay out (`.nc.publish.ignore`).
- **The contract.** The facts table's lint row names `composer vendor:check`,
  and says ECS and Psalm leave `lib/Vendor/` to Strauss.
- **Documents.** The README's *Installation* becomes *Installing and updating*:
  `--allow-unstable` while releases are nightlies, then the command.
  The CLI reference gains its row, and the count becomes 24;
  the FAQ answers why the Apps page offers no update.
- **The harness.** On `34` the app is this checkout, bind-mounted,
  and the installer replaces the app's directory.
  `34-upd` is the second Nextcloud 34 LFP's run set up for this:
  the app store enabled, `custom_apps/` writable.
  This app goes there from `package.sh`'s tarball, its version set below the store's
  newest, 0.30.0, and the runs are LFP's:
  `--check`, `--stable`, `--nightly --check`, `--nightly`.
  The instance is started for the runs and stopped after.

## Implementation Plan

### Block 1: The command

1. `composer.json` as above; `composer update mwx/nextcloud-app`; `composer.lock`.
2. The phar copied from LFP's checkout; `scripts/vendor-scope.php`;
   `composer vendor:scope`; `lib/Vendor/` added.
3. `lib/Command/Update.php`; `appinfo/info.xml`.
4. `tests/Unit/Command/UpdateTest.php`; `tests/Integration/InstallerPinTest.php`.
5. `psalm.xml`: `lib/Vendor` ignored and scanned; the inventory comment names `OC\Installer` and its pin.
   `ecs.php`: `lib/Vendor` skipped.
6. `.github/workflows/test.yml`: the Lint job's `composer vendor:check`.
7. Contract §1, the lint row; `sync-docs.sh`.
8. `CHANGELOG.md` `### Added`: the bullet.

**Verification:** unit and integration suites on 34 and 33,
`composer cs:check`, `composer psalm`, `composer vendor:check` clean,
`occ fcias:update --check` on `34` answering exit 2 with the reason;
`changelog.sh check`; `tools/check`.

### Block 2: The README and the FAQ

1. README: *Installing and updating*; the command's row and section in the CLI reference;
   24 commands in the features line and the reference's introduction.
2. `docs/FAQ.md`: why the Apps page offers no update, and the command.

**Verification:** `tools/check`; the links resolve.

### Block 3: On the harness

1. `34-upd` started; `package.sh`'s tarball, its `info.xml` and `installed_version` set to 0.29.9,
   into `custom_apps/`; the app enabled.
2. `occ fcias:update --check`, `--stable`, `--nightly --check`, and `--check --output json`:
   the output and exit codes.
3. `occ fcias:update --nightly`: the store's 0.30.0 in place, exit 0; `app:list` confirms it.
4. The app removed from `34-upd`, which is stopped.

**Verification:** the runs recorded in a NOTE, committed.

## Proposed commit messages

- `[UPDATE] APs: +AppUpdate v1.0`
- `[TASK] occ fcias:update: the app updates itself from the store, nightlies included`
  (`AP AppUpdate v1.0, Block 1`)
- `[TASK] The README and the FAQ describe occ fcias:update`
  (`AP AppUpdate v1.0, Block 2`)
- `[UPDATE] APs: +NOTE AppUpdate v1.0` for Block 3's runs
- `[UPDATE] APs: -AppUpdate`, offered at the completion gate

## Change History

- v1.0: the plan, from the user's `PLAN++ update U2-B` on the TL;DR gate of 2026-11-03,
  with U1-A; the library asked for the `-o` hook.
