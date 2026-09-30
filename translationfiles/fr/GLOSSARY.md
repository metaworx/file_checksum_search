# French glossary

This translation is an AI draft and has not yet been reviewed by a native
speaker of French.

The terms the French translation, `fr`, uses. A new text takes them from
here; a term that changes, changes here and in the `.po` file at once.

## Registers

- `fr` is Nextcloud's French: *vous*, *votre*, as its `core` and `settings`
  texts address the reader (*Veuillez contacter votre administrateur*,
  *Vous avez révoqué …*). An instruction is in the *vous* imperative
  (*Cliquez*, *Activez*); a button, menu entry or switch is in the
  infinitive (*Modifier la règle*, *Tout vérifier*, *Prendre acte*).
- Nextcloud's French uses the generic masculine (*administrateur*,
  *vous n'êtes pas autorisé*); so does this translation.

## Terms

| English | French | Note |
|---|---|---|
| checksum | somme de contrôle, sommes de contrôle | the English's word for the value |
| hash | empreinte, empreintes | only where the English keeps *hash*: the Duplicates hash filter (« Empreinte », « Rechercher n'importe où dans l'empreinte ») |
| hashing, hash (verb) | calcul des empreintes, calculer une empreinte | the process, where the English says *hashing*; never *hacher* |
| checksum algorithm | algorithme de somme de contrôle | only in *Checksum algorithms*; elsewhere *algorithme* |
| algorithm | algorithme | |
| duplicates | doublons | the page is « Doublons » |
| rule | règle | |
| personal rule | règle personnelle | an account's rule for its own home folder; not *règle propre à un compte* |
| band | niveau, niveaux | the evaluation layer a rule falls in: « Niveau 7, position 1 »; *Priority band* is *Niveau de priorité* |
| position | position | a rule's place among the rules of the same scope in its band |
| priority | priorité | |
| scope | portée | Nextcloud's term (`files_external`); the files a rule addresses |
| selector | sélecteur | a scope as the API writes it, `home:alice`; the parameter name `selector` is typed as it is |
| place (a storage, a team folder, all home folders) | emplacement | what has, or lacks, a catch-all rule of its own |
| enforced | imposée, imposées | Nextcloud's *imposer* (*Imposer la protection par mot de passe*); agrees with *règle* |
| come before (a rule) | passer avant | an enforced rule comes before a personal one; *précéder* where the English says *precede* |
| catch-all rule | règle de repli | |
| default (rule) | règle par défaut | *Default algorithm* is *Algorithme par défaut* |
| Add rule | Ajouter une règle | the button, and the text that names it: « Ajouter une règle » |
| specific | spécifique | *Specific rules* is *Règles spécifiques* |
| type | type | a rule's type: Include / Ignore / Exclude (`occ fcias:rules:add --type`); never called its mode |
| mode | mode | a rule's mode: Auto / Missing / Force / Lazy (`--mode`); never called its type |
| Include / Ignore / Exclude | Inclure / Ignorer / Exclure | the names of a rule's *type* in the form; a lowercase `"include"` shown as a value stays English, as in *une règle de type "include"* |
| Auto / Missing / Force / Lazy | Automatique / Manquantes / Forcer / Différé | the names of a rule's *mode* in the form; they share no word with the types, and *Forcer* is kept for the mode because *enforced* is *imposée* |
| home folder | dossier personnel | Nextcloud's term (`files_sharing`) |
| team folder | dossier d'équipe | Nextcloud's term (`groupfolders`: *Dossiers d'équipe*); the selector prefix `groupfolder:` is typed as it is |
| storage | stockage | |
| external storage, external mount | stockage externe | Nextcloud's term (`files_external`) |
| raw ID | identifiant interne | *ID* alone is *identifiant* |
| account | compte, comptes | Nextcloud's *Comptes* |
| administrator | administrateur | Nextcloud's term |
| group admin | administrateur de groupe | Nextcloud's *Administrateur de groupe* (sub-admin) |
| admin group | groupe admin | the group named `admin` |
| this server | ce serveur | not *cette instance*; « la plupart des serveurs » |
| permission | autorisation | Nextcloud's term (*Autorisation refusée*) |
| app password | mot de passe d'application | Nextcloud's term |
| Security (the personal settings section) | Sécurité | Nextcloud's own name |
| filesystem access | accès au gestionnaire de fichiers | as in Nextcloud's own « Autoriser l'accès au gestionnaire de fichiers » |
| sudo token | jeton sudo | Nextcloud's *jeton* |
| grant (noun / verb) | habilitation / habiliter | kept apart from *autorisation*: a grant replaces the prompt, not the permission |
| revoke | révoquer | Nextcloud's term |
| cross-account | inter-comptes | |
| look across accounts | consulter d'autres comptes | |
| sudoers | sudoers | |
| Mine / Others | Mes fichiers / Autres comptes | the tabs |
| picker | sélecteur | Nextcloud's term |
| address (a rule addresses) | viser | |
| decides the file | décide du sort du fichier | |
| recalculate | recalculer, recalcul | the sidebar's *Recalculate* buttons are « Recalculer » |
| by hand | manuellement | Nextcloud's term |
| verify | vérifier | |
| reapply | réappliquer, réapplication | |
| indexed | indexé | |
| pending | en attente | Nextcloud's term |
| queued files | fichiers en file d'attente | |
| untrusted / eroded / reset | non fiables / caduques / réinitialisées | the states of stale checksums; they agree with *sommes de contrôle*. *Reset* is the state a reset leaves, a past participle, never the action *Réinitialiser* |
| invalidated (by a reset) | invalidées | |
| delete (checksums) / discard (a session) | supprimer | the thing is deleted, not left behind: never *abandonner* |
| enable or disable (a rule) | activer ou désactiver | the rule's *Activer* / *Désactiver* entries; not *basculer*, which Nextcloud keeps for switching a view |
| background job | tâche d'arrière-plan | Nextcloud's *Tâches d'arrière-plan* |
| rule sweep / queue drain / orphan purge / checksum copy / checksum index check | Balayage des règles / Traitement de la file d'attente / Purge des entrées orphelines / Reprise des sommes de contrôle existantes / Vérification de l'index des sommes de contrôle | |
| tunables | réglages fins | |
| prefill | préremplir, préremplissage | |
| glob pattern | motif glob | |
| path | chemin | |
| sidebar | panneau latéral | Nextcloud's term (files: « Ouvrir le panneau latéral ») |
| file sidebar | panneau latéral du fichier | Nextcloud's « Ouvrir le panneau latéral du fichier » |
| tab | onglet | |
| status | statut | Nextcloud's term (`files_external`) |
| Match anywhere in the hash | Rechercher n'importe où dans l'empreinte | the switch beside the hash filter |
| Unified Search | Recherche unifiée | Nextcloud's term (`core`) |

## Style

- The app's name, *File Checksum Index & Search*, stays English.
- A name in running text takes French quotes with a no-break space inside:
  « Tous les dossiers personnels ». A value shown or typed as it is keeps
  straight quotes and stays English: `"**"`, `"include"`, `"hash"`,
  `local::/path/`.
- A no-break space (U+00A0) comes before `:` `;` `?` `!`, and inside `« »`.
  The apostrophe is the straight one, `'`, as in Nextcloud's own terms
  (*mot de passe d'application*, *dossier d'équipe*).
- A failure names what failed: « Impossible d'enregistrer la règle. » One
  with nothing to name reads « Échec de l'enregistrement. », as the
  English's "Saving failed.".
- A dash between clauses is the en dash with spaces: ` – `.
- An ellipsis follows the word directly: *Recherche…*, as Nextcloud's
  `core` has it; the English's no-break space before it is not kept.
- `{placeholders}`, `%s` and `%n` stay as they are. The plural's second
  form, the one for exact millions, is *%n fichiers*: `%n` is written in
  digits, and French puts no *de* after a numeral, only after the noun
  *million*.
