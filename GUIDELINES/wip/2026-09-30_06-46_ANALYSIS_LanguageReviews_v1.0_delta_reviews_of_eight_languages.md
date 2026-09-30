# ANALYSIS LanguageReviews v1.0: delta reviews of eight languages

> **2026-09-30.** For AP Languages v1.5, steps 4 and 5. After the English
> was reworked ("[FIX] The English says what the app does"), one agent per
> language brought its translation up to it: 151 of the 353 texts, 135
> changed and 16 new, plus the store summary and description. A second,
> fresh agent reviewed each language read-only: the changed texts, the
> texts whose translator comment changed, the glossary and the store texts.
> The reviews came back as text; below is each one's verdict, what it
> checked, and its findings verbatim. The findings are the maintainer's to
> decide.

## Overview

| Language | Verdict | Required | Optional |
|---|---|---|---|
| French (`fr`) | ship after fixes | 2 | 1 |
| Spanish (`es`) | ship as is | 0 | 2 |
| Italian (`it`) | ship as is | 0 | 3 |
| Dutch (`nl`) | ship as is | 0 | 5 |
| Brazilian Portuguese (`pt_BR`) | ship as is | 0 | 2 |
| Simplified Chinese (`zh_CN`) | ship after fixes | 1 | 1 |
| Japanese (`ja`) | ship as is | 0 | 3 |
| Polish (`pl`) | ship after fixes | 1 | 3 |

No finding in any language concerns meaning in a changed text, except
Polish 1. Every review confirmed:

- the source texts are identical to the template, the 8 with a no-break
  space before "…" included;
- no fuzzy, untranslated or obsolete entry is left;
- the placeholders and plural forms match the English;
- `msgfmt --check --check-format` passes; the only warning is the header's
  default `PO-Revision-Date`, as before;
- every entry msgmerge had paired with the wrong old text (about 25 per
  language, most in the "Could not …" family) is translated from the new
  English;
- the summary is within 128 characters: fr 127, nl 126, es 118, pl 114,
  pt_BR 114, it 109, ja 72, zh_CN 50;
- the description ends with the machine-translation note in the language,
  then in English.

## Across languages

- **The ellipsis.** The English puts a no-break space before "…". Dutch
  keeps it. French, Spanish, Italian, Polish, Brazilian Portuguese, Chinese
  and Japanese set "…" against the word, as their typography does. Every
  reviewer accepted its language's choice.
- **"Acknowledge"**, now a button: fr *Prendre acte*, es *Tomar nota*,
  it *Prendi atto*, nl *Voor kennisgeving aannemen*, pt_BR *Marcar como
  ciente*, zh_CN 知道了, ja 了解しました (Japanese 2 proposes 了解). Each
  follows its glossary's button form, and the reviewers accepted all of
  them. Several translators noted that the idiomatic "Got it" is not an
  infinitive.
- **"at all"** in "Whether you may look across accounts at all …" is lost
  in Spanish and Brazilian Portuguese (Spanish 1, Brazilian Portuguese 1).
  It is what separates the permission from the grant.
- **"None"** is one text shown under two rows, "Queued files" and
  "Untrusted checksums". Where those two rows differ in gender, a gendered
  "None" is wrong under one of them. French 1 fixes that; the Spanish
  reviewer accepted Nextcloud's generic *Ninguno*.
- **The file sidebar** is now Nextcloud's own term in es, it and pt_BR.
  French still uses *barre latérale*, where Nextcloud says *panneau
  latéral* (French 2).
- **"recalculate a file by hand"** (the Type help text, whose English did
  not change) says the file is recalculated. The new vocabulary says a
  file's *checksum* is recalculated. Dutch 1 and Polish 3 fix the
  translation. The English itself would read better as "recalculate a
  file's checksums by hand", but changing it would turn the text fuzzy
  again in all ten languages. It is left for a later English pass.
- **The translators' process.** Writing the whole `.po` file with the Write
  tool turned the no-break space in the 8 source texts into a plain space,
  in every language. Each translator restored the texts from the template
  with `scripts/l10n.sh update`, and the mechanical check confirmed all
  eight afterwards. Editing entry by entry keeps the character.

