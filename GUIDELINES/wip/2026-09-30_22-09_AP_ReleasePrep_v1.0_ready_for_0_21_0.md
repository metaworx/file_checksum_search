# AP ReleasePrep v1.0: ready for 0.21.0

## Discussion

- **The ask.** The user, 2026-09-30: review the work since v0.20.3 before a
  release, then make it ready to cut.
- **The reviews.** They are ANALYSIS ReleaseReview v1.0, beside this plan.
  Its finding numbers are kept here: B backend, F frontend, D documentation,
  T translations.
- **Already done:**
  - the CHANGELOG's merged bullets, the checksum index check's name and
    `defaultAlgo` ("[TASK] The changelog reads as the net change since
    v0.20.3");
  - the e2e README ("[TASK] The e2e README runs occ through the harness").

## Analysis

- **No logic defect was found.** What stands in the way of the release:
  - texts that are still English;
  - an OpenAPI file behind the API;
  - a compatibility promise the `ChecksumApi` break contradicts;
  - one badge with the contrast defect already fixed elsewhere;
  - stale names;
  - a few CHANGELOG bullets.
- **Translations:** all eleven languages were checked, with no meaning defect
  in any, and seventeen small term and style points (T1 to T17).
- **Every new text** needs its translation in all eleven languages.
  `scripts/l10n.sh check` fails otherwise.
- **Out of scope, left for later:** the "can wait" list of the analysis, and
  the `GROUP_CONCAT` limit, which is pre-existing and wants an issue.
- **The version** is 0.21.0. The `ChecksumApi` break is incompatible, which
  below 1.0 is a minor.

## Implementation Plan

### Block 1: the server's messages

1. **B1.** Every server message the rules pages show goes through `IL10N`:
   - in `RulesController`: the default `forbidden()` text, "Invalid request
     body.", the scope refusals and the reorder errors;
   - `RuleService`'s permutation and unknown-mode messages;
   - `Selector`'s validation messages, which get an `IL10N` as
     `RuleDefinitionValidator` has;
   - `ChecksumApi`'s "No rule with ID".
2. **B4.** `RuleService::assertApplicable()` separates an English `reason`
   for `ApplyRuleJob`'s log from the translated message the controller
   shows, as `HashCalculationService` does.
3. **B6.** The repair message says "→ Advanced → Status", and its test with
   it.
4. **D13.** The console's job label is "Checksum index check", and the
   README clause that explained the old name goes.
5. **Tests.** A test L10n that marks what it translates, so a spec can tell
   a translated text from an English one. With it: each message above; the
   log keeps English; the repair text.
6. **Translations.** `scripts/l10n.sh update`, and the new texts in all
   eleven languages, from each glossary. The German is written for review by
   the user; the others are checked by a reviewer agent.

**Verification:** the PHPUnit suites (unit and integration), `composer
cs:check`, `scripts/l10n.sh check`, `msgfmt`.
**CHANGELOG:** the Fixed bullet on translatable texts is amended if it no
longer covers the change.

### Block 2: the frontend

1. **F4.** The background-job counters are translated texts with a
   placeholder per key, and `done` becomes a word or is left out.
   `App.spec.ts` follows.
2. **F1.** The "folder missing" badge's text is `--color-warning-text`.
3. **F2.** The rule-type badges' text is `--color-warning-text`.
4. **F3.** The Others tab's "Show empty files" label is
   `--color-warning-text`.
5. **Translations** for any new text, as in Block 1.

**Verification:** Vitest, `npm run typecheck`, ESLint, Stylelint, the build,
`scripts/l10n.sh check`. The badges are checked in the light and the dark
theme on instance 34.
**CHANGELOG:** the Fixed bullet on status labels names the badges.

### Block 3: the documentation

1. **B2, D1.** In `docs/api-v1-openapi.yaml`:
   - `includeEmpty` on `/api/v1/duplicates` and `/api/v1/sudo/duplicates`;
   - `empty: boolean` on `DuplicateGroup`;
   - the example hash `aaf4c61d…`.
2. **In `docs/api-v1.md`:**
   - **B3, D10:** the compatibility table's row for a reordered or required
     PHP parameter, and the pre-1.0 note;
   - **D11:** "`error` is for display, in the caller's language; match on
     the status, never the text";
   - **D8:** "Checksum algorithms";
   - **D9:** account, team folder, come before, group admin.
3. **README.md:**
   - **D2:** the REST row with `minCount`, `hash`, `anywhere` and
     `includeEmpty`;
   - **D5:** the empty-files switch in the Duplicate File Browser, and
     `--include-empty` in the `find-duplicates` row.
4. **docs/FAQ.md:**
   - **D6:** the five background jobs;
   - **D7:** empty files left out by default.
5. **docs/user-guide.md, D14:** a file link opens its folder, with the
   file's details.

**Verification:** the OpenAPI file parses; every name cited in the
documentation exists in the code or the template.
**CHANGELOG:** none, no shipped path.

### Block 4: translations

1. **T1 to T17,** as the analysis lists them, each changed with Edit on its
   own entry.
2. **T3, T6:** the German and Japanese glossaries give "pending deletion" as
   the texts have it.
3. **T4, D21:** the `de_DE` header says `Language: de_DE`.
4. **D20:** six manifest translations say "hold", not "own": de, fr, it,
   nl, pl, zh-hans. Each is written as `&#160;` where the text has a
   no-break space.
5. **Checks:**
   - `scripts/l10n.sh update` and `build`;
   - `msgfmt` on every file;
   - the no-break spaces counted before and after, in every `.po` and in
     `info.xml`'s parsed text;
   - the manifest schema.

**CHANGELOG:** the translations bullet if it no longer covers the change.

### Block 5: the CHANGELOG's remaining corrections (D12)

- the new-rule fix says the placeholder row's "Add rule", not "Create rule";
- the empty-files bullet also names `GET /api/v1/sudo/duplicates`, `occ
  find-duplicates` (whose default output changes) and the per-file route's
  `empty`;
- the `ChecksumApi` bullet says a call that omitted `$reachUids`, or passed
  later arguments by position, breaks;
- Added: `canRecalc` on both duplicates listings;
- Changed: the API's `error` texts are reworded and in the caller's
  language.

**Verification:** `changelog.sh check`.

### Block 6: the release

1. **The full test gate:**
   - the PHPUnit unit and integration suites on NC 33 and 34;
   - Vitest, `npm run typecheck`, ESLint, Stylelint, `composer cs:check`;
   - `scripts/l10n.sh check`, the manifest schema;
   - the e2e suite on NC 34, through `nc-test 34 occ`.

   Anything that fails stops the release.
2. **Retire this plan,** in a commit of its own, before the release.
3. **`[RELEASE] v0.21.0`** with `changelog.sh cut`: the manifest's version,
   the `RELEASE-PIN` blocks, the CHANGELOG section. Then the signed tag,
   whose message is the release notes. Its own gate.

## Proposed commit messages

1. `[FIX] Every message the rules pages show is translated`
2. `[FIX] The admin page's job counters are translated, and its badges readable`
3. `[TASK] The API documentation says what the API does`
4. `[FIX] The translations follow their glossaries`
5. `[TASK] The changelog names every change since v0.20.3`
6. `[UPDATE] APs: -ReleasePrep`, then `[RELEASE] v0.21.0`

Each in full at its gate.

## Change History

- v1.0 (2026-09-30): first version.
