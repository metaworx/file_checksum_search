# ANALYSIS ReleaseReview v1.0: before cutting 0.21.0

> **2026-09-30.** Three read-only reviews of everything since v0.20.3
> (64 commits), asked for by the user before a release: the backend, the
> frontend, and the documentation, manifest and translations. Their findings
> are sorted below into what should be fixed before the release, what is
> cheap to do in the same pass, and what can wait. Each finding keeps its
> reviewer's number (B = backend, F = frontend, D = documentation). The
> CHANGELOG corrections already in the working tree are marked as such.

## Summary

**The reviews found no logic defect in the new code.** Confirmed as correct:
- the empty-file exclusion, and its IQueryBuilder-only SQL;
- the 63-character index rule;
- `canRecalc` and `defaultAlgo`;
- the required reach in `ChecksumApi`;
- the queued repair steps;
- file links through core's `/f/{fileid}`;
- dates in the user's locale;
- the status-label colours;
- every frontend text in the translation template, in a form the tool reads.

**Checks that pass:** Vitest (274), `npm run typecheck`, ESLint, Stylelint,
`scripts/l10n.sh check` (358 texts, eleven languages), `msgfmt` on all eleven
files, the manifest schema, and `php -l` on every changed PHP file.

**Not yet ready to cut.** Some texts the pages show are still English, the
OpenAPI file lags behind the API, one badge has the contrast defect fixed
elsewhere, and the documentation's compatibility promise contradicts the
`ChecksumApi` break.

**The version:** 0.21.0. The unreleased section has Added, Changed, Removed
and Fixed. The `ChecksumApi` change is incompatible, which below 1.0 is still
a minor.

## Before the release

1. **Server messages the rules pages show are untranslated (B1).**
   - Contract §3.7 requires them translated, and the CHANGELOG's Fixed
     bullet says every text can be.
   - `lib/Controller/RulesController.php`:
     - the default `forbidden()` text (:629, used at :272, :344, :398, :574);
     - `'Invalid request body.'` (:279, :581);
     - the scope refusals (:115, :120);
     - the reorder errors (:461, :466).
   - Also reachable from the pages:
     - `RuleService.php:1427-1429`, the stale reorder list;
     - `Selector.php:102-131`, through `badRequest($e->getMessage())`;
     - `RuleService.php:1219`, `'Unknown mode "%s".'`;
     - `ChecksumApi.php:1209`, `'No rule with ID "%s".'`.
   - The frontend shows `result.error` for save, delete, reorder, reapply and
     toggle.
   - Fix: `IL10N` for each; `Selector` and the reorder get an `IL10N`, as
     `RuleDefinitionValidator` has.
2. **Background-job counters are untranslated (F4).** `settings-admin-vue/App.vue:192-194` prints the server's raw keys (`copied 1200, files 900, done 0`), so a German page mixes languages. `App.spec.ts:127` asserts the English. Fix: a `t()` text with a placeholder per key, with `done` shown as a word or left out.
3. **The OpenAPI file lacks the empty-files change (B2, D1).**
   - `includeEmpty` is missing from `/api/v1/duplicates` (~:270) and
     `/api/v1/sudo/duplicates` (~:648).
   - `DuplicateGroup` (~:1172) has no `empty`.
   - The example hash is `da39a3ee…`, the empty file's, which the listing now
     hides.
   - Fix: the parameter (default false), `empty: boolean`, and the example
     `aaf4c61d…`, as `docs/api-v1.md` has it.
4. **The compatibility promise contradicts the `ChecksumApi` break (B3, D10).**
   - `docs/api-v1.md` calls the API "Stable". Its table (:1202-1217) says
     renaming a PHP parameter needs a new major version.
   - Five methods now take a required `$reachUids` second.
   - Fix: a table row "Reorder, or remove the default of, a PHP method
     parameter | No | New major version", and the note "before app 1.0.0 a
     break ships in a minor release under `### Changed`".
5. **The repair message names a heading that is gone (B6, D3).**
   `RepairQuietStart.php:68` says "Advanced → Status Info"; the heading is
   "Status". `RepairQuietStartTest.php:459` asserts the old text.
