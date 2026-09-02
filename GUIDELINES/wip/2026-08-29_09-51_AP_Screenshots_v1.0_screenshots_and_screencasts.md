# AP Screenshots v1.0: Refresh screenshots and screencasts

> **Status: preliminary — not yet approved.** Analysis-only session; no capture has been made.
> Depends on: AP UserDocs v1.0 (in-app copy fixes should land first where they change visible text)
> and any UI-string fixes from AP CodeDocs/UserDocs — capture AFTER those merge, or the shots are
> stale on arrival. The e2e work (AP E2ETests) is independent.

## Discussion

- All five PNGs in `docs/Screenshots/` date from 2026-08-19/22 and predate the QuietStart/RuleBands
  overhaul. Two are outright wrong about the product; three are merely dated.
- The only shipped consumer today is the app-store listing: `appinfo/info.xml:30-44` lists all five
  as `<screenshot>` URLs (gitlab raw links on `master`). `README.md`, `docs/FAQ.md`,
  `docs/user-guide.md` and the in-app docs viewer embed **no** images at all — an opportunity, not
  only a repair.
- Existing set is dark theme. Recommendation: recapture the whole set in the **default (light)**
  theme — it is what a new evaluator sees — and keep the set internally consistent; a dark variant
  is optional extra work, not part of this AP's gate.
