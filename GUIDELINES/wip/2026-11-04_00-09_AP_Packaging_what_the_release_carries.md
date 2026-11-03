# AP Packaging v1.0: What the release carries

## TL;DR

The in-app documents' screenshots never load: they come from gitlab.com,
which the server's image policy refuses.
They come from the app itself instead.
The release then carries only what the app reads,
and the phar's directory stays out of git status.

- in-app pictures: relative links in the documents;
  `readDocs()` → the app's web path (`IURLGenerator::linkTo()`) (S1-A)
- the markdown `RELEASE-PIN` blocks and the guide's
  "load from the project's site" go; `info.xml`'s pin stays
- `docs/Screenshots/` ships whole (S2-A)
- `.nc.publish.ignore`: `+ /CLAUDE.md`, `+ /docs/branding` (P1-A)
- `bamarni/composer-bin-plugin` `require` → `require-dev`:
  the release's `vendor/` → Composer's autoloader alone (P2-A)
- `.gitignore`: `/vendor-bin/strauss/`; LFP's session told (P3-A)

### Blocks

1. The in-app pictures: the rewrite and its test, the documents, an e2e check, the CHANGELOG
2. The release's contents: the ignore file and the plugin; the tarball listed and installed on `34-upd`
3. The phar's directory: the ignore rule; LFP's session told

## Discussion

- **Origin.**
  The user's `PLAN packaging` after the AppUpdate run, which saw the release ship
  `CLAUDE.md` and the bin plugin; the user's `EVAL serving the screenshots from the app dir`;
  then `PLAN++ as recommended`, 2026-11-04.
- **Decided at the EVAL gate of 2026-11-04,** as recommended:
  - S1-A: the in-app documents take the screenshots from the app.
    Not B, gitlab.com allowed in the pages' image policy:
    every reader's browser would ask a third party, and nothing loads offline.
  - S2-A: the whole `docs/Screenshots/`, 17 files, 1.9 MB:
    one rule, nothing to keep in step with the documents.
  - P1-A: `CLAUDE.md` and `docs/branding/` leave the release; the screenshots stay.
  - P2-A: the bin plugin moves to `require-dev`, as LFP has it.
  - P3-A: `/vendor-bin/strauss/` ignored; the scripts stay as LFP's.
- **Left out:** `package.sh` skipping `composer install` for an app with no
  runtime dependencies, which would drop the empty autoloader too:
  it is the guidelines' shared copy, changed there or not at all.

## Analysis

- **Why the pictures never load.**
  NcRichText renders `![…][ref]` as an `<img>`;
  Nextcloud's default policy is `img-src 'self' data: blob:`
  (the harness's 34 adds OpenStreetMap's tiles), and the app adds no domain.
  The eight shots the README, the user guide and the FAQ show, from
  `gitlab.com/…/-/raw/v0.30.0/docs/Screenshots/…`, are refused.
  The guide's line 44 blames the connection.
- **The rewrite.**
  `readDocs()` already reads each document from the app's directory.
  For a markdown file, a reference definition or an inline image whose target is relative
  and names an image under the app's directory, resolved from the document's own directory,
  becomes `IURLGenerator::linkTo( 'file_checksum_search', <path> )`:
  the app's web path, `apps/` or `custom_apps/`, never `index.php`.
  Links to documents, anchors and absolute URLs stay as they are.
- **The documents.**
  `README.md` links `docs/Screenshots/X.png`, `docs/user-guide.md` and `docs/FAQ.md`
  link `Screenshots/X.png`, which GitLab and GitHub render as they do now.
  The three markdown `RELEASE-PIN` blocks go; `changelog.sh cut` rewrites whatever pins it finds,
  so it keeps `info.xml`'s, which the store needs absolute.
- **Static serving.**
  The web server serves an app's PNGs as it serves its `img/`;
  `'self'` covers them.
- **The release's contents** (0.30.0's tarball, 7.2 MB):
  `CLAUDE.md`; `docs/branding/`, 4 files nothing reads;
  `vendor/`, 237 files, 1.5 MB, the bin plugin with its e2e scenarios and Composer's autoloader,
  which nothing loads: the server loads `lib/` by PSR-4.
  The plugin sits in `require` since `[TASK] Move PHPUnit to vendor-bin, add openapi-extractor vendor-bin`,
  for no stated reason; CI installs the development packages, so `composer bin` still works there.
- **The phar's directory.**
  `composer install` and `update` end in `composer bin all install`,
  which takes every directory under `vendor-bin/` for a namespace
  and leaves `composer.json`, `composer.lock` and `vendor/` in `vendor-bin/strauss/`.
  LFP's setup is the same.
- **CHANGELOG.**
  Block 1 touches `lib/`: a bullet under `### Fixed`, the defect being 0.30.0's.
  Blocks 2 and 3 touch top-level files only, outside the shipped paths.

## Implementation Plan

### Block 1: The in-app pictures

1. `PageController`: `IURLGenerator` injected; `readDocs()` rewrites a markdown document's
   relative image targets, as above.
2. `tests/Unit/Controller/PageControllerTest.php`: a reference definition, an inline image,
   one from `README.md` and one from `docs/`, a link to a document, an absolute URL and a missing file.
3. `README.md`, `docs/user-guide.md`, `docs/FAQ.md`: relative definitions,
   the pin blocks gone; the guide's lines 44-45 gone.
4. `tests/e2e/duplicates.cy.js`: on the Help tab, the guide's five images load.
5. `CHANGELOG.md` `### Fixed`: the bullet.

**Verification:** unit suite on 34 and 33, ECS, Psalm;
`duplicates.cy.js` on 34; `changelog.sh check`; `tools/check`.

### Block 2: The release's contents

1. `.nc.publish.ignore`: `/CLAUDE.md` beside `/AGENTS.md`, `/docs/branding`.
2. `composer.json`: the plugin in `require-dev`; `composer update --lock`.
3. `package.sh`: the tarball listed.
4. `34-upd`: the tarball installed into `custom_apps/`, enabled, `occ fcias:status`,
   a document's image fetched; the app removed, the instance stopped.

**Verification:** no `CLAUDE.md`, `docs/branding/` or `vendor/bamarni/` in the tarball;
`docs/Screenshots/` in it; the install's status and image answer; `composer install`, `composer bin all install` and the unit suite as before.

### Block 3: The phar's directory

1. `.gitignore`: `/vendor-bin/strauss/`.
2. `composer install`: `git status` clean.
3. LFP's session told, with the line.

**Verification:** `git status` clean after `composer install`; the message sent.

## Proposed commit messages

- `[UPDATE] APs: +Packaging v1.0`
- `[FIX] The in-app guide, FAQ and README show their screenshots, served by the app`
  (`AP Packaging v1.0, Block 1`)
- `[TASK] The release leaves out CLAUDE.md, the branding sources and the bin plugin`
  (`AP Packaging v1.0, Block 2`)
- `[UPDATE] The Strauss phar's directory stays out of git status`
  (`AP Packaging v1.0, Block 3`)
- `[UPDATE] APs: -Packaging`, offered at the completion gate

## Change History

- v1.0: the plan, from the user's `PLAN++ as recommended` on the EVAL gate of 2026-11-04,
  with S1-A, S2-A, P1-A, P2-A and P3-A.
