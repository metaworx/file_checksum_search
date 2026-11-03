# NOTE AppUpdate v1.0: the runs on the harness

Block 3's runs, 2026-11-03, and where they went differently from the plan.

## Where

`34-upd`, Nextcloud 34.0.5, PHP 8.2, the app store enabled,
`custom_apps/` its one writable apps directory, Large File Previews 0.3.0 beside it.
The app came from `package.sh`'s tarball of the commit
`[TASK] The README and the FAQ describe occ fcias:update`,
extracted into `custom_apps/`, its `info.xml` set to 0.29.9 and enabled;
`app:enable` recorded `installed_version` 0.29.9 itself, the app being new there.
On the harness's `34`, `occ fcias:update --check` answers exit 2 with the reason:
the harness disables the app store.

## The runs (`occ fcias:update …` on `34-upd`)

The store lists 0.30.0 as a nightly only: newest stable `none`,
newest unstable `0.30.0 (nightly)`, compatible (`>=33 <=35`, PHP `>=8.2`).

1. **`--check`:** installed `0.29.9 (stable)`, the version not in the store;
   channel `stable (the installed kind)`;
   `nothing to take: no compatible stable release above 0.29.9`; exit 1.
2. **`--stable`:** channel `stable (the flag)`; the same outcome; exit 1.
3. **`--nightly --check`:** chosen `0.30.0 (nightly)`, server offers `0.30.0`,
   `would update to 0.30.0 (--check: nothing changed)`; exit 0.
4. **`--check --output json`:** run 1 as one object,
   `"outcome":{"status":"nothing",…,"exitCode":1}`; exit 1.
5. **`--nightly`:** `Outcome: updated to 0.30.0`, exit 0;
   `app:list` says 0.30.0, `needsDbUpgrade: false`,
   and the directory holds the store's copy, which has no `lib/Vendor/`:
   the package's preload carried the command through the swap.
6. **`--nightly` again:** `Command "fcias:update" is not defined`, exit 1:
   the store's 0.30.0 predates the command.

Afterwards the app was removed from `34-upd`, and the instance stopped.

## Where it went differently

- The plan named 0.30.0 the store's newest without saying of which kind;
  it is a nightly only, so an install whose version the store does not list
  reads as stable and takes nothing without `--nightly`.
  An install of a version the store lists as a nightly reads as one,
  and takes nightlies unflagged, as Large File Previews' runs measured.
- Run 6 was not in the plan: until a release carrying the command reaches the store,
  an update by it is the last one it makes.
