# Polish glossary

This translation is an AI draft, not yet reviewed by a native speaker.

The terms the Polish translation `pl` uses. A new text takes them from
here; a term that changes, changes here and in the `.po` file at once.

## Registers

- `pl` addresses the reader as Nextcloud's own Polish does: informal
  second person singular (*możesz*, *nie masz*, imperatives such as
  *Skontaktuj się*, *Utwórz*), with the possessive capitalised as a
  courtesy: *Twoje*, *Twój*, *Twoich* (core and settings: „Twoje konto
  nie jest skonfigurowane…”, „Skontaktuj się z administratorem”).
- A past-tense verb in the second person carries a gender in Polish
  (*zrobiłeś*, *zrobiłaś*). The texts avoid it: an impersonal form
  (*zapisano*) or the present tense takes its place. Where the text is
  about the reader's own choice, the present or the perfective future
  keeps the reader in it: *…pasuje do tego, co wpiszesz*.
- A button or menu entry names its action in the imperative, as
  Nextcloud's Polish does: *Zapisz*, *Dodaj regułę*, *Przelicz*.
- A button that acknowledges speaks in the first person, as Polish
  interfaces do: *Przyjmuję do wiadomości*. The toast that follows it
  reports in the impersonal past: *Przyjęto do wiadomości – …*.

## Terms

| English | Polish | Note |
|---|---|---|
| checksum | suma kontrolna, sumy kontrolne | the English's word for the value |
| hash | hash, hasha, hashe, hashy | only where the English keeps *hash*: the Duplicates hash filter, „Dopasowuj w dowolnym miejscu hasha”, the *Hash* label, and the store texts' „po hashu” ("by hash"); the loanword Polish IT uses, since *skrót* would also read as "shortcut" |
| hashing | obliczanie sum kontrolnych | |
| algorithm | algorytm, algorytmy | |
| checksum algorithms | algorytmy sum kontrolnych | the section heading |
| duplicates | duplikaty | |
| rule | reguła | Nextcloud's *reguła dostępu* in team folders |
| personal rule | reguła osobista, reguły osobiste | an account's rule for its own home folder, as *ustawienia osobiste*; not *własna reguła konta*. Addressed to the reader, "your own rule" stays *Twoja własna reguła* |
| band | poziom, poziomy | the evaluation layer a rule falls in: „Poziom 7, pozycja 1”, „Poziom priorytetu” |
| position | pozycja | a rule's place among the rules of the same scope in its band |
| priority | priorytet | *Poziom priorytetu* for "Priority band" |
| scope | zakres | Nextcloud's external storage uses *Zakres*; "the rules of the same scope": *reguły o tym samym zakresie* |
| selector | selektor | a scope as the API writes it, `home:alice`; the parameter name `selector` is typed as it is, and takes the masculine: *selector jest wymagany* |
| place (a storage, a team folder, all home folders) | miejsce, miejsca | what has, or lacks, a catch-all rule of its own |
| specific | szczegółowy | the band labels, "more specific rules" |
| enforced | wymuszona (reguła), wymuszone (reguły) | Nextcloud: *(wymuszona)* |
| catch-all rule | reguła zbiorcza | the status rows: *brak reguły zbiorczej*, *brak własnej reguły*, noun phrases that agree with rows of every gender |
| default (rule) | reguła domyślna; the catch-all default: domyślna reguła zbiorcza | |
| Add rule | Dodaj regułę | the button, and the text that names it |
| evaluate, evaluation order | sprawdzać, kolejność sprawdzania | |
| match | pasować, dopasowanie | |
| come before (a rule) | poprzedzać | *żadna reguła osobista nie może jej poprzedzać* |
| address (a rule addresses …) / cover | dotyczyć / obejmować | |
| Include / Ignore / Exclude | Uwzględnij / Ignoruj / Wyklucz | a rule's **type** (*rodzaj*), the choices of the Type field |
| Auto / Missing / Force / Lazy | Automatycznie / Brakujące / Od nowa / Później | a rule's **mode** (*tryb*), the choices of the Mode field; Force is *Od nowa*, not *Wymuś*, so it cannot be read as *wymuszona* |
| type / mode | rodzaj / tryb | Nextcloud's *Type* is *Rodzaj* |
| home folder | folder domowy | Nextcloud's sharing says *folder główny*, which here would read as the root folder that the path `/` names |
| team folder | folder zespołu, foldery zespołów | Nextcloud's own term; the selector prefix `groupfolder:` is typed as it is |
| storage | magazyn, magazyny | Nextcloud's term |
| external storage, external mount | magazyn zewnętrzny | Nextcloud's external storage app |
| raw ID | wewnętrzne ID | |
| account | konto, konta | Nextcloud's *Konta*; *użytkownik* stays only in the document title *Podręcznik użytkownika* |
| this server | ten serwer, na tym serwerze | not *instancja*; „do większości serwerów” |
| administrator | administrator | Nextcloud's term |
| admin group | grupa administratorów | |
| group admin | administrator grupy, administratorzy grup | Nextcloud's name for a group's sub-admin (settings: „Administrator grupy”) |
| app password | hasło aplikacji | |
| Allow filesystem access | „Zezwalaj na dostęp do systemu plików” | Nextcloud's own name for the app password's option |
| sudo token | token sudo | |
| sudoers | sudoers | the permission's name |
| grant (noun / verb) | upoważnienie / upoważnić | *zezwalać* is Nextcloud's "allow" and *uprawnienie* is "permission", so the grant takes a third word |
| permission | uprawnienie | |
| allow | zezwalać | Nextcloud's toggles: *Zezwalaj …* |
| revoke | cofnąć | Nextcloud: *Cofnij* |
| cross-account, look across accounts | innych kont, mieć wgląd w inne konta | |
| route (API) | trasa | |
| Mine / Others | Moje / Cudze | the tabs |
| compute, calculate | obliczać | |
| recalculate | przeliczać, przeliczanie, *Przelicz* | the sidebar's section heading and its button are one English text, *Przelicz*; what is recalculated is a file's checksum, never the file |
| verify | weryfikować, *Zweryfikuj* | |
| apply / reapply | zastosować / zastosować ponownie, *Zastosuj ponownie* | |
| indexed | zaindeksowane | |
| pending | oczekujące | |
| queued files | pliki w kolejce | |
| untrusted / eroded / reset | niezaufane / wygasłe / zresetowane | the states of stale checksums, past participles agreeing with *sumy kontrolne*: *wygasłe* because nothing put them out, they lapsed; *zresetowane* is the state a reset left them in, not the action |
| invalidated (by a reset) | unieważnione | the hint beside *Zresetowane*; kept apart from *osierocone*, the orphans the purge removes |
| background job | zadanie w tle | Nextcloud's term |
| rule sweep / queue drain / orphan purge / checksum copy / checksum index check | Przegląd reguł / Opróżnianie kolejki / Usuwanie osieroconych wpisów / Kopiowanie istniejących sum kontrolnych / Sprawdzanie indeksu sum kontrolnych | the background jobs' names |
| tunables | Dostrajanie | |
| prefill | wypełniać wstępnie | Nextcloud: *wstępnie wypełniony* |
| picker | lista wyboru | its placeholders: *Wyszukaj grupy…*, *Wyszukaj konta…*, *Wybierz grupy…*, *Wybierz folder…* (settings: „Wyszukaj grupy…”) |
| match anywhere in the hash (the Duplicates switch) | „Dopasowuj w dowolnym miejscu hasha” | imperative, as Nextcloud's switches („Pokaż ukryte pliki”); not *Szukaj wszędzie*, which is Nextcloud's "Search everywhere", across folders and locations |
| glob pattern | wzorzec glob | |
| path | ścieżka | |
| sidebar | panel boczny | Nextcloud: *panel boczny pliku* |
| tab | karta | Nextcloud's LDAP: *w karcie Zaawansowane* |
| settings, personal settings | ustawienia, ustawienia osobiste | |
| Security (the settings section) | „Bezpieczeństwo” | Nextcloud's own name |
| command line | wiersz poleceń | |