6. **The README spells a REST parameter wrong (D2).** `README.md:627` has
   `min_count`, where the parameter is `minCount`. A wrong name is silently
   ignored. The row also lacks `hash`, `anywhere` and `includeEmpty`.
7. **The "folder missing" badge is white on pale yellow (F1).**
   `RuleRow.vue:238-239` puts `--color-primary-text` on `--color-warning`:
   the defect "[FIX] Status labels are readable on a light background" fixed
   elsewhere. Fix: `color: var(--color-warning-text)`.
8. **The CHANGELOG (D11, D12).**
   - **Already in the working tree:**
     - the three translation bullets are one;
     - the two English-rework bullets are one;
     - "checksum index check";
     - `defaultAlgo`.
   - **Still to do:**
     - "from "Create rule"" should be the placeholder row's "Add rule";
     - the `includeEmpty` bullet should also name `GET /api/v1/sudo/duplicates`,
       `occ find-duplicates`, whose default output changes, and the per-file
       route's `empty`;
     - the `ChecksumApi` bullet should say a call that omitted `$reachUids` breaks
       too;
     - Added: `canRecalc` on both duplicates listings;
     - Changed: `error` texts reworded and in the caller's language
       (D11). `docs/api-v1.md`'s "Error Handling" should say to match on
       the status, never the text.
9. **The e2e README's `CYPRESS_occ` (D4).** It recommends a command that
   breaks the suite. **Fixed in the working tree**, and checked on
   instance 34.
10. **The translations of the newest texts.** The pot grew from 353 to 358
    texts with the empty-files work.
    - **Checked in all eleven languages, and no meaning defect in any.** All
      358 texts were checked by script, and the recent ones read against the
      English; es, fr, it and pt_BR were read in full, entry by entry.
    - **Minor points only,** T1 to T17 (see "Translation spot-checks"
      below).

## In the same pass (cheap)

- **B4:** `RuleService::assertApplicable()` throws translated text that
  `ApplyRuleJob` logs. Logs stay English: split an English `reason` from the
  translated `error`, as `HashCalculationService` does.
- **D13:** the console prints "Hash index check" (`JobStatsService.php:72`),
  where the interface says "Checksum index check". README.md:307-308 papers
  over this.
- **F2, F3:** the rule-type badges, and the Others tab's "Show empty files"
  label, should use `--color-warning-text` on `--color-warning`.
- **Documentation gaps in the empty-files work:**
  - the README's Duplicate File Browser list and its `find-duplicates` row
    (D5);
  - the FAQ on duplicate detection (D7).
- **Stale documentation:**
  - the FAQ lists three background jobs of five (D6);
  - `docs/api-v1.md` names "Hash Algorithms" (D8) and keeps the old terms,
    user, group folder, outrun and leader (D9);
  - the user guide says a file link opens the file, where it now opens the
    file's folder (D14).
- **D20:** six manifest translations narrow "every group of identical files
  you hold" to "own", though received shares count: de, fr, it, nl, pl,
  zh-hans.

## Translation spot-checks

Three spot-checks, all read-only, covered the eleven languages: de, de_DE,
en_GB and nl; ja, zh_CN and pl; es, fr, it and pt_BR.

**What they confirmed, by script and by reading:**
- **Coverage:** every language has the template's 358 texts, with no empty,
  fuzzy or extra entries. `msgfmt` passes on all.
- **Placeholders, literals and plurals** match the English, including
  Polish's four forms.
- **Registers are kept:**
  - `de` du, and `de_DE` Sie: its 34 differing texts differ only in address;
  - nl je, ja です/ます, zh_CN 您, pl informal;
  - es usted, fr vous, it tu, pt_BR você.
- **British English** differs from the English in "dialogue" only.
- **Typography:**
  - fr puts a no-break space before `: ; ? !` and inside « »;
  - es balances its «», ¿ and ¡.
- **No clashes:** labels quoted in running text match their own elements,
  and no type or mode shares a word with another.
- **The recent texts are right everywhere:** the five empty-files texts, the
  Type help, the two job names, the recalculate permission, and "Noted".

**The findings are small, and none concerns meaning:**
- **T1, nl:826.** "Show in Duplicates" → "In “Duplicaten” tonen": the page
  name quoted, the infinitive as the glossary's labels have it.
