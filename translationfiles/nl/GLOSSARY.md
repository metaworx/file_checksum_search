# Dutch glossary

This translation is an AI draft, not yet reviewed by a native speaker.

The terms the Dutch translation `nl` uses. A new text takes them from here;
a term that changes, changes here and in the `.po` file at once.

## Registers

- `nl` is Nextcloud's informal Dutch: *je*, *jouw*, as its core and settings
  texts speak to the reader ("Neem contact op met je beheerder", "Je account
  is niet ingesteld …").
- An impersonal "one" is *iemand*, never *men*, which is stiff next to *je*.
- A label, button or menu entry names its action in the infinitive, as the
  app's other buttons do: “Regel toevoegen”, “Opnieuw berekenen”,
  “Overal in de hash zoeken”.

## Terms

| English | Dutch | Note |
|---|---|---|
| checksum | controlesom, controlesommen | the English's word for the value |
| hash | hash, hashes | only where the English keeps *hash*: the Duplicates hash filter, “Overal in de hash zoeken”, the `"hash"` parameter |
| hashing | het berekenen van controlesommen, berekening | |
| algorithm | algoritme, algoritmen | |
| duplicates | duplicaten | |
| rule | regel | a row of the table is *rij*, never *regel* |
| personal rule | persoonlijke regel | an account's rule for its own home folder; not *eigen regel van een account* |
| the rule on {path} | de regel voor {path} | a rule is *voor* a path, not *op* it |
| an "%s" rule | een regel van het type "%s" | never *een %s-regel*, which glues English to Dutch; a type named by its label: *een regel van het type “Opnemen”* |
| band | laag, lagen | the evaluation layer a rule falls in: “Laag 7, positie 1”, “binnen deze laag”, *prioriteitslaag* |
| segment (in the code) | — | one scope's rules inside a band; internal, never shown |
| position | positie | a rule's place among the rules of the same scope in its band |
| among the rules of the same scope | tussen de regels met hetzelfde toepassingsgebied | where dragging moves a rule |
| priority | prioriteit | |
| scope | toepassingsgebied | Nextcloud's term (external storage) |
| selector | selector | a scope as the API writes it, `home:alice`; the parameter name `selector` is typed as it is |
| place (a storage, a team folder, all home folders) | locatie, locaties | what has, or lacks, a catch-all rule of its own |
| evaluate | evalueren | evaluation order: *evaluatievolgorde* |
| match | overeenkomen, overeenkomst | |
| come before | eerder aan de beurt komen | with its comparison wherever the English has an object: *dan zij*, *dan deze regel* |
| enforced | afgedwongen | Nextcloud's *afdwingen* |
| catch-all rule / default | vangnetregel / standaardregel | |
| Add rule | Regel toevoegen | the button, and the text that names it |
| Acknowledge | Voor kennisgeving aannemen | the banner's button, beside “Sluiten”; its answer is “Genoteerd” |
| Include / Ignore / Exclude | Opnemen / Negeren / Uitsluiten | a rule's *type* (*regeltype*), the Type field's options; the lowercase values `"include"` … stay English |
| Auto / Missing / Force / Lazy | Automatisch / Ontbrekende / Forceren / Later | a rule's *mode* (*regelmodus*), the Mode field's options; the lowercase values `"auto"` … stay English |
| home folder | thuismap | after the Files app's *Thuis* |
| team folder | teammap, teammappen | Nextcloud's own term; the selector prefix `groupfolder:` is typed as it is |
| storage | opslag | |
| external storage | externe opslag | Nextcloud's own term |
| mounted storage | aangekoppelde opslag | after Nextcloud's *aankoppelpunt* |
| received share | inkomende share | Nextcloud's own term |
| raw ID | interne ID | |
| account | account, accounts (*het account*) | Nextcloud's own term |
| this server | deze server | not *deze instantie*; “de meeste servers” |
| administrator | beheerder | Nextcloud's own term |
| admin group | beheerdersgroep | |
| group admin | groepsbeheerder | Nextcloud's word for a sub-admin (“Groepsbeheerder voor”) |
| app password | appwachtwoord | Nextcloud's own spelling; it belongs *bij* an account, not *in* it |
| filesystem access | toegang tot het bestandssysteem | the app-password option is Nextcloud's own label “Toestaan toegang bestandssysteem” |
| sudo token | sudo-token | |
| grant (noun / verb) | vrijgave / vrijgeven | a grant *blijft van kracht totdat ze wordt ingetrokken* |
| revoke | intrekken | Nextcloud's own term |
| permission | machtiging | Nextcloud's own term |
| cross-account | accountoverschrijdend | |
| look across accounts | accountoverschrijdend kijken | look at other accounts' files: *de bestanden van andere accounts bekijken* |
| sudoers | sudoers | |
| Mine / Others | Van mij / Van anderen | the tabs |
| picker | keuzelijst | |
| prefill | vooraf vullen | |
| recalculate | opnieuw berekenen, herberekening | the sidebar button “Opnieuw berekenen” |
| recalculate checksums by hand | handmatig controlesommen opnieuw berekenen | the permission; “Wie controlesommen opnieuw mag berekenen” |
| recalculate a file's checksum | de controlesom van een bestand opnieuw berekenen | a file's checksum is recalculated, not the file; reading the file again is *nogmaals lezen* |
| search (for an item) | zoeken | a picker finds an item: *Groepen zoeken …*; *doorzoeken* is searching inside something |
| verify | controleren | Nextcloud's own term |
| reapply | opnieuw toepassen | |
| indexed | geïndexeerd | |
| pending (deletion) | in afwachting van (verwijdering) | the reset state's hint |
| queued files | bestanden in de wachtrij | |
| untrusted / eroded / reset | niet-vertrouwd / vervallen / gereset | the states of stale checksums; *gereset* is the state a reset left them in (past participle), not the action |
| invalidated (by a reset) | ongeldig gemaakt | the reset state's hint; not *verweesd*, which is the orphan purge's |
| orphan | verweesd | only for the orphan purge's entries |
| background job | achtergrondtaak | Nextcloud's own term |
| rule sweep / queue drain / orphan purge / checksum copy / checksum index check / checksum stamp check | regeldoorloop / wachtrijverwerking / opruimen van verweesde vermeldingen / overname van bestaande controlesommen / controle van de controlesomindex / controle van de tijdstempels van controlesommen | |
| tunables | fijnafstelling | |
| glob pattern | globpatroon | |
| path | pad | |
| sidebar, file sidebar | zijbalk, bestandszijbalk | Nextcloud's own term |
| tab | tabblad | |
| Security (settings section) | Beveiliging | Nextcloud's own term |
| Unified Search | Geünificeerd zoeken | Nextcloud's own term |
| command line | commandoregel | Nextcloud's own term |
| occ command | occ-commando | |

## Style

- The app's name, *File Checksum Index & Search*, stays English.
- A name in running text takes curly double quotes and the exact Dutch of
  that element's own label: “Alle thuismappen”, “Regel toevoegen”, a type as
  “Opnemen”. A value shown or typed as it is keeps straight quotes and stays
  English: `"**"`, `"include"`, `"hash"`, `local::/path/`.
- A failure that names its object reads “Kon de regel niet opslaan.”; one
  with nothing to name reads “Opslaan mislukt.”, as the English's "Saving
  failed.".
- A dash between clauses is the en dash with spaces: ` – `.
- An ellipsis follows a no-break space (U+00A0), as in the English:
  “Zoeken …”. Written as the character itself.
- After *dus*, the verb comes first: “dus kan de regel nooit overeenkomen”.
- "Who may …" or "what happens … is decided by X" takes the passive:
  “Wie accountoverschrijdend mag kijken, wordt bepaald door …”. A leading
  *Wie …*, *Wat …* or *Of …* clause before an active *bepaalt X* reads as its
  subject, and the sentence then says the opposite.
- An inclusive range is *tot en met*: “2 tot en met 100”, “Tot en met dit
  aantal”. *tot* alone is read as excluding the upper bound.
- A qualifier such as "without a password prompt" goes next to the verb it
  belongs to, not next to *vrijgegeven*, where it says how something was
  granted.
- Compounds with *app*, *hash*, *glob* and *controlesom* are written as one
  word (*appwachtwoord*, *controlesomindex*, *globpatroon*); with an
  abbreviation they take a hyphen (*opslag-ID*, *map-ID*, *REST-API*).
- `{placeholders}`, `%s` and `%n` stay as they are.