## French (`fr`)

**Verdict: ship after the fixes below.** The delta is accurate and
consistent. Two small fixes remain: an agreement error that the new "Queued
files" label causes in an untouched text, and "sidebar" not using
Nextcloud's French term.

Checked and right:
- **Changed comments:** « Réinitialisées » and « Caduques » agree with
  *sommes de contrôle*; « Non traité » agrees with *fichier*.
- **Labels:** every « … » label quoted in running text matches its own
  translation.
- **Old terms:** none is left: *instance*, *espace de noms*, *portion*,
  *segment*, *outrepasser*, *devancer*, *règle propre à un compte*,
  *Compris*.
- **Nextcloud 34's own terms** are used: Comptes, Dossiers d'équipe,
  Administrateur de groupe, Tâches d'arrière-plan, Sécurité, Portée, Mot de
  passe d'application, Recherche unifiée.
- **Address and typography:** *vous*; no-break spaces before `: ; ? !` and
  inside « ».
- **The translator's doubts**, all kept: « Prendre acte », « accès au
  gestionnaire de fichiers » (Nextcloud's own label), « Fichiers min. »,
  « Habilitation », « La requête n'a pas pu aboutir. ». "7.1" keeps its dot
  because `priorityLabel()` in `src/rules-vue/bands.ts` writes it that way.

1. **grammar**
   - msgid: `None` (untouched; used under both "Queued files" and "Untrusted
     checksums" in `src/settings-admin-vue/App.vue`)
   - current: `Aucune`
   - proposed: `Néant`
   - reason: « Aucune » agreed with the old rows. « Fichiers en file
     d'attente » is masculine, while « Sommes de contrôle non fiables » is
     feminine, so one text serving both needs a form without gender.

2. **term**
   - msgid: every text that says *sidebar*. Three of them changed:
     - `No "Include" rule is enabled, … Recalculating by hand from the file sidebar still works.`
     - `Recalculating checksums by hand: the sidebar's "Recalculate" buttons, …`
     - `The algorithm used wherever none is named: … the sidebar's first button …`

     Three are untouched:
     - `Scripts and other apps calling this app's public API … the file sidebar …`
     - `The algorithm the Checksums tab in the file sidebar offers …`
     - `What happens when this rule matches. "include" … including the sidebar button, …`
   - current: « barre latérale des fichiers » / « barre latérale »
   - proposed: « le panneau latéral du fichier » for *file sidebar*, and
     « le panneau latéral » for *sidebar*. For example:
     - « les boutons « Recalculer » du panneau latéral »
     - « premier bouton du panneau latéral »
     - « l'onglet « Sommes de contrôle » du panneau latéral du fichier »
     - « le bouton du panneau latéral »
   - reason: Nextcloud 34's `files` app translates "Open file sidebar" as
     « Ouvrir le panneau latéral du fichier ». *files*, *viewer* and
     *related_resources* all say « panneau latéral ».

3. **grammar (optional)**
   - msgid: `Not run yet`
   - current: `Pas encore exécutée`
   - proposed: `Pas encore d'exécution`
   - reason: it sits beside the job's name, and « Balayage des règles » and
     « Traitement de la file d'attente » are masculine. A form without
     gender reads right beside every job.

Glossary: if finding 2 is taken, the rows change to
`sidebar | panneau latéral` and `file sidebar | panneau latéral du fichier`.

## Spanish (`es`)

**Verdict: ship as is.** All 151 changed texts, the four retouched ones, the
glossary and the manifest match the new English. The only findings are two
optional wording preferences.

Checked and right:
- **Changed comments:** «Caducadas» and «Invalidadas» agree with *sumas de
  verificación*. «barra lateral de archivo» is Nextcloud's own term.
- **Meaning, against the code:**
  - «Si se activa, …» fits the Enforced switch.
  - "Delete rule" is a dialog title.
  - «Autorizada» heads a column whose cells read «{time} por {account}».
- **Nextcloud 34's own terms** are used: «Seguridad», «Permitir acceso al
  sistema de archivos», «Carpeta de equipo», «Administrador de grupo para»,
  «Trabajos en segundo plano», «Búsqueda unificada».
- **Address:** *usted* throughout. Buttons are in the infinitive, and the
  subjunctive is used in filter clauses.
- **"…"** sits against the word, as Spanish sets it.
- **"None"** («Ninguno») under a feminine row stays, since it is
  Nextcloud's generic word.

1. **meaning (optional)**
   - msgid: `A granted app password may read across accounts … Whether you may look across accounts at all is still up to your administrator; a grant replaces the password prompt, not the permission.`
   - current: `… Si usted puede consultar otras cuentas sigue siendo decisión de su administrador; …`
   - proposed: `… Si usted puede o no consultar otras cuentas sigue siendo decisión de su administrador; …`
   - reason: the Spanish drops "at all", which separates the permission
     from the grant. The German keeps it with „überhaupt“.

2. **style (optional)**
   - msgid: `Up to this many, the picker opens with every account and group it may offer already in the list. Above it, the picker asks the server as you type instead. …`
   - current: `… Por encima, el selector consulta en cambio al servidor mientras usted escribe. …`
   - proposed: `… Por encima, en cambio, el selector consulta al servidor mientras usted escribe. …`
   - reason: «en cambio» is set off near the start of the clause. Between
     the verb and its object it reads awkwardly.

Glossary: optionally, the *match* row could add that the switch's label
says *buscar* while its tooltip says *hacer coincidir*.

## Italian (`it`)

**Verdict: ship as is.** All 151 changed texts say what the new English
says, terms and cross-references are consistent, and every mechanical check
passes. The three findings are optional.

Checked and right:
- **Changed comments:** "Azzerati" (a past participle) and "Decaduti";
  the job names are right.
- **Nextcloud 34's own terms** are used: Sicurezza, Consenti accesso al
  filesystem, Apri la barra laterale del file, Cartella del team,
  Amministratore per il gruppo, Ambito, Operazioni in background, Ricerca
  unificata.
- **Old terms:** none is left.
- **Address:** *tu* throughout.
- **"Rilegge il file …"** is the help text of the Recalculate section, so
  the third person is right there.

1. **term (optional)**
   - msgid: `These places exist on this server but have no catch-all rule of their own, … "Add rule" opens the dialog with a catch-all rule for the place; it is only stored once you click "Save" there.`
   - current: `Questi luoghi esistono su questo server … con una regola di ripiego per il luogo; …`
   - proposed: `Queste aree esistono su questo server ma non hanno una propria regola di ripiego, quindi sui loro file decide una regola più generale, oppure nessuna. “Aggiungi regola” apre la finestra di dialogo con una regola di ripiego per l'area; viene memorizzata solo quando lì fai clic su “Salva”.`
   - reason: *luogo* is an unusual word for a storage, a team folder or all
     home folders. *area* is common in Italian IT, and clashes with neither
     *posizione* nor *ambito*. Only this text uses the term.

2. **style (optional)**
   - msgid: `Show only groups whose hash matches what you type. Whole values come first, …`
   - current: `… Prima vengono i valori interi, …`
   - proposed: `… Prima vengono i valori completi, …`
   - reason: in a technical field *valori interi* commonly means "integer
     values".

3. **grammar (optional)**
   - msgid: `The raw ID from the storages table, … or for any storage the other choices cannot address.`
   - current: `… o per qualsiasi archiviazione che le altre scelte non possono indicare.`
   - proposed: `… o per qualsiasi archiviazione che le altre scelte non possano indicare.`
   - reason: a relative clause after *qualsiasi* takes the subjunctive in
     written Italian.

Glossary: if finding 1 is taken, `place | luogo, luoghi` becomes
`area, aree`.

## Dutch (`nl`)

**Verdict: ship as is.** All 151 changed texts and the store texts say what
the new English says, in the glossary's terms, and they pass the mechanical
checks. The five findings are all optional.

Checked and right:
- **Nextcloud's own Dutch** is used: "Geünificeerd zoeken", "Toestaan
  toegang bestandssysteem" (its exact label), "Groepsbeheerder voor",
  "Teammappen", "Beveiliging", "Achtergrondtaken", "inkomende shares".
- **Band:** *laag* is used the same way everywhere.
- **Grammar and address:** *je* throughout, and buttons are in the
  infinitive. The failure texts read "Kon de regel niet opslaan." /
  "Opslaan mislukt.".
- **The translator's doubts:** "Voor kennisgeving aannemen" is kept: it is
  an infinitive like „Zur Kenntnis nehmen“, and its sense fits a button
  that only hides the banner. "locaties" is also kept.

1. **term (optional)**
   - msgid: `What happens when this rule matches. … "Ignore" stops automatic hashing but still lets someone recalculate a file by hand. …`
   - current: `… maar laat nog steeds toe dat iemand een bestand handmatig opnieuw berekent. …`
   - proposed: `… maar laat nog steeds toe dat iemand de controlesom van een bestand handmatig opnieuw berekent. …`
   - reason: this text did not change, but it breaks the glossary's note "a
     file's checksum is recalculated, not the file". It is optional because
     the English says "recalculate a file".

2. **term (optional)**
   - msgid: `When on, every account may look across all accounts after confirming their password. When off, …`
   - current: `Indien ingeschakeld, mag elk account na bevestiging van zijn wachtwoord in alle accounts kijken. …`
   - proposed: `Indien ingeschakeld, mag elk account na bevestiging van zijn wachtwoord de bestanden van alle accounts bekijken. …`
   - reason: "in alle accounts kijken" can read as looking into the accounts
     themselves. The glossary gives "de bestanden van andere accounts
     bekijken", as another text already does.

3. **style (optional)**
   - msgid: `The sudoers: … A group admin may also look at the members of the groups they administer without being selected. …`
   - current (second sentence): `Een groepsbeheerder mag bovendien, zonder geselecteerd te zijn, kijken bij de leden van de groepen die hij of zij beheert.`
   - proposed: `Groepsbeheerders mogen bovendien, ook zonder geselecteerd te zijn, de bestanden bekijken van de leden van de groepen die ze beheren.`
   - reason: the plural drops "hij of zij". Naming the files says what
     "look at" means, as the German does.

4. **style (optional)**
   - msgid: `Min. files`
   - current: `Min. bestanden`
   - proposed: `Min. aantal bestanden`
   - reason: the field holds a count of files (2 to 100), and "min." alone
     is also Dutch for *minuten*.

5. **style (optional)**
   - msgid: `Choose accounts or groups`
   - current: `Kies accounts of groepen`
   - proposed: `Accounts of groepen kiezen`
   - reason: this text did not change. It is now the only imperative
     placeholder among the pickers, beside "Accounts zoeken …", "Groepen
     selecteren …" and "Map selecteren …". It is optional because
     Nextcloud's Dutch often says "Kies …".

Glossary:
- `pending | openstaand` is never used. Change it to
  `pending (deletion) | in afwachting van (verwijdering)`.
- `authorisation | autorisatie` is no longer in the English. Remove it.

## Brazilian Portuguese (`pt_BR`)

**Verdict: ship as is.** All 151 changed texts say what the new English
says, and they use the glossary's terms and cross-references correctly. The
two findings are optional wording preferences.

Checked and right:
- **Changed comments:** "Eroded" → *Descartadas* and "Reset" →
  *Redefinidas*, both feminine past participles.
- **Terms changed across the file:**
  - 9 "hashing" texts now say *cálculo de somas de verificação*;
  - 2 file-sidebar texts now say *barra lateral de arquivo*.
- **Nextcloud's own terms** are used: *Segurança*, *Permitir acesso ao
  sistema de arquivos*, *Pasta de equipe*, *Tarefas em segundo plano*,
  *Pesquisa unificada*.
- **Address:** *você* throughout, and buttons are in the infinitive.
- **The translator's doubts** are all acceptable.

1. **style (optional)**
   - msgid: `App passwords that may use the cross-account routes without a password prompt. … Who may look across accounts at all is decided under "Who may look across accounts"; a grant replaces the password prompt, not the permission.`
   - current: `… Quem pode, afinal, consultar outras contas é decidido em “Quem pode consultar outras contas”; …`
   - proposed: `… Quem pode ou não consultar outras contas é decidido em “Quem pode consultar outras contas”; …`
   - reason: *afinal* reads as "after all", not "at all". *pode ou não*
     matches the sudo-tokens help text.

2. **style (optional)**
   - msgid: `Checksum index check`
   - current: `Verificação do índice de somas de verificação`
   - proposed: `Conferência do índice de somas de verificação`
   - reason: *verificação* appears twice in a short job name. *Conferência*
     also keeps the job apart from the Duplicates page's *Verificar*.

Glossary: if finding 2 is taken, the job row follows it. Optionally add
`Unified Search | Pesquisa unificada`.

## Simplified Chinese (`zh_CN`)

**Verdict: ship after the fixes below.** The 151 changed texts are right in
meaning, terms and mechanics. The one required fix is in an unchanged text:
it still uses 哈希值 where the glossary now calls for 哈希计算.

Checked and right:
- **Nextcloud 34's zh_CN** has the same terms: 账号, 团队文件夹, 适用范围,
  应用密码, 后台任务, “安全”, “允许访问文件系统”, 统一搜索, 文件侧边栏.
- **The translator's doubts:**
  - 分组管理员 is Nextcloud's own label for a group admin, and Nextcloud
    itself mixes 分组 and 群组.
  - 区域 fits "place": 存储位置 would be wrong for all home folders or a
    team folder.
  - 不区分大小写 is right, since the server lowercases the search text.
- **Address:** 您 throughout.
- **Punctuation:** no ASCII punctuation next to Chinese characters.

1. **term**
   - msgid: `Ignore (no automatic hashing; still allowed on request)`
   - current: 忽略（不自动计算哈希值；仍可按需计算）
   - proposed: 忽略（不自动进行哈希计算；仍可按需计算）
   - reason: the English says *hashing*, which the glossary gives as
     哈希计算; 哈希值 is for *hash*. It also matches the option beside it,
     "排除（从不对这些文件进行哈希计算）".

2. **style (optional)**
   - msgid: `Which files get checksums, with which algorithms, and which are left alone. …`
   - current: 哪些文件获得校验和、使用哪些算法，以及哪些文件不做处理。…
   - proposed: 为哪些文件计算校验和、使用哪些算法，以及哪些文件不予处理。…
   - reason: 获得校验和 reads as a literal copy of "get checksums".

Glossary:
- The *hash* note names a “哈希值” column that does not exist. It should
  say the Duplicates filter field.
- `pending | 待处理` is unused. Change it to
  `pending (deletion) | 等待……删除`, or drop it.
- `personal settings | 个人设置` can go.

## Japanese (`ja`)

**Verdict: ship as is.** Each of the 151 changed texts says what the new
English says, the terms and cross-references are consistent, and all checks
pass. The three findings are optional.

Checked and right:
- **Accounts:** アカウント is used for every account. ユーザー remains only
  in "Who may …" headings and ユーザーガイド.
- **Old terms:** none is left: インスタンス, 名前空間, 区分, ユーザー自身のルール.
- **Nextcloud's own terms** are used: セキュリティ, ファイルシステムへのアクセスを許可,
  統合検索, バックグラウンドジョブ.
- **Politeness:** desu/masu in sentences, and noun or dictionary form in
  labels.
- **Typography:** no space before 「…」.
- **Store description:** the half-width space after `**REST API (v1)**` is
  needed for the bold to close.

1. **meaning (optional)**
   - msgid: `folder missing`
   - current: フォルダーなし
   - proposed: フォルダー利用不可
   - reason: the badge says the rule's team folder, or the app providing
     it, is not available, and its hint uses 利用できない. 「フォルダーなし」
     can read as "no folder set".

2. **register (optional)**
   - msgid: `Acknowledge`
   - current: 了解しました
   - proposed: 了解
   - reason: it is now a button, and the glossary gives buttons a noun or
     dictionary form. 了解しました is the polite past kept from the old
     "Acknowledged".

3. **style (optional)**
   - msgid: `the file changed while no rule maintained its checksums, so they were deleted; it gets them back once a rule covers it again`
   - current: …再びルールの対象になると、チェックサムが再び計算されます
   - proposed: …再びルールの対象になると、チェックサムは再計算されます
   - reason: 再び appears twice in one clause, and 再計算 is the glossary's
     word for recalculate.

Glossary:
- Findings 1 and 2 change their rows.
- Add a row for 無効にする (noun 無効化), which "disable" now shares with
  "invalidated".

## Polish (`pl`)

**Verdict: ship after the fixes below.** The Polish matches the new English
with correct terms and passing mechanics, but one sentence in the sudoers
help can be read as saying the password is valid for 30 minutes.

Checked and right:
- **"Recalculate" → „Przelicz”:** one text serves as both the section
  heading and the button.
- **Old terms:** none is left: własna reguła konta, wycinek, przestrzeń
  nazw, instancja, Przeliczanie.
- **Nextcloud's own terms:** checked in core, settings, files, groupfolders
  and files_external.
- **Address:** informal, with *Twoje* capitalised.
- **The translator's doubts** are all acceptable. „włączyć lub wyłączyć” is
  right, since the negation sits on *udało się*.

1. **meaning**
   - msgid: `The sudoers: … Looking across accounts still needs a password confirmation, which holds for 30 minutes, or a granted app password; this only says who may be asked.`
   - current: `… Wgląd w inne konta nadal wymaga potwierdzenia hasła, ważnego przez 30 minut, albo upoważnionego hasła aplikacji; …`
   - proposed: `… Wgląd w inne konta nadal wymaga ważnego przez 30 minut potwierdzenia hasła albo upoważnionego hasła aplikacji; …`
   - reason: ", ważnego przez 30 minut" attaches to the nearest noun, *hasła*,
     so it reads "a password valid for 30 minutes".

2. **style (optional)**
   - msgid: `Match anywhere in the hash`, and the text that names it
   - current: `Dopasowanie w dowolnym miejscu hasha` / `… Włącz „Dopasowanie w dowolnym miejscu hasha”, aby …`
   - proposed: `Dopasowuj w dowolnym miejscu hasha` / `… Włącz „Dopasowuj w dowolnym miejscu hasha”, aby …`
   - reason: Nextcloud's Polish switches use the imperative („Pokaż ukryte
     pliki”, „Wymuszaj zabezpieczenie hasłem”), as the app's own switches
     do.

3. **term (optional; untouched text)**
   - msgid: `What happens when this rule matches. … "Ignore" stops automatic hashing but still lets someone recalculate a file by hand. …`
   - current: `… ale nadal pozwala ręcznie przeliczyć plik. …`
   - proposed: `… ale nadal pozwala ręcznie przeliczyć sumy kontrolne pliku. …`
   - reason: the glossary says what is recalculated is a file's checksum,
     never the file. This is the only text left that recalculates the file.

4. **manifest (optional)**
   - element: `<description lang="pl">`, the Checksums line
   - current: `… pokazuje każdą sumę kontrolną zapisaną dla pliku, przeliczaną na żądanie, a tuż pod nimi duplikaty pliku.`
   - proposed: `… pokazuje każdą sumę kontrolną zapisaną dla pliku, przeliczaną na żądanie, a na tej samej karcie także duplikaty pliku.`
   - reason: the plural *nimi* follows the singular *każdą sumę
     kontrolną*. „tuż pod nimi” also claims the duplicates sit right below
     the checksums, when the Recalculate section is between them.

Glossary:
- The register example *ponieważ wskazujesz je z nazwy* is gone from the
  `.po`. Swap in a current one, or drop it.
- The *hash* row could add the store texts' „po hashu”.
- If finding 2 is taken, the two *hash* rows follow it.
