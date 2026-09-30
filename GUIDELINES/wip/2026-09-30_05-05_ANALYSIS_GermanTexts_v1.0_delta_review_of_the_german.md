# ANALYSIS GermanTexts v1.0: delta review of the German after the English pass

> **2026-09-29.** A read-only review of the German, `de` and `de_DE`, after it was carried over to the English that ANALYSIS EnglishTexts v1.0 reworked, for AP Languages v1.5 step 3. Its nine findings are the maintainer's to decide. Reproduced verbatim below, its headings one level down. The files it read are `translationfiles/de/file_checksum_search.po`, `translationfiles/de_DE/file_checksum_search.po`, `translationfiles/de/GLOSSARY.md` and the German description in `appinfo/info.xml`, as they stood in the working tree on 2026-09-29, before step 2's commit.

## Delta review: German, `de` and `de_DE`

**Verdict: ship after the small fixes below.** The carried-over German says what the new English says, and every text that uses *Segment* is grammatical. No new finding concerns meaning or terms. The most notable are a pronoun that does not agree with one row's label, a hint with three *sie* that mean two things, and a sentence-initial *Sie* that `de_DE` readers can take as address.

Counts: meaning 0, term 0, register 1, as-is 0, style 8 (4 of them optional).

Scope: every text in `en/CHANGES.md` and `de/CHANGES.md`, every text with *Segment*, the register split, and the German manifest against the new English. I read the files in the checkout without writing to it.

### Checked

- **Mechanics.** Both files pass `msgfmt --check`: 353 texts translated, none fuzzy, none empty. Every `{…}`, `%s` and `%n` matches its msgid. No text is left with *Ebene*, *Abschnitt*, *übersteuern*, *Gruppenleitung*, *Seitenleiste der Dateien*, *Instanz* or *Hintergrund-Aufgabe*. No `...`, and no em dash outside `"—"`.
- **Ellipsis.** All 8 ellipses in each file now follow U+00A0, in the msgstr as in the msgid, so `de/CHANGES.md`'s "Not done" note is out of date.
- **Register.** `de_DE` differs from `de` in 34 texts, every one of them only in address. The one new difference, the places help's "wenn du/Sie speicherst/speichern", follows the English, which now addresses the reader ("until you save it"). *Hintergrundaufgabe* is now the same in both files. `de` has no *Sie* of address, and `de_DE` has no *du*, *dein* or du-imperative. `de_DE` quotes „Erlaube Dateisystem-Zugriff“, which looks informal but is Nextcloud's own `de_DE` label (settings app), so it is right as a quotation. One sentence-initial *Sie* is ambiguous: finding 1.
- **Segment (neuter)**, all 9 texts, both files, all correct:
  - „die ersten vier Segmente belegen“
  - „Segment {band}“ and „Segment {band}, Position {position}“
  - „Innerhalb dieses Segments“
  - „Das Segment einer Regel … in ein anderes Segment zu verschieben“
  - „Wird in Segment {band} ausgewertet“: no article before a number, as in „in Kapitel 7“
  - „Prioritätssegment“
  - „in der Reihenfolge der Segmente … Das Segment einer Regel … – es wird nicht direkt gewählt“
  - „ihr Segment … in diesem Segment … die erste Regel des Segments 7 … Das Segment … – es wird nie direkt gewählt –“

  No pronoun still points back to the feminine *Ebene*.
- **Cross-references** match their labels:
  - „Regel hinzufügen“
  - „Neu berechnen“, „Prüfen“, „Alle prüfen“
  - „Erzwungen“
  - „Wer kontoübergreifend Einsicht nehmen darf“
  - „Alle Home-Ordner“, „Alles“
  - „An beliebiger Stelle im Hash suchen“
  - „Sicherheit“
  - „Einschließen“, the dialog's type name that the new banner quotes
- **Outside both CHANGES files:** the two texts from 02c6f1c ("The path is not in a folder you can write to." and "The path leads into a received share, …") are faithful and differ only in address. `RuleTable.vue` now builds the team-folder row through `'{folders}: {folder}'`, so my earlier "outside the translation" note is settled.

### Findings

#### 1. register: a sentence-initial *Sie* reads as address in `de_DE` (de, de_DE)

- msgid: `Rules for one account's home folder — personal rules — or for one storage. They decide a file only where no enforced rule matched it first.`
- current: `… oder für einen Speicher. Sie entscheiden über eine Datei nur, wo zuvor keine erzwungene Regel gepasst hat.`
- proposed: `… oder für einen Speicher. Diese Regeln entscheiden über eine Datei nur, wo zuvor keine erzwungene Regel gepasst hat.`
- Reason: at the start of a sentence, *Sie* is the formal "you" as readily as "they". This is the only such sentence among the texts the two files share, and the first sentence of this text changed in this pass.

#### 2. style: three *sie* with two referents (de, de_DE)

- msgid: `the file changed while no rule maintained its checksums, so they were deleted; it gets them back once a rule covers it again`
- current: `die Datei wurde geändert, während keine Regel ihre Prüfsummen pflegte, daher wurden sie gelöscht; sie erhält sie zurück, sobald eine Regel sie wieder abdeckt`
- proposed: `die Datei wurde geändert, während keine Regel ihre Prüfsummen pflegte, daher wurden diese gelöscht; sobald eine Regel die Datei wieder abdeckt, erhält sie wieder Prüfsummen`
- Reason: in "sie erhält sie zurück, sobald eine Regel sie …", *sie* means file, checksums and file in turn. *Zurück* also suggests that the deleted checksums come back, when new ones are computed.

#### 3. style: *seine* does not agree with every row it follows (de, de_DE)

