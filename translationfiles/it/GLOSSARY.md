# Italian glossary

This translation is an AI draft, not yet reviewed by a native speaker.

The terms the Italian translation uses. A new text takes them from here; a
term that changes, changes here and in the `.po` file at once.

## Registers

- `it` addresses the reader informally, with *tu*, as Nextcloud's own
  Italian does: *Contatta il tuo amministratore*, *Puoi chiudere questa
  finestra* (core), *Copiala a mano* (settings).
- Buttons and actions are imperatives in the *tu* form, as Nextcloud's
  are: *Salva*, *Annulla*, *Elimina*, *Abilita*, *Revoca*, *Aggiungi
  regola*, *Prendi atto*. A button's tooltip is an imperative too: *Rileggi
  questo file, ricalcolane il checksum e confronta*.

## Terms

| English | Italian | Note |
|---|---|---|
| checksum | checksum (m., invariable) | the English's word for the value; the loanword Italian IT uses: *il checksum*, *i checksum* |
| hash | hash (m., invariable) | only where the English keeps *hash*: the Duplicates hash filter, “Cerca in qualsiasi punto dell'hash”, and hashing (*calcolo degli hash*); *l'hash*, *gli hash* |
| algorithm | algoritmo, algoritmi | |
| duplicates | duplicati | the page: “Duplicati” |
| rule | regola | |
| personal rule | regola personale, regole personali | an account's rule for its own home folder |
| band | fascia, fasce | the evaluation layer a rule falls in; *Fascia di priorità*, “Fascia 7, posizione 1” |
| position | posizione | a rule's place among the rules of the same scope in its band |
| priority | priorità | |
| scope | ambito | Nextcloud's `files_external` term for *Scope*; *tra le regole dello stesso ambito* |
| selector | selettore | a scope as the API writes it, `home:alice`; the parameter name `selector` is typed as it is |
| place (a storage, a team folder, all home folders) | area, aree | what has, or lacks, a catch-all rule of its own; not *posizione*, which is the rule's position |
| enforced | obbligatoria, obbligatorie | Nextcloud's settings say *obbligatoria* of enforced two-factor authentication; to enforce: *rendere obbligatoria*. Not *forzata*, which is the mode *Force* |
| catch-all rule | regola di ripiego | |
| default (rule) / default (value) | regola predefinita / predefinito | Nextcloud's *Default* → *Predefinito* |
| Add rule | Aggiungi regola | the button, and the text that names it |
| Include / Ignore / Exclude | Includi / Ignora / Escludi | a rule's type (*Tipo*), as the form names it; a rule of that type: *una regola di tipo “Includi”*. The table shows the lowercase `"include"`, `"ignore"`, `"exclude"`, which stay English |
| Auto / Missing / Force / Lazy | Automatico / Mancanti / Forzato / Differito | a rule's mode (*Modalità*), as the form names it; the option texts name their object: *ricalcola i checksum esistenti*, never a bare *gli esistenti*. The table's `"auto"`, `"missing"`, `"force"`, `"lazy"` stay English |
| home folder | cartella home | |
| team folder | cartella del team, cartelle del team | Nextcloud's `groupfolders` term; the selector prefix `groupfolder:` is typed as it is |
| share (a received one) | condivisione | Nextcloud's term (*condivisione ricevuta*); the location prefix `share:` is typed as it is |
| address (a rule's scope as one string) | indirizzo | `home:alice//Documents/**` is typed as it is |
| storage | archiviazione, archiviazioni | Nextcloud's term; *Un'archiviazione* |
| raw ID | ID interno | |
| external storage | archiviazione esterna | Nextcloud's `files_external` term |
| account | account (m., invariable) | Nextcloud's own term; *Accounts* → *Account* |
| this server | questo server | not *questa istanza*; *la maggior parte dei server* |
| administrator | amministratore | as Nextcloud has it |
| admin group | gruppo admin | |
| group admin | amministratore di gruppo | Nextcloud's sub-admin; its *Group admin for* → *Amministratore per il gruppo* |
| app password | password dell'applicazione | Nextcloud's term, invariable in the plural: *le tue password dell'applicazione* |
| filesystem access | accesso al filesystem | as in Nextcloud's own “Consenti accesso al filesystem” |
| Security (the personal settings section) | Sicurezza | Nextcloud's own name |
| sudo token | token sudo | |
| grant (noun / verb) | autorizzazione / autorizzare | a sudo token's standing permission; *resta valida finché non viene revocata*. The switch: *Autorizzata il {time}* (the app password; `{time}` is a date and time); the admin table's column: *Autorizzazione*, a noun like its neighbour *Ultimo utilizzo* |
| permission | permesso, permessi | Nextcloud's *Permissions* → *Permessi*; kept apart from *autorizzazione* |
| revoke | revocare | Nextcloud's *Revoke* → *Revoca* |
| cross-account | tra account | only as a modifier: *Letture tra account*, *le route tra account* |
| look across accounts | consultare altri account | *consultare i file di altri account* where the English says *look at other accounts' files* |
| read across accounts | leggere i dati di altri account | not *leggere tra gli account* |
| find across accounts | trovare in tutti gli account | the link: *Trova in tutti gli account*, as its tooltip says *in tutti gli account che puoi vedere* |
| sudoers | sudoers | |
| Mine / Others | I miei file / Altri account | the Duplicates page's tabs |
| recalculate | ricalcolare, *Ricalcola*; *ricalcolo* | the sidebar's buttons, so running text can call them “Ricalcola” |
| by hand | manualmente | *ricalcolare manualmente i checksum* |
| the rule on {path} | la regola per {path} | aria labels: *Modifica la regola per {path}* |
| verify | verificare | Nextcloud's *Verify* → *Verifica*; *Verify all* → *Verifica tutti* |
| reapply | riapplicare, *Riapplica*; *riapplicazione* | |
| index (verb), indexed | indicizzare, indicizzato | |
| pending (deletion) | in attesa (di eliminazione) | |
| queued files | file in coda | |
| untrusted / eroded / reset | non attendibili / decaduti / azzerati | the states of stale checksums, past participles: *Azzerati* are the checksums a reset has made invalid, not the action; a reset of the index: *azzeramento* (not *ripristino*, which reads as a restore); invalidated by it: *invalidati da un azzeramento* |
| hidden from search | esclusi dalla ricerca | not *nascosti dalla ricerca*, which reads as "hidden by the search" |
| background job | operazione in background | Nextcloud's *Background jobs* → *Operazioni in background*; not run yet: *Non ancora eseguita*, as Nextcloud's own |
| rule sweep / queue drain / orphan purge / checksum copy / checksum index check / checksum stamp check | Scansione delle regole / Svuotamento della coda / Pulizia delle voci orfane / Copia dei checksum esistenti / Controllo dell'indice dei checksum / Controllo delle marche temporali dei checksum | |
| tunables | parametri regolabili | |
| prefill | precompilare | |
| picker | selettore | Nextcloud's *profile picker* → *selettore del profilo* |
| glob pattern | modello glob | |
| path | percorso | |
| come before (a rule) | precedere | |
| sidebar | barra laterale | Nextcloud's term |
| file sidebar | barra laterale del file | Nextcloud's own: *Open file sidebar* → *Apri la barra laterale del file* |
| dialog | finestra di dialogo | |
| tab | scheda | Nextcloud's term |
| Unified Search | Ricerca unificata | Nextcloud's own name; in the store description |
| failed (an action) | impossibile / non riuscito | *Could not save the rule.* → *Impossibile salvare la regola.*; *Saving failed.* → *Salvataggio non riuscito.* |

## Style

- The app's name, *File Checksum Index & Search*, stays English.
- A name in running text takes Italian curly quotes, as Nextcloud's newer
  Italian texts do, and the exact Italian of that element's own label:
  “Tutte le cartelle home”, “Sicurezza”, “Aggiungi regola”, a type as
  “Includi”. A value shown or typed as it is keeps straight quotes and stays
  English: `"**"`, `"include"`, `"hash"`, `"—"`, `local::/path/`.
- A dash between clauses is the en dash with spaces: ` – `.
- An ellipsis follows the word without a space: “Salvataggio…”, as
  Nextcloud's own *Salvataggio…*. The no-break space the English puts
  before “…” is not carried over.
- The apostrophe is the straight `'`, as in most of Nextcloud's Italian.
- `{placeholders}`, `%s` and `%n` stay as they are.
- The plural form is Nextcloud's
  `nplurals=3; plural=n == 1 ? 0 : n != 0 && n % 1000000 == 0 ? 1 : 2;`;
  for `%n file`, all three forms read *%n file*, as in Nextcloud's `files`.