## Style

- The app's name, *File Checksum Index & Search*, stays English.
- A name in running text takes Polish quotes, low opening and high
  closing, and the exact Polish of that element's own label: „Wszystkie
  foldery domowe”, „Dodaj regułę”, a type as „Uwzględnij”,
  „Bezpieczeństwo”. A value shown or typed as it is keeps straight
  quotes and stays English: `"**"`, `"include"`, `"hash"`,
  `local::/path/`. A type or mode value inside a Polish sentence is
  quoted too, placeholder or not: *Reguła rodzaju "%s"*.
- A failure names what failed: „Nie udało się zapisać reguły.” One with
  nothing to name reads „Zapisywanie nie powiodło się.”, as the
  English's "Saving failed.".
- A dash between clauses is the en dash with spaces: ` – `.
- An ellipsis follows the word without a space, although the English
  puts a no-break space before it: „Zapisywanie…”, „Wyszukaj grupy…”,
  as Polish typography has it and as most of Nextcloud's Polish texts
  write it. Never three dots.
- The Priority column's numbers keep their dot: *7.1*, not *7,1*.
- `{placeholders}`, `%s` and `%n` stay as they are.
- `%n` takes four forms: *plik* (1), *pliki* (2–4, 22–24, …),
  *plików* (0, 5–21, 25–31, …) and the fourth for fractions, which
  Nextcloud's own Polish fills with the third.