- msgid: `no rule of its own — its files are left to a more general rule, if one is enabled`
- current: `keine eigene Regel – seine Dateien bleiben einer allgemeineren Regel überlassen, sofern eine aktiviert ist`
- proposed: `keine eigene Regel – die Dateien bleiben einer allgemeineren Regel überlassen, sofern eine aktiviert ist`
- Reason: the rows are labelled *Speicher: …*, *Team-Ordner: …*, *Alle Home-Ordner* and *Alles – jeder Speicher* (`RuleTable.vue`, `namespaces`). *Alle Home-Ordner* is plural, and the reader sees the label, not the *Ort* the translator had in mind. The article needs no agreement.

#### 4. style: "Es konnte nicht gespeichert werden" (de, de_DE; the translator's open question)

- msgid: `Could not save.`
- current: `Es konnte nicht gespeichert werden.`
- proposed: `Die Änderungen konnten nicht gespeichert werden.`
- Reason: the impersonal passive with *es* is stilted. The text is the fallback toast of the algorithm and permission sections (`AlgorithmSection.vue:110`, `PermissionSection.vue:121`), where what failed is the change just made. This keeps the "Die … konnten nicht … werden" pattern of its neighbours without reusing *Einstellung* or *Optionen*, which two other texts already use.

#### 5. style: whose files the group admin may see (de, de_DE)

- msgid: `The sudoers: … A group admin may also look at the members of the groups they administer without being selected. Looking across accounts still needs a password confirmation, which holds for 30 minutes, or a granted app password; this only says who may be asked.`
- current: `… Eine Gruppenadministration darf außerdem bei den Mitgliedern der Gruppen, die sie verwaltet, Einsicht nehmen, ohne ausgewählt zu sein. … oder ein freigeschaltetes App-Passwort; dies legt nur fest, wer danach gefragt werden darf.`
- proposed: `… Eine Gruppenadministration darf außerdem in die Dateien der Mitglieder der von ihr verwalteten Gruppen Einsicht nehmen, ohne ausgewählt zu sein. … oder ein freigeschaltetes App-Passwort; dies legt nur fest, wer überhaupt gefragt werden darf.`
- Reason: one takes *Einsicht in* something, not *bei* someone. The *danach* used to point to the one password confirmation; with the app password as an alternative, it points to either.

#### 6. style (optional): *vor ihr/ihnen kommen* where the admin help says *stehen vor* (de, de_DE)

- msgids and changes:
  - `"Yes" means an administrator enforced this rule: …`: „kann keine persönliche Regel vor ihr kommen“ → „kann keine persönliche Regel vor ihr stehen“
  - `Administrator-enforced rules for one account's home folder or one storage. …`: „Dort kommt keine andere Regel vor ihnen“ → „Dort steht keine andere Regel vor ihnen“
  - `When set, nobody else can edit or disable this rule. …`: „daher kann keine vor ihnen kommen“ → „daher kann keine vor ihnen stehen“
  - the unchanged `Administrator-enforced rules for the members of a group, …`: „kommt vor ihnen“ → „steht vor ihnen“
- Reason: *vor jemandem kommen* for a rank is correct but loose. The table's own help already says „erzwungene Regeln stehen daher immer vor persönlichen Regeln“, and one verb reads as one idea.

#### 7. style (optional): a mode name in running text without quotes (de, de_DE)

- msgid: `Missing (like Auto, and add missing checksums)`
- current: `Fehlende (wie Automatisch, dazu fehlende Prüfsummen ergänzen)`
- proposed: `Fehlende (wie „Automatisch“, dazu fehlende Prüfsummen ergänzen)`
- Reason: *wie Automatisch* can be read as the adverb *automatisch*. The glossary quotes a name in running text.

#### 8. style (optional): the places help could stay one text (de, de_DE)

- msgid: `These places exist on this server … nothing is saved until you save it.`
- current: `… gespeichert wird erst, wenn du speicherst.` / `… gespeichert wird erst, wenn Sie speichern.`
- proposed (both): `… gespeichert wird erst beim Speichern.`
- Reason: this is shorter and needs no address. The split as it stands is correct, since the English addresses the reader, so this is optional.

#### 9. style (optional): manifest, first sentence (de)

- current: `Indiziert die Prüfsummen von Dateien, sodass sich eine Datei anhand ihrer Prüfsumme finden lässt und ihre Duplikate gleich mit.`
- proposed: `Indiziert die Prüfsummen von Dateien, sodass sich eine Datei anhand ihrer Prüfsumme finden lässt – und mit ihr sofort ihre Duplikate.`
- Reason: *gleich mit* is spoken German, and it hangs at the end of a store description.

### The German manifest against the new English

- **Summary:** faithful, 123 characters.
- **Description:** every bullet says what its English says.
  - The Duplicates line gives "for those allowed to look across accounts, other accounts' files too" exactly, with the glossary's *kontoübergreifend Einsicht nehmen*.
  - The occ line drops *Export* with the English.
  - *instanzweit* stays, because the English keeps "instance-wide".
- **Markdown:** intact, and *du* throughout.
- The only note is finding 9, which is optional.

### Glossary

- It matches the files:
  - *Segment* with its neuter examples
  - the internal "segment" row
  - *Ort*, *Regel hinzufügen*, *dieser Server*, *Dateisystem-Zugriff*, *Sicherheit*
  - *Hintergrundaufgabe* for both registers
  - the infinitive rule for labels
  - U+00A0 before the ellipsis
- One note is now stale: the *grant* row's "also for 'standing authorisation'". No English text says that any more. The row itself is right.

### The translator's open question

"Could not save." is finding 4. The old „Speichern fehlgeschlagen.“ would also be correct. The proposal keeps the pattern the other "Could not …" texts now follow.