- **T2, nl:1030.** Uses "de bestanden … bekijken" where the glossary's term
  for looking across accounts is "accountoverschrijdend kijken".
- **T3, de and de_DE:1111.** "zur Löschung … vorgemerkt" for "pending
  deletion" is better German than the glossary's *ausstehend*. Add it to
  the glossary rather than change the text.
- **T4.** The `de_DE` header says `Language: de` (also D21).
- **T5, ja:376.** 大きなグループ should be 大きな重複グループ, the glossary's
  term for a group of duplicates.
- **T6, ja:1110.** "pending deletion" should be 削除待ち, in the text and in
  the glossary: the glossary's 保留中 reads as "on hold".
- **T7, ja:126.** The rule type in `%s` should be quoted, as zh_CN and pl
  quote it.
- **T8, zh_CN:376.** 大组 should be 文件组, the glossary's term.
- **T9, pl:828.** "Pokaż w Duplikatach" → "Pokaż na stronie „Duplikaty”":
  the page name quoted and not declined.
- **T10, pl:1131.** "teraz: {hash}" → "obecnie: {hash}", the current value
  rather than "at this moment".
- **T11, fr:412.** "Find across accounts": "Rechercher dans d'autres
  comptes" → "Rechercher dans tous les comptes". The link includes the
  viewer's own, as its tooltip (:656) says.
- **T12, pt_BR:412.** The same text: "Encontrar entre contas" → "Encontrar
  em todas as contas", as its tooltip (:656).
- **T13, fr and it:297, :372.** Both drop "default" from "catch-all
  default":
  - fr "une règle de repli par défaut" / "la règle de repli par défaut
    vient en dernier";
  - it "una regola predefinita di ripiego" / "la regola predefinita di
    ripiego viene per ultima".
- **T14, es:498.** "look at other accounts' files": "ver los archivos" →
  "consultar los archivos de otras cuentas tras confirmar su contraseña, …",
  the glossary's *consultar otras cuentas*.
- **T15, fr:358, :452.** They keep the English "&": "Imposées – groupes et
  dossiers d'équipe" / "Groupes et dossiers d'équipe".
- **T16, it:128.** "An %s rule …": "Una regola %s" → "Una regola di tipo
  %s", as the glossary has it, and as es, fr and pt_BR say.
- **T17, pt_BR:37.** "read across accounts": "ler entre contas" reads as
  "read between accounts" → "ler dados de outras contas", the rest
  unchanged.

## Can wait

- **Backend:**
  - B5: `HashIndexCheck`'s failure comment is wrong; a failed walk is
    abandoned until the next repair.
  - B7: the recalculate rule exists twice (`PublicApiController::mayRecalc`
    and `ChecksumApi::mayRecalc`).
  - B8: `SettingsController.php:300` returns raw exception text.
  - B9: test gaps: the `error`/`reason` split, `includeEmpty` and
    `canRecalc` on `/sudo/duplicates`, and the no-algorithm exclusion on a
    real database.
  - B10: `ChecksumApi.php:646` says `$algo` defaults to `sha1`.
- **Frontend:**
  - F5: the pickers say "Search …" twice and "Select folder …" once.
  - F6: the band fallback differs between title and cell.
  - F7: the default algorithm goes stale after it is changed on the same
    page.
  - F8 to F10: test gaps for dates in a component, the sidebar link's href,
    and the `defaultAlgo` wiring.
  - F11: the personal `App.spec` fixtures carry `userScope` and `pinned`.
  - F12: the l10n e2e spec restores `force_language` as a string.
- **Documentation:**
  - D15: the user guide's control order differs from the page.
  - D16: README.md: a settings path through "Additional settings"; "every
    command" has a `fcias:` alias, but four have none; "Generate SHA-1" on a
    command without `--algo`.
  - D17: the FAQ says a repair with no step runs all of them.
  - D18: a stale docblock in `ChecksumApi`.
  - D19: stale lines in the e2e README.
  - D21: the `de_DE` header says `Language: de`.
- **Pre-existing, worth an issue:** `queryDuplicates` joins a group's file
  ids with `GROUP_CONCAT`. MySQL's default 1024-byte limit truncates a group
  of more than about 140 files, and can cut its last id into another file's.
  `includeEmpty=1` exposes the largest group. Verified in code only.
