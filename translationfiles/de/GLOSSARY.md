# German glossary

The terms both German translations use, `de` and `de_DE` alike. A new text
takes them from here; a term that changes, changes here and in both `.po`
files at once.

## Registers

- `de` is Nextcloud's informal German: *du*, *dein*.
- `de_DE` is its formal German: *Sie*, *Ihr*. It differs from `de` only
  where a text addresses the reader, and in *Hintergrund-Aufgabe*, which
  Nextcloud's `de_DE` hyphenates.

## Terms

| English | German | Note |
|---|---|---|
| checksum | Prüfsumme | |
| hash | Hash, Hashes | |
| algorithm | Algorithmus, Algorithmen | |
| duplicates | Duplikate | |
| rule | Regel | |
| band | Ebene, Ebenen | the evaluation layer a rule falls in; not *Bereich*, which would clash with *Geltungsbereich* |
| position | Position | a rule's place inside its band |
| priority | Priorität | |
| scope | Geltungsbereich | |
| slice (of files) | Ausschnitt | |
| enforced | erzwungen | |
| catch-all rule / default | Auffangregel / Standardregel | |
| Include / Ignore / Exclude | Einschließen / Ignorieren / Ausschließen | a mode's name in the form |
| Auto / Missing / Force / Lazy | Automatisch / Fehlende / Erzwingen / Später | a mode's name in the form |
| home folder | Home-Ordner | |
| group folder, team folder | Team-Ordner | Nextcloud's own term |
| storage | Speicher | |
| raw id | interne ID | |
| external mount | externer Speicher | |
| account, user | Konto, Konten | Nextcloud's own term for both |
| administrator | Administration | gender-neutral, as Nextcloud has it |
| group leader | Gruppenleitung | |
| app password | App-Passwort | |
| sudo token | Sudo-Token | |
| grant (noun / verb) | Freischaltung / freischalten | |
| revoke | widerrufen | |
| cross-account | kontoübergreifend | |
| sudoers | Sudoers | |
| Mine / Others | Eigene / Andere | the tabs |
| recalculate | neu berechnen | |
| verify | prüfen | |
| indexed | indexiert | |
| pending | ausstehend | |
| untrusted / eroded / reset | nicht vertrauenswürdig / verfallen / zurückgesetzt | the states of stale hashes |
| background job | Hintergrundaufgabe (`de`), Hintergrund-Aufgabe (`de_DE`) | |
| rule sweep / queue drain / orphan purge / checksum copy / hash index check | Regeldurchlauf / Abarbeitung der Warteschlange / Bereinigung verwaister Einträge / Übernahme vorhandener Prüfsummen / Prüfung des Hash-Index | |
| tunables | Feineinstellungen | |
| prefill | vorausfüllen | |
| glob pattern | Glob-Muster | |
| path | Pfad | |
| sidebar | Seitenleiste | |
| tab | Reiter | |

## Style

- The app's name, *File Checksum Index & Search*, stays English.
- A name in running text takes German quotes: „Alle Home-Ordner“. A value
  shown or typed as it is keeps straight quotes and stays English:
  `"**"`, `"include"`, `local::/path/`.
- A dash between clauses is the en dash with spaces: ` – `.
- An ellipsis follows a space: „Suchen …“.
- `{placeholders}`, `%s` and `%n` stay as they are.