- The test instance (`~/projects/nextcloud_testing/instances/34`, https://nextcloud-34.ddev.site)
  currently carries leftover experimental rules (three `groupfolder:*` rules in band 6, one `*` rule
  with path `**sdfsd` in band 8; the two shipped defaults are absent). Every capture session must
  start from the reset recipe below.

## Analysis

### Current inventory (all in `docs/Screenshots/`, referenced from `appinfo/info.xml:30-44`)

| File | Size | Verdict | Why |
|---|---|---|---|
| `Admin-Settings.png` | 1300x1811 | **stale — misleading** | Shows the removed "Global Rule (priority 0)" panel, "User Scope: all", the old Additional Rules table (Priority/User/Path columns, separate Edit/Disable/Delete buttons), old Status block without Eroded/Background Jobs rows, "FAQ"-era tabs. None of this UI exists. |
| `User-Settings.png` | 1325x587 | **stale — misleading** | Tabs "Rules"/"FAQ" (now "Rules"/"Help"), flat table with Scope `all`, per-row Edit/Disable/Delete buttons; no bands, no read-only enforced/defaults context, no pen+menu. |
| `File-Detail-Pane.png` | 749x710 | dated but accurate | Sidebar Checksums tab is conceptually unchanged (hashes, Recalc buttons, Find duplicates). Missing: the refusal reason under Recalculate for excluded files. Recapture for theme consistency. |
| `Duplicates-Page.png` | 1478x852 | dated but accurate | Controls unchanged; Verify-hashes rate-limit stop message not shown (fine). Recapture for consistency. |
| `Unified_Search.png` | 902x514 | accurate | Behaviour unchanged. Recapture only for set consistency. |

### What the current product has that no capture shows

Banded rules table with band headers and `<band>.<position>`, defaults partition, placeholder
"not covered" rows (`tr[data-placeholder]`), idle banner (`#fcias-idle-banner`), rule dialog with
kind-then-target pickers and live band preview, row pen icon + `NcActions` menu (Edit / Enable-
Disable / Re-apply / Delete), "provider missing" badge (`.fcias-provider-missing`), group-folder
naming "Team Folders: Team Docs (#1)", status page *Status Info* with Eroded Hashes and Background
Jobs heartbeat rows, personal page's read-only bands, drag-reorder confined to segment+partition.

### Instance state baseline (reset recipe, run before any capture session)

```bash
cd ~/projects/nextcloud_testing/instances/34
# 1. wipe leftover rules (list ids, delete each)
ddev exec php occ fcias:rules:list --output=json   # or default table
ddev exec php occ fcias:rules:delete <id>          # for every listed id
# 2. recreate the two shipped defaults, disabled
ddev exec php occ maintenance:repair
ddev exec php occ fcias:rules:list                 # expect: home:* + ** (band 7, disabled), * + ** (band 8, disabled)
# 3. group-folder membership for capture 10 (one-time)
ddev exec php occ group:add designers
ddev exec php occ group:adduser designers alice
ddev exec php occ group:adduser designers bob
ddev exec php occ groupfolders:group 2 designers write   # "Design Assets"
# 4. rebuild bundle so the served js/ matches the branch
npm run build   # in the app repo (live-mounted)
```

Users already present: `admin`/`admin`, `alice`/`SecretPass123!`, `bob`/`SecretPass123!`;
group folders 1 "Team Docs", 2 "Design Assets"; alice shares `/Photos` with bob (bob sees
`RenamedPhotos`). Content for duplicates/sidebar shots: upload the same small file twice into
alice's `/Documents` and `/` (as the old shots did), then enable the `home:*` default and run
`ddev exec php occ fcias:hash --mark && ddev exec php occ background:job:execute-all` or simply
`occ fcias:hash -a sha1 -a md5` to populate hashes.

## Implementation Plan

Viewport for all browser captures: 1440x900 window, default light theme, English (en) locale,
admin sidebar collapsed where irrelevant. Crop to the content column as the old shots did.
Naming: keep existing five filenames (info.xml URLs stay valid) and add new ones; all under
`docs/Screenshots/`.

### Block 1 — repair the two wrong app-store shots

1. `Admin-Settings.png` (replace): admin logged in, Administration settings → File Checksum Index &
   Search. State: reset recipe + BOTH shipped defaults still disabled is wrong for this shot — enable
   `home:*` default, add one `home:alice` rule (`/Photos/**`, sha256) and one `groupfolder:1` rule
   (`**`, sha1, enforced) so bands 2, 5, 7 and 8 plus at least one placeholder row are visible.
   On screen: Status Info block (Eroded Hashes, Background Jobs, Last Updated), permission section,
   banded table with band headers, `<band>.<position>` text, pen + menu column, a
   `tr[data-placeholder]` "not covered" row with its Create rule button.
2. `User-Settings.png` (replace): alice logged in, Personal settings → the app's section. State as
   in step 1 plus one rule alice owns (`home:alice`, `/Photos/**`). On screen: read-only enforced
   band above, alice's own editable segment, defaults below, Help tab visible.

**Verification:** open both PNGs; confirm no "Global Rule", no "User Scope", band headers legible at
100%; `appinfo/info.xml` URLs unchanged and resolving after push.

### Block 2 — new captures for the current model

3. `Admin-Idle-Banner.png`: reset state, both defaults disabled → `#fcias-idle-banner` visible with
   Acknowledged/Close. Crop to banner + table top.
4. `Rule-Dialog.png`: dialog open from a placeholder row's Create rule for `groupfolder:2` — shows
   kind select ("A group folder"), the Team Folders picker open with "Design Assets (#2)", live band
   preview, verdict select, help popover buttons. One extra variant `Rule-Dialog-Band-Preview.png`
   optional.
5. `Rule-Row-Menu.png`: admin table, one row's `NcActions` menu open showing Edit / Disable /
   Re-apply / Delete with icons, pen icon beside the trigger.
6. `Status-Info.png`: after one sweep + drain have run (`occ background:job:execute-all` or wait),
   so Background Jobs shows real heartbeats and counts; Eroded Hashes non-zero if feasible
   (edit a file covered by no enabled rule after it had hashes).
7. `Provider-Missing.png`: create a rule `groupfolder:99` via
   `ddev exec php occ fcias:rules:add --selector groupfolder:99 --path '**' ...` (id 99 does not
   exist) → row shows `.fcias-provider-missing` badge. Delete the rule after capture. (Preferred
   over disabling the groupfolders app, which would disturb other captures.)
8. `Drag-Reorder.webm` (screencast, 10-15 s, 1440x900, no audio): admin table with >= 3 rules in one
   segment; drag a rule within its segment (accepted), then attempt to drag past the segment's
   default (refused/snaps back). Export webm + a GIF fallback `Drag-Reorder.gif` if the README
   embeds it (GitHub does not play webm inline).

**Verification:** each file exists, < 1 MB (screencast < 5 MB), light theme, no personal data beyond
the seeded demo users; filenames exactly as listed.

### Block 3 — recapture the three dated shots (set consistency)

9. `File-Detail-Pane.png`: alice, Files → a hashed file → Checksums tab; hashes + Recalc buttons +
   Find duplicates visible. Optional second shot `File-Detail-Pane-Excluded.png` with an `exclude`
   rule covering the file, showing the refusal reason under the buttons.
10. `Duplicates-Page.png`: admin or alice, the Duplicates page with >= 2 groups (the duplicate file
    seeded above).
11. `Unified_Search.png`: unified search with a real md5 from the seeded data; File Checksums
    section with two results.

**Verification:** visual pass over the full set side by side — one theme, one viewport family,
consistent chrome.

### Block 4 — wire the new captures into the docs

12. `appinfo/info.xml`: reorder `<screenshot>` list to lead with the new admin table shot; add
    `Admin-Idle-Banner.png` and `Rule-Dialog.png`; keep total at 5-7 entries. (Shipped-code path →
    CHANGELOG bullet required.)
13. `README.md`: embed `Admin-Settings.png` in the rules chapter and `File-Detail-Pane.png` near the
    sidebar description; `docs/user-guide.md`: embed `User-Settings.png` and
    `File-Detail-Pane.png`; `docs/FAQ.md`: embed `Status-Info.png` in the status/erosion answer.
    Relative paths must work both on GitLab and in the in-app docs viewer — verify how
    `PageController::getDocs()`/`getHelp()` (lib/Controller/PageController.php:66-129) serve image
    URLs before choosing the path form; if the viewer cannot serve images, embed only in README and
    note it.
14. Delete nothing: all previous filenames remain in use.

**Verification:** `git status` shows only `docs/Screenshots/*`, `appinfo/info.xml`, the three
markdown files; markdown image links resolve locally; in-app Documentation tab renders without
broken images (or the limitation is recorded in the AP before the gate).

## Proposed commit message

```
[TASK] Refresh the screenshot set for the banded-rules UI

The five app-store screenshots predate the selector model: two showed
removed UI (the Global Rule panel, the flat personal rules table) and
none showed the banded table, the idle banner, the rule dialog, the
status heartbeats or the provider-missing badge. All five are
recaptured in the default theme and joined by captures of the new
surfaces plus a short drag-reorder screencast; info.xml leads with the
banded table and the README/user guide/FAQ embed the shots where the
matching text is.
```

## Change History

- v1.0 (2026-08-29): initial preliminary plan, written from the analysis-only session
  (see the consolidated gap analysis of the same timestamp in `GUIDELINES/messages/`
  — untracked, not citable from anything that outlives this AP).
