# German glossary

The terms both German translations use, `de` and `de_DE` alike. A new text
takes them from here; a term that changes, changes here and in both `.po`
files at once.

## Registers

- `de` is Nextcloud's informal German: *du*, *dein*.
- `de_DE` is its formal German: *Sie*, *Ihr*. It differs from `de` only
  where a text addresses the reader.
- A label or button names its action in the infinitive, the same in both:
  „An beliebiger Stelle im Hash suchen“, „Zur Kenntnis nehmen“.

## Terms

| English | German | Note |
|---|---|---|
| checksum | Prüfsumme | the English's word for the value |
| hash | Hash, Hashes | only where the English keeps *hash*: the hash filter, „An beliebiger Stelle im Hash suchen“ |
| algorithm | Algorithmus, Algorithmen | |
| duplicates | Duplikate | |
| rule | Regel | |
| personal rule | persönliche Regel | an account's rule for its own home folder; not *eigene Regel eines Kontos* |
| band | Segment, Segmente | the evaluation layer a rule falls in; neuter: „dieses Segments“, „ein anderes Segment“, „Prioritätssegment“, „Segment 7, Position 1“ |
| segment (in the code) | — | one scope's rules inside a band; internal, never shown. The German *Segment* is the band |
| position | Position | a rule's place among the rules of the same scope in its band |
| priority | Priorität | |
| scope | Geltungsbereich | |
| selector | Selektor | a scope as the API writes it, `home:alice`; the parameter name `selector` is typed as it is |
| place (a storage, a team folder, all home folders) | Ort | what has, or lacks, a catch-all rule of its own |
| enforced | erzwungen | |
| catch-all rule / default | Auffangregel / Standardregel | |
| Add rule | Regel hinzufügen | the button, and the text that names it |
| Include / Ignore / Exclude | Einschließen / Ignorieren / Ausschließen | a type's name in the form; the rule's type, not its mode |
| Auto / Missing / Force / Lazy | Automatisch / Fehlende / Erzwingen / Später | a mode's name in the form |
| home folder | Home-Ordner | |
| team folder | Team-Ordner | Nextcloud's own term; the selector prefix `groupfolder:` is typed as it is |
| storage | Speicher | |
| raw ID | interne ID | |
| external storage | externer Speicher | |
| account | Konto, Konten | Nextcloud's own term |
| this server | dieser Server | not *diese Instanz*; „die meisten Server“ |
| administrator | Administration | gender-neutral, as Nextcloud has it |
| group admin | Gruppenadministration | Nextcloud's word for a sub-admin („Gruppenadministration für“) |
| app password | App-Passwort | |
| filesystem access | Dateisystem-Zugriff | as in Nextcloud's own „Erlaube Dateisystem-Zugriff“ |
| Security (the personal settings section) | Sicherheit | Nextcloud's own name |
| sudo token | Sudo-Token | |
| grant (noun / verb) | Freischaltung / freischalten | *Berechtigung* is only a permission |
| revoke | widerrufen | |
| cross-account | kontoübergreifend | |
| look across accounts | kontoübergreifend Einsicht nehmen, kontoübergreifende Einsicht | |
| override | außer Kraft setzen, Vorrang haben | not *übersteuern*, which is Swiss usage |
| sudoers | Sudoers | |
| Mine / Others | Eigene / Andere | the tabs |
| recalculate | neu berechnen, Neuberechnen | |
| verify | prüfen | |
| reapply | erneut anwenden | |
| index (verb), indexed | indizieren, indiziert | not *indexieren* |
| pending | ausstehend | a calculation still to come |
| pending deletion | zur Löschung vorgemerkt | the reset state's hint: „zur Löschung durch die Hintergrundaufgabe vorgemerkt“ |
| queued files | Dateien in der Warteschlange | |
| untrusted / eroded / reset | nicht vertrauenswürdig / verfallen / zurückgesetzt | the states of stale checksums |
| invalidated (by a reset) | ungültig | not *verwaist*, the orphan purge's word |
| background job | Hintergrundaufgabe | both registers, as Nextcloud's own admin heading |
| rule sweep / queue drain / orphan purge / checksum copy / checksum index check / checksum stamp check | Regeldurchlauf / Abarbeitung der Warteschlange / Bereinigung verwaister Einträge / Übernahme vorhandener Prüfsummen / Prüfung des Prüfsummen-Index / Prüfung der Prüfsummen-Zeitstempel | |
| tunables | Feineinstellungen | |
| prefill | vorausfüllen | |
| glob pattern | Glob-Muster | |
| path | Pfad | |
| sidebar | Seitenleiste | |
| file sidebar | Datei-Seitenleiste | Nextcloud's own term |
| tab | Reiter | |

## Style

- The app's name, *File Checksum Index & Search*, stays English.
- A name in running text takes German quotes and the exact German of that
  element's own label: „Alle Home-Ordner“, „Regel hinzufügen“, a type as
  „Einschließen“. A value shown or typed as it is keeps straight quotes and
  stays English: `"**"`, `"include"`, `"hash"`, `local::/path/`.
- A failure names what failed: „Die Regel konnte nicht gespeichert
  werden.“ One with nothing to name reads „Speichern fehlgeschlagen.“, as
  the English's "Saving failed.".
- A dash between clauses is the en dash with spaces: ` – `.
- An ellipsis follows a no-break space (U+00A0), as in the English:
  „Suchen …“. Written as the character itself.
- `{placeholders}`, `%s` and `%n` stay as they are.
