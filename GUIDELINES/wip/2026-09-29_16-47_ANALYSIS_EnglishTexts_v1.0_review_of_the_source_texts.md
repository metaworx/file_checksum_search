# ANALYSIS EnglishTexts v1.0: review of the English source texts

> **2026-09-29.** The research behind AP Languages v1.4: a read-only review of
> the app's 360 English texts and its manifest summary and description,
> against the code and against Nextcloud's own English, made after the
> reviews of ten translations showed that several of their errors came from
> the English. Reproduced verbatim below, its headings one level down. Its
> `en/` paths are the review's snapshot of
> `translationfiles/templates/file_checksum_search.pot` and
> `appinfo/info.xml` at `180893c`.

## Review of the English texts

Source: `en/file_checksum_search.pot` (360 texts) and the English
`<summary>`/`<description>` of `en/info.xml`, read against the code in
`nc_file_checksum_search` (working tree of 2026-09-29) and against Nextcloud's
own English in `nextcloud-server` (core, `apps/files`, `apps/settings`,
1,909 strings counted). Paths below are relative to the app's root.

### Verdict

The English is careful. The help texts explain more than most Nextcloud apps
do, and nearly all of them are right. The ones that are wrong are the ones
that matter most. Several texts about the rule model describe it more broadly
than the code: drag and position work per scope, not per band; the "not
covered" placeholder ignores more general rules; a personal rule is said to
override a team-folder rule it can never reach. Two permission texts
misstate what happens. Accounts without the recalculation permission still
see the Verify buttons, and looking across accounts does not ask for the
password "each time"; group admins can look too without being selected.
Terms drift in predictable pairs: user/account, group folder/team folder,
checksum/hash, calculate/recalc/recompute, and scope/"Applies to"/slice. The
translations already say "account" and "team folder", so the English source
is the one lagging behind. Conventions are consistent within the app but
differ from Nextcloud's in five places: title-case headings, the ellipsis
spacing, curly quotes, the British "authorisation", and a lower-case "id".

Findings: **accuracy 17**, **term 12** (2 optional), **convention 8**
(2 optional), **clarity 22** (4 optional), **grammar 4** (2 optional).
Separate from those: two reading notes on decided texts and two notes on
English outside the template.

### Target vocabulary

Every proposal below already uses this vocabulary, so the proposals can be
applied together.

| Concept | Word | Not |
|---|---|---|
| the login entity | account | user (Nextcloud renamed it: `839ddaa3547`, `6f63ecfc802`) |
| a person | everyone, nobody, people | users |
| a rule on one account's home, not enforced | personal rule | user rule, a user's own rule |
| the groupfolders app's folder | team folder | group folder |
| the value | checksum | hash, except in the Duplicates hash filter (decided, see T3) |
| making the value | compute (a rule), recalculate (by hand), verify (Duplicates: recalculate and compare) | calculate by hand, recalc, recompute, trigger a computation |
| what a rule addresses | scope | Applies to, slice, file universe, namespace |
| evaluation layer | band | segment |
| an account's own storage | home folder | home, personal folder |
| an app password allowed to use the sudo routes | grant, granted app password | token, standing authorisation |
| a Nextcloud sub-admin | group admin | group leader |

### Decided texts: reading check

- "invalidated by a reset: already hidden from search, and pending deletion by the background job": reads correctly.
- "Select folder…": reads correctly. Only its ellipsis spacing would change under K2.
- "Match anywhere in the hash", and the hash help that names it: the switch reads correctly. The help's first sentence, "Show only groups whose checksum this names", is hard to parse ("this" is the typed value) and mixes checksum and hash. If it is ever reopened: "Show only groups whose hash matches what you type." See T3.
- "Min. files" / "Per page": read correctly with their comments.
- Priority help, "7.1 is the first rule of band 7": correct, because band 7 holds a single scope. The clause around it, "its position in that band", is not correct (A1).
- "Acknowledge": reads correctly.
- Summary: reads correctly.
- Description, first line, "so a file can be found by its checksum and duplicates at once": it can be parsed as "found by its checksum and by its duplicates". The intended reading is "and duplicates are found at once". If it is ever reopened: "so a file can be found by its checksum, and its duplicates at once."

---

### Accuracy

**A1 · accuracy**: drag and position work per scope, not per band
- Code: `listRulesFor()` numbers positions per `band|selector` (`lib/Service/RuleService.php:1498-1507`). The drag only drops on the same selector and the same catch-all partition (`src/rules-vue/RuleTable.vue:150-160`). The server permutes one segment partition only (`RuleService.php:1377-1415`). The user guide already says "the second rule of its group in band 5".

| msgid | where | proposed |
|---|---|---|
| Drag to reorder within this band | `src/rules-vue/RuleRow.vue:105` | Drag to reorder among the rules of the same scope |
| Evaluated top to bottom — … Drag a rule by its handle to reorder it within its band; to move it between bands, change its scope or its Enforced flag. | `src/settings-admin-vue/App.vue:59` | Evaluated top to bottom — the first matching rule decides the file. A rule's band follows from its scope and whether it is enforced, so enforced rules always precede personal rules, and the catch-all default is last. Drag a rule by its handle to reorder it among the rules of the same scope; to move it to another band, change its scope or its "Enforced" setting. |
| Where the rule sits in evaluation order: its band and its position in that band, … which is what dragging changes. | `src/rules-vue/RuleTable.vue:85` | Where the rule sits in evaluation order: its band, and its position among the rules of the same scope in that band, joined by a dot, so 7.1 is the first rule of band 7. The first rule that matches a file decides it outright, so a lower number is stronger. The band follows from the rule's scope and whether it is enforced — it is never chosen directly — and the position is what dragging changes. |
| Rules are evaluated in band order … and reordering only moves a rule within its own band. | `src/rules-vue/RuleForm.vue:235` | Rules are evaluated in band order and the first match decides the file. A rule's band follows from its scope and whether it is enforced — it is not chosen directly, and reordering only moves a rule among the rules of the same scope. |

- Why: in bands 1, 2, 5 and 6, which hold several scopes, the texts promise drops that the table refuses.

**A2 · accuracy**: the recalculation permission reaches beyond the sidebar
- msgid: "Triggering a computation of one's own files — the sidebar's Recalculate buttons and the API's recalc route — on top of owning them. Reading what is already computed is untouched; an account not named here simply does not see the buttons."
- Where: `src/settings-admin-vue/App.vue:60`, plus the three helps at `App.vue:32-34` ("…of their own files by hand…").
- Code:
  - Only the sidebar hides its section (`src/sidebar-vue/ChecksumsSidebarTab.vue:174`).
  - "Verify" and "Verify all" on the Duplicates page are always shown (`src/duplicates-vue/components/DuplicateGroup.vue:76-83, 107-114`). They answer "This account may not calculate by hand." (`lib/Public/ChecksumApi.php:659-665`).
  - The same check applies on the Others tab to other accounts' files, so "own files" / "owning them" is too narrow.
- Proposed (App.vue:60): "Recalculating checksums by hand: the sidebar's "Recalculate" buttons, "Verify" and "Verify all" on the Duplicates page, and the API's recalculation routes, for any file the account can reach. Reading checksums already computed is not affected. Without this permission the sidebar hides its buttons; the Duplicates page keeps them and refuses the request."
- Proposed (App.vue:32-34): see T4 ("…may recalculate checksums by hand…", without "of their own files").
- Alternative: hide the Verify buttons as the sidebar does. The last sentence of the proposal then becomes "…does not see the buttons."
- Why: an administrator reads that the buttons disappear, but on the Duplicates page they stay and fail.

**A3 · accuracy**: looking across accounts does not ask every time, and group admins may look too
- msgid: "The sudoers: members of the admin group always, plus the groups and users selected here. Looking across accounts still costs a password confirmation each time; this only says who may be asked."
- Where: `src/settings-admin-vue/App.vue:62`.
- Code:
  - One confirmation holds for 30 minutes (`lib/Service/SudoConfirmation.php:40`), and a granted app password needs none.
  - A group admin may look at the members of the groups they administer without being selected (`lib/Service/SudoScope.php:21-26, 104-119`; user guide "Looking at other accounts").
- Proposed: "The sudoers: members of the admin group always, plus the groups and accounts selected here. A group admin may also look at the members of the groups they administer without being selected. Looking across accounts still needs a password confirmation, which holds for 30 minutes, or a granted app password; this only says who may be asked."
- Why: an administrator who clears the selection would believe nobody but admins can look, and would expect a prompt on every look.

**A4 · accuracy**: rules are not applied on file events only
- msgid: "Which algorithms are computed for which files, on real-time file events."
- Where: `src/settings-admin-vue/App.vue:58`.
- Code: rules also decide ignore and exclude. The rule sweep applies them every 300 s (`lib/BackgroundJob/RuleProcessingJob.php:66-72, 97-99`), and "Re-apply" applies one on request.
- Proposed: "Which files get checksums, with which algorithms, and which are left alone. Rules act when a file is created or changed, and a background job applies them to existing files every few minutes."
- Why: the text hides both the verdicts and the sweep.

**A5 · accuracy**: new rules in the dialog do not start from the default algorithm
- msgid: "The algorithm used wherever none is named: new rules, the command line, the API, and the sidebar's first button for users who have not chosen one of their own. Only an allowed algorithm can be the default; removing the default from the list above moves it to the first remaining."
- Where: `src/settings-admin-vue/AlgorithmSection.vue:46`.
- Code:
  - The New rule dialog always seeds SHA-1 (`src/rules-vue/RuleForm.vue:86, 104`).
  - "Create rule" seeds SHA-1 + MD5 (`src/settings-admin-vue/App.vue:223`).
  - Only a rule sent without algorithms through the API or occ gets the default (`lib/Service/RuleDefinitionValidator.php:109`).
- Preferred: fix the code so the dialog seeds the default. Otherwise use this text: "The algorithm used wherever none is named: a rule created through the API or the command line without algorithms, a recalculation that names none, and the sidebar's first button for anyone who has not chosen one. Only an allowed algorithm can be the default; removing the default from the list above makes the first remaining one the default."
- Why: an administrator who sets SHA-256 as the default and opens "Add rule" finds SHA-1.

**A6 · accuracy**: placeholder notes ignore the more general rules
- Where: `src/rules-vue/RuleTable.vue:274-275`.
- Code: an enabled `*` rule (the shipped "Everything" default, `lib/Migration/RepairQuietStart.php:267-291`) reaches every storage and team folder. The section help (`RuleTable.vue:129`) says so itself.

| msgid | proposed |
|---|---|
| not covered — no rule addresses it, so nothing is hashed there | no rule of its own — its files are left to a more general rule, if one is enabled |
| no catch-all rule — only the specific rules above apply here | no catch-all rule — files the rules above do not match are left to a more general rule, if one is enabled |

- Why: once "Everything" is enabled, "nothing is hashed there" is false.

**A7 · accuracy**: a personal rule never reaches a team folder
- msgid: "Rules for a group's members, or for one group folder. Not enforced: a user's own rule overrides them for their files."
- Where: `src/rules-vue/bands.ts:152`.
- Code: a personal rule is `home:<uid>` and is refused for a team folder (`lib/Service/RuleService.php:1570-1574, 1645-1651`).
- Proposed: "Rules for a group's members, or for one team folder. Not enforced: for a member's home folder, their personal rule comes first."
- Why: half of the band cannot be overridden by what the text names.

**A8 · accuracy**: personal rules only cover writable folders in one's own files
- Code: a writable received share or team folder is refused (`lib/Service/RuleService.php:1645-1656`).

| msgid (start) | where | proposed |
|---|---|---|
| When on, every user of this instance may create and edit rules for folders they can write to. … | `src/settings-admin-vue/App.vue:27` | When on, every account may create and edit rules for folders in their own files that they can write to. When off, only the groups and individual accounts you select may. |
| Members of these groups may create and edit rules for folders they can write to. … | `App.vue:28` | Members of these groups may create and edit rules for folders in their own files that they can write to. Selected groups and selected accounts are combined — being in either is enough. |
| Individual users who may create and edit rules for folders they can write to, … | `App.vue:29` | Individual accounts that may create and edit rules for folders in their own files that they can write to, in addition to the members of any selected groups. |
| Creating and editing rules for folders one can write to. … | `App.vue:61` | Creating and editing rules for folders in one's own files that one can write to. An administrator always may; a rule an administrator has enforced is read-only for everyone else regardless. |
| Every rule that can affect your files, … and the path is in a folder you can write to. | `src/settings-personal-vue/App.vue:57` | …You can create rules only if you have been given permission and the path is a folder in your own files that you can write to. (rest unchanged) |

- Why: a person with the permission tries a shared folder and is refused, although the text says they may.

**A9 · accuracy**: "provider missing" is only ever about team folders
- msgid: "The app or storage this rule names is not available, so the rule can never match." and the badge "provider missing".
- Where: `src/rules-vue/RuleRow.vue:80`, `RuleRow.vue:124`.
- Code: `providerMissing()` is true only for a `groupfolder:` rule (`src/rules-vue/RuleTable.vue:285-294`).
- Proposed: "The team folder this rule names, or the app that provides it, is not available, so the rule can never match." Badge: "folder missing".
- Why: no storage rule ever gets the badge, and "provider" is jargon.

**A10 · accuracy**: the prefill limit is inclusive
- msgid: "Below this many, the picker opens with every account and group it may offer already in the list. Above it, … Applies to administrators and group leaders alike."
- Where: `src/settings-admin-vue/TunablesSection.vue:25`.
- Code: `count <= $threshold` (`lib/Service/SudoScope.php:378`).
- Proposed: "Up to this many, the picker opens with every account and group it may offer already in the list. Above it, the picker asks the server as you type instead. Applies to administrators and group admins alike." ("group admins": T9.)
- Why: "Below" excludes the limit itself.

**A11 · accuracy**: "supported" means allowed
- Code: all three messages come from `AlgorithmCatalogue::isValid()`, which checks the administrator's allowed list, not what PHP supports (`lib/Service/AlgorithmCatalogue.php:181-183`).

| msgid | where | proposed |
|---|---|---|
| Unsupported algorithm: %s | `lib/Service/HashCalculationService.php:874` | Algorithm not allowed on this server: %s |
| Not an algorithm this instance computes: %s | `lib/Controller/PublicApiController.php:484` | Algorithm not allowed on this server: %s (same text) |
| At least one supported algorithm is required. | `lib/Service/RuleDefinitionValidator.php:127` | At least one allowed algorithm is required. |

- Why: an algorithm that PHP supports but the administrator has not allowed is reported as "unsupported". The two messages for one check become one.

**A12 · accuracy**
- msgid: "Only groups of this algorithm, or every algorithm at once. The list is what this server computes; an algorithm nobody has enabled is not offered."
- Where: `src/duplicates-vue/components/DuplicateListing.vue:65`.
- Proposed: "Only groups of this algorithm, or every algorithm at once. The list holds the algorithms this server computes; one the administrator has not allowed is not offered."
- Why: the list is the administrator's allowed list. "Nobody has enabled" suggests rules or people.

**A13 · accuracy**
- msgid: "These are other people's files. Everything below is shown because you asked for it by name — leave this tab to go back to your own."
- Where: `src/duplicates-vue/App.vue:140`.
- Proposed: "This tab shows other people's files: those of the accounts and groups chosen above. Leave this tab to go back to your own."
- Why: "All accounts" and "All my groups" are not "by name" (`src/duplicates-vue/components/TargetPicker.vue:52`).

**A14 · accuracy**
- msgid: "The list of accounts could not be loaded."
- Where: `src/duplicates-vue/components/TargetPicker.vue:224`.
- Proposed: "Could not load the accounts and groups." (K4)
- Why: the list holds groups too.

**A15 · accuracy**
- msgid: "\"Yes\" means an administrator set this rule and users cannot override or disable it from their personal settings. Enforced rules fill the first four bands, so no rule of a user's own can outrun one."
- Where: `src/rules-vue/RuleTable.vue:123`.
- Proposed: "\"Yes\" means an administrator enforced this rule: nobody else can edit or disable it, and since enforced rules fill the first four bands, no personal rule can come before it."
- TRANSLATORS (line above 123): `// TRANSLATORS: "Yes" is the value this column shows; translate it as that does`
- Why: an administrator sets many rules that are not enforced. "outrun": C3. "user": T1.

**A16 · accuracy**: manifest description (English only; per contract §3.7 the German description changes in the same commit)
- `appinfo/info.xml:17`: "**occ commands** for hashing, searching, duplicates, rules, backup, import and export." There is no export command; `backup` is the export (`lib/Command/Backup.php:53-55`). Proposed: "…rules, backup and import."
- `appinfo/info.xml:14`: "…every group of identical files you hold, and for administrators across every account." Accounts with "look across accounts" and group admins see the Others tab too. Proposed: "…you hold, and, for those allowed to look across accounts, other accounts' files too."

**A17 · accuracy**
- Where: `src/duplicates-vue/components/DuplicateListing.vue:77-78`.

| msgid | proposed |
|---|---|
| Show only groups whose checksum starts with this | Show only groups whose hash matches this |
| Whole or start of a checksum | Whole hash or part of one |

- Why: with "Match anywhere in the hash" on, the match is not only at the start. These use "hash" to agree with their field (T3).

---

### Terms

**T1 · term**: account, not user; personal rule, not a user's own rule
Nextcloud's own English renamed the entity to "account" (settings: 56 account, 14 user; commits `839ddaa3547` "rename users to account or person" and `6f63ecfc802`). All ten translations already say "account". The texts mix both, even in one picker: "User" label, "Search users…", with a tooltip "One account's home folder".

| msgid | where | proposed |
|---|---|---|
| A single user | `src/rules-vue/RuleForm.vue:317` | A single account |
| User | `RuleForm.vue:366, 381` | Account |
| Users | `src/settings-admin-vue/PermissionSection.vue:148, 151` | Accounts |
| Search users… | `RuleForm.vue:372`, `PermissionSection.vue:149` | Search accounts … (K2) |
| Unknown user. | `lib/Service/RuleDefinitionValidator.php:174` | Unknown account. |
| This user may not apply this rule. | `lib/Public/ChecksumApi.php:1164` | This account may not apply this rule. |
| This user may not change this rule. | `ChecksumApi.php:1100, 1139` | This account may not change this rule. |
| This user may not edit rules. | `ChecksumApi.php:1060` | This account may not edit rules. |
| The one user whose home folder this rule addresses. | `RuleForm.vue:231` | The one account whose home folder this rule addresses. |
| Users may not edit this rule | `RuleForm.vue:467` | Only administrators can edit or disable this rule (C18) |
| Allow all users to calculate by hand | `src/settings-admin-vue/App.vue:465` | Allow all accounts to recalculate checksums (T4) |
| Allow all users to edit rules | `App.vue:476` | Allow all accounts to edit rules |
| Allow all users to look across accounts | `App.vue:487` | Allow all accounts to look across accounts |
| Allow all users to use the API | `App.vue:498` | Allow all accounts to use the API |
| When on, every user of this instance may create and edit rules … | `App.vue:27` | see A8 |
| When on, every user may trigger a computation of their own files by hand. When off, only the groups and individual users selected here may; … | `App.vue:32` | When on, every account may recalculate checksums by hand. When off, only the groups and individual accounts selected here may; everyone still sees the checksums already computed. |
| When on, every user may call the public API with an app password. When off, only the groups and individual users selected here may; … | `App.vue:37` | When on, every account may call the public API with an app password. When off, only the groups and individual accounts selected here may; the app's own pages keep working for everyone. |
| When on, every user may switch to the instance-wide view after confirming their password. When off, only members of the admin group and the groups and users selected here may. | `App.vue:42` | When on, every account may look across all accounts after confirming their password. When off, only members of the admin group and the groups and accounts selected here may. |
| Members of these groups may … Selected groups and selected users are combined — being in either is enough. (×4) | `App.vue:28, 33, 38, 43` | …Selected groups and selected accounts are combined — being in either is enough. (28: A8; 33: "Members of these groups may recalculate checksums by hand.") |
| Individual users who may create and edit rules … | `App.vue:29` | see A8 |
| Individual users who may trigger a computation of their own files by hand, … | `App.vue:34` | Individual accounts that may recalculate checksums by hand, in addition to the members of any selected groups. |
| Individual users who may call the public API … | `App.vue:39` | Individual accounts that may call the public API with an app password, in addition to the members of any selected groups. |
| Individual users who may look across accounts … | `App.vue:44` | Individual accounts that may look at other accounts' files once they have confirmed their password, in addition to the members of any selected groups and of the admin group. |
| The sudoers: … the groups and users selected here … | `App.vue:62` | see A3 |
| When set, users cannot override or disable this rule from their personal settings. Enforced rules are evaluated before every user rule, so they cannot be outrun. | `RuleForm.vue:228` | When set, nobody else can edit or disable this rule. Enforced rules are evaluated before every personal rule, so none can come before them. |
| "Yes" means … | `src/rules-vue/RuleTable.vue:123` | see A15 |
| Administrator-enforced rules aimed at one specific slice — a single user's home or one storage. … | `src/rules-vue/bands.ts:147` | Administrator-enforced rules for one account's home folder or one storage. No other rule comes before them there, and the account cannot edit or disable them. |
| Rules for one specific slice — users' own rules for their homes, or a rule for one storage. … | `bands.ts:151` | Rules for one account's home folder — personal rules — or for one storage. They decide a file only where no enforced rule matched it first. |
| Rules for a group's members … a user's own rule overrides them … | `bands.ts:152` | see A7 |
| Evaluated top to bottom — … users' own rules … | `App.vue:59` | see A1 |
| The algorithm used wherever none is named: … for users who have not chosen one of their own. … | `src/settings-admin-vue/AlgorithmSection.vue:46` | see A5 |
| Which slice of files the rule addresses: one user's home folder, … | `RuleTable.vue:90` | see T2 |
| Which slice of the file universe this rule addresses: … a single user, … | `RuleForm.vue:222` | see C9 |

- Keep: "User Guide" (the reader's role, not the entity); K1 makes it "User guide".
- Why: one entity with two names, where Nextcloud and every translation use one.

**T2 · term**: team folder, not group folder
The groupfolders app calls itself "Team folders" on the releases this app supports (33 and later). The app already shows that name where it asks the app (`lib/Service/GroupFolderService.php:83-104`). Static texts still say "group folder". Every translation already says the equivalent of "team folder".

| msgid | where | proposed |
|---|---|---|
| A group folder | `src/components/LocationIcon.vue:51` | A team folder |
| Group folder | `src/rules-vue/RuleTable.vue:239` | Team folder |
| Groups & group folders | `src/rules-vue/bands.ts:41` | Groups & team folders |
| Enforced — groups & group folders | `bands.ts:37` | Enforced — groups & team folders |
| Administrator-enforced rules for the members of a group, or for one group folder. … | `bands.ts:148` | …or for one team folder. Only an enforced rule aimed at something more specific comes before them. |
| Rules for a group's members, or for one group folder. … | `bands.ts:152` | see A7 |
| The last resort, covering every storage — external mounts and group folders included. … | `bands.ts:154` | The last resort, covering every storage — external storage and team folders included. Enable deliberately: it can reach storage that is slow or costs money to read. |
| The home-folders default covers home folders only: files on external storage and in group folders are reached only by the “Everything” default or by their own group-folder or storage rules. | `src/settings-admin-vue/App.vue:52` | The "All home folders" default covers home folders only: files on external storage and in team folders are reached only by the "Everything" default or by their own team-folder or storage rules. |
| Which slice of files the rule addresses: … one group folder, … also reaches group folders and external storage. | `RuleTable.vue:90` | Which files the rule addresses: one account's home folder, the home folders of one group's members, "All home folders", one team folder, one storage by its ID, or "Everything". A rule only ever meets files inside its scope — "All home folders" reaches every home folder and nothing else, while "Everything" also reaches team folders and external storage. |
| Which slice of the file universe … one group folder, … group folders included. | `src/rules-vue/RuleForm.vue:222` | see C9 |
| comment above "{folders} — one folder": "the group folders app" | `RuleForm.vue:210` | `// TRANSLATORS: {folders} is what the team folders app calls itself, e.g. "Team folders"` |

- Keep: the selector prefix `groupfolder:` (a value).

**T3 · term**: checksum and hash name one value
The app's name, the sidebar tab and the search provider say "checksum". Labels, statuses and helps say "hash" for the same stored value, sometimes in one text: "Checksums indexed for this file. Click a hash to copy it." Nextcloud's own English has neither word, so it sets no convention. Most languages have a native word for "checksum" (Prüfsumme, somme de contrôle, suma de comprobación) and borrow "hash", so a mixed source produces a mixed translation.

Recommendation: "checksum" is the noun in labels and prose. "hash" stays a verb ("hashing", "is hashed", "never hash these files"). The one exception is the Duplicates hash filter (field "Hash", the decided switch "Match anywhere in the hash", its tooltip, its help, A17), which keeps "hash" throughout so that the field and its decided switch agree.

| msgid | where | proposed |
|---|---|---|
| Checksums indexed for this file. Click a hash to copy it. | `src/sidebar-vue/ChecksumsSidebarTab.vue:143` | Checksums indexed for this file. Click one to copy it. |
| Hash Algorithms | `src/settings-admin-vue/App.vue:391` | Checksum algorithms |
| Indexed Hashes | `App.vue:538` | Indexed checksums |
| Untrusted Hashes | `App.vue:559` | Untrusted checksums |
| Lazy (delete hashes, recalc later) | `src/rules-vue/RuleForm.vue:454` | see C4 |
| Open the Duplicates page on this hash, across every account you may see | `ChecksumsSidebarTab.vue:221` | Open the Duplicates page on this checksum, across every account you may see |
| What happens to a file that already has a hash. "Auto" … clears the hashes now … | `RuleForm.vue:227` | What happens to a file that already has checksums. "Auto" recalculates only outdated ones, "Missing" also adds missing ones, "Force" deletes and recalculates all of them, and "Lazy" deletes them now and lets a later run recalculate them. |
| What happens to a file that already has a hash. "auto" … | `src/rules-vue/RuleTable.vue:113` | What happens to a file that already has checksums. "auto" recalculates only outdated ones, "missing" also adds missing ones, "force" deletes and recalculates all of them, and "lazy" deletes them now and lets a later run recalculate them. Shown as "—" for a rule that computes nothing. |
| The algorithms rules may compute … Removing one does not delete hashes already stored under it — … | `src/settings-admin-vue/AlgorithmSection.vue:45` | The algorithms rules may compute and pickers may offer, chosen from what this server's PHP provides. Removing one does not delete checksums already stored under it — they stay searchable — it only stops new ones being computed. |
| When on, every user may trigger a computation … the hashes already computed. | `App.vue:32` | see T1 |
| hashes were dropped on write … | `App.vue:156` | see C6 |
| Hash index check | `App.vue:175` | optional: Checksum index check |
| Show only groups whose checksum starts with this / Whole or start of a checksum | `src/duplicates-vue/components/DuplicateListing.vue:77-78` | see A17 (filter group, "hash") |
| Show only groups whose checksum this names. … | `DuplicateListing.vue:69` | decided; see the reading check |

- Unchanged, already "checksum": the texts at `ChecksumsSidebarTab.vue:146, 152, 210, 234`, `useSidebarHashes.ts:96`, `DuplicateListing.vue:66, 74`, `RuleForm.vue:225, 230, 292`, `RuleTable.vue:102, 107`, `lib/Search/HashSearchProvider.php:73`, and "Checksum copy".
- The decided summary also uses both words ("Index file checksums to find files by hash"). It reads fine as English and stays.
- Alternative, if "hash" is preferred as the noun: the same table in reverse, 16 texts.

**T4 · term**: recalculate by hand; compute for rules; verify on Duplicates

| msgid | where | proposed |
|---|---|---|
| Who may calculate by hand | `src/settings-admin-vue/App.vue:459` | Who may recalculate checksums |
| Allow all users to calculate by hand | `App.vue:465` | Allow all accounts to recalculate checksums |
| This account may not calculate by hand. | `lib/Public/ChecksumApi.php:663` | This account may not recalculate checksums. |
| When on, every user may trigger a computation … / Members of these groups may trigger a computation … / Individual users who may trigger a computation … | `App.vue:32, 33, 34` | see T1 |
| Triggering a computation of one's own files — … | `App.vue:60` | see A2 |
| Recalc | `src/sidebar-vue/ChecksumsSidebarTab.vue:196` | Recalculate |
| Compute a checksum for the selected algorithm. | `ChecksumsSidebarTab.vue:177` | Read the file again and recalculate its checksum with the chosen algorithm. |
| Recompute every file in this group from its contents | `src/duplicates-vue/components/DuplicateGroup.vue:45` | Read every file in this group again, recalculate its checksum and compare |
| Recompute this file from its contents | `DuplicateGroup.vue:46` | Read this file again, recalculate its checksum and compare |
| mode options "…recalc…" (×4) | `src/rules-vue/RuleForm.vue:445-454` | see C4 |
| mode helps "recomputes / recompute" (×2) | `RuleForm.vue:227`, `src/rules-vue/RuleTable.vue:113` | see T3 |

- Keep: "Recalculate", "Recalculation failed.", "Manual recalculation…", "manual recalculation", "recalculate a file by hand", "Verify", "Verify all", "Verified", "Verification stopped: too many recalculation requests…", and "compute(s/d)" where a rule does the work.
- Why: four verbs for one action. "calculate by hand" can be read as arithmetic done by a person.

**T5 · term**: the scope field is called "Applies to"
- The table column is "Scope" (`src/rules-vue/RuleTable.vue:89`), and three helps say "change its scope" or "follows from its scope" (`src/settings-admin-vue/App.vue:59`, `src/rules-vue/RuleForm.vue:235`, `RuleTable.vue:85`). The dialog field that sets it is "Applies to" (`RuleForm.vue:305, 329`).
- Proposed: "Applies to" → "Scope".
- "slice" (`bands.ts:147, 151`, `RuleTable.vue:90`, `RuleForm.vue:222`) goes with it: T1, T2 and C9 already drop it.
- Why: the helps tell the reader to change a "scope" that the dialog does not name.

**T6 · term**
- msgid: "Defaults for all home folders. Within this segment a catch-all default — path **, / or empty — always evaluates last, after any more specific rules here."
- Where: `src/rules-vue/bands.ts:153`.
- Proposed: "Defaults for all home folders. Within this band a catch-all default — path "**", "/" or empty — always evaluates last, after the more specific rules here."
- Why: "segment" appears in no other text and names the band itself (the German glossary maps both to "Ebene"). The values are unquoted (K3).

**T7 · term**: home folder
- "a single user's home" (`bands.ts:147`), "their homes" (`bands.ts:151`), "every personal folder" (`RuleTable.vue:90`), "The home-folders default" (`App.vue:52`). Every other text says "home folder".
- Proposed: T1 and T2 carry the rewrites; "The home-folders default" becomes "The "All home folders" default", the name the table shows.

**T8 · term**: grant, not token or standing authorisation
- The tab and heading say "Sudo tokens". The texts under them call the thing an "app password" and its permission a "grant", then switch to "token" and "standing authorisation".

| msgid | where | proposed |
|---|---|---|
| … — a standing authorisation, listed for every administrator to see. … | `src/settings-personal-vue/SudoTokensSection.vue:48` | see C8 |
| App passwords granted the cross-account routes … Each is a standing authorisation: … | `src/settings-admin-vue/SudoTokensTab.vue:44` | see C7 |
| token deleted — grant left behind | `SudoTokensTab.vue:122` | app password deleted — the grant remains |
| The grant listing is unavailable: the token table could not be read. | `SudoTokensTab.vue:49` | Could not list the grants: the app passwords could not be read. |
| Your app passwords could not be listed: the token table could not be read. | `SudoTokensSection.vue:56` | Could not list your app passwords: they could not be read. |
| The grant listing could not be loaded ({error}). | `SudoTokensTab.vue:63` | Could not load the grants ({error}). |
| Your app passwords could not be listed ({error}). | `SudoTokensSection.vue:83` | Could not list your app passwords ({error}). |
| Sudo tokens | `src/settings-admin-vue/App.vue:339`, `SudoTokensSection.vue:136-137` | optional (C22) |

**T9 · term**
- msgid: "…Applies to administrators and group leaders alike."
- Where: `src/settings-admin-vue/TunablesSection.vue:25` (A10).
- Proposed: "group admins", as in Nextcloud's "Group admin for" (`apps/settings/src/components/Users/UserListHeader.vue:49`).
- Why: "group leader" is not a Nextcloud term. The user guide says "a group administrator in Nextcloud's terms".

**T10 · term**: two texts for one condition
- "Not logged in." (`lib/Controller/RulesController.php:620`) and "Not authenticated." (`lib/Controller/PublicApiController.php:107, 429, 454`; `lib/Controller/SudoTokensController.php:76, 111`) both answer "no user session".
- Proposed: "Not logged in." everywhere.

**T11 · term (optional)**
- "Add Rule" opens a dialog titled "New rule", and the placeholder row's "Create rule" opens the same dialog, prefilled (`src/settings-admin-vue/App.vue:427`, `src/settings-personal-vue/App.vue:212`, `src/rules-vue/RuleForm.vue:205`, `src/rules-vue/RuleTable.vue:414`).
- Proposed: "Add rule" for both buttons (K1), and keep "New rule" as the dialog title.

**T12 · term (optional)**: this server, not this instance
- "Not an algorithm this instance computes: %s" (A11), "These namespaces exist on this instance…" (C9), "every user of this instance" (A8), "the instance-wide view" (T1), "The defaults suit most instances." (`src/settings-admin-vue/TunablesSection.vue:84`: "…suit most servers.")
- Six other texts already say "this server".

---

### Conventions

**K1 · convention**: sentence case
Nextcloud's labels and headings are 976 of 1,008 in sentence case ("Background jobs", "Personal info", "Create new app password").

| msgid | where | proposed |
|---|---|---|
| Add Rule | `src/settings-admin-vue/App.vue:427`, `src/settings-personal-vue/App.vue:212` | Add rule |
| App Version | `src/settings-admin-vue/App.vue:526` | App version |
| Background Jobs | `App.vue:575` | Background jobs |
| Confirm Delete | `App.vue:260`, `src/settings-personal-vue/App.vue:105` | Delete rule (C14) |
| Database Version | `App.vue:532` | Database version |
| File Checksums | `lib/Search/HashSearchProvider.php:73` | File checksums |
| Hash Algorithms | `App.vue:391` | Checksum algorithms (T3) |
| Indexed Hashes | `App.vue:538` | Indexed checksums |
| Last Updated | `App.vue:590` | Last updated |
| Pending Updates | `App.vue:544` | Queued files (C5) |
| Status Info | `App.vue:513` | Status |
| Untrusted Hashes | `App.vue:559` | Untrusted checksums |
| User Guide | `lib/Controller/PageController.php:88, 140` | User guide |
| {group} (Group) | `src/duplicates-vue/components/TargetPicker.vue:58` | {group} (group) |

- Keep: "File Checksum Index & Search" (the app's name) and "FAQ".

**K2 · convention**: ellipsis
Nextcloud writes a no-break space (U+00A0) before "…" in 34 of 44 strings ("Loading …", "Loading accounts …"; commit `21db5bf9b9e` "l10n: Separate ellipsis"). The app has 7 texts without a space and 2 with a normal space.

| msgid | where | proposed |
|---|---|---|
| Add an algorithm… | `src/settings-admin-vue/AlgorithmSection.vue:126` | Add an algorithm&nbsp;… |
| Loading checksums … (normal space) | `src/sidebar-vue/ChecksumsSidebarTab.vue:146` | Loading checksums&nbsp;… |
| Saving… | `AlgorithmSection.vue:42`, `src/settings-admin-vue/PermissionSection.vue:71` | Saving&nbsp;… |
| Search groups… | `src/rules-vue/RuleForm.vue:339` | Search groups&nbsp;… |
| Search users… | `RuleForm.vue:372`, `PermissionSection.vue:149` | Search accounts&nbsp;… (T1) |
| Searching … (normal space) | `src/duplicates-vue/components/DuplicateListing.vue:395`, `ChecksumsSidebarTab.vue:228` | Searching&nbsp;… |
| Select folder… | `RuleForm.vue:213` | Select folder&nbsp;… (wording decided; spacing only) |
| Select groups… | `PermissionSection.vue:139` | Select groups&nbsp;… |

- Not touched: "local::/path/ or smb::…", where the ellipsis is part of an example value.
- If the decided "Select folder…" is to keep its exact spelling, the fallback is no space in all eight texts. Two texts change instead of eight, against Nextcloud's majority.

**K3 · convention**: quotes
Nextcloud quotes a UI name or a value with straight double quotes (90 strings; 1 curly). The app uses straight quotes in 12 texts and curly quotes in 5, both for UI names.

| msgid (start) | where | change |
|---|---|---|
| No enabled include rule exists … the “All home folders” default … | `src/settings-admin-vue/App.vue:51` | "All home folders"; also quote "Include" (C19) |
| The home-folders default … the “Everything” default … | `App.vue:52` | "Everything" (T2) |
| App passwords granted … under “Who may look across accounts” … | `src/settings-admin-vue/SudoTokensTab.vue:44` | "Who may look across accounts" (C7) |
| No app passwords yet. Create one under “Security”, then grant it here. | `src/settings-personal-vue/SudoTokensSection.vue:143` | No app passwords yet. Create one under "Security", then grant it here. |
| Show only groups whose checksum this names. … Turn on “Match anywhere in the hash” … | `src/duplicates-vue/components/DuplicateListing.vue:69` | quotes only: "Match anywhere in the hash" (decided wording) |
| Defaults for all home folders. … path **, / or empty … | `src/rules-vue/bands.ts:153` | path "**", "/" or empty (T6) |
| A granted app password … Create the app password on Security first … | `SudoTokensSection.vue:48` | under "Security" (C8) |
| Evaluated top to bottom — … its Enforced flag. | `App.vue:59` | its "Enforced" setting (A1) |
| Triggering a computation … the sidebar's Recalculate buttons … | `App.vue:60` | "Recalculate" (A2) |

**K4 · convention**: one pattern for failure messages
Nextcloud has no single pattern ("Could not", "Failed to" and "Unable to" all appear). The app uses four patterns for the same kind of message, and has two texts for one of them ("Save failed." and "Saving failed."). Proposed: "Could not …" throughout.

| msgid | where | proposed |
|---|---|---|
| Failed to load checksums. | `src/sidebar-vue/composables/useSidebarHashes.ts:96` | Could not load the checksums. |
| Failed to load documentation. | `src/docs-vue/DocsViewer.vue:105` | Could not load the documentation. |
| Failed to load duplicates. | `src/duplicates-vue/composables/useDuplicates.ts:152`, `useSidebarHashes.ts:173` | Could not load the duplicates. |
| Failed to load permission options. | `src/settings-admin-vue/PermissionSection.vue:87` | Could not load the permissions. |
| Failed to load rules. | `src/rules-vue/composables/useRules.ts:111` | Could not load the rules. |
| Failed to load status (HTTP {status}). | `src/settings-admin-vue/composables/useAdminSettings.ts:69` | Could not load the status (HTTP {status}). |
| Failed to load status. | `useAdminSettings.ts:77` | Could not load the status. |
| Failed to load the algorithm list. | `src/settings-admin-vue/AlgorithmSection.vue:72` | Could not load the algorithms. |
| Delete failed. | `src/settings-admin-vue/App.vue:267`, `src/settings-personal-vue/App.vue:112` | Could not delete the rule. |
| Re-apply failed. | `App.vue:287`, personal `App.vue:132` | Could not reapply the rule. |
| Reorder failed. | `App.vue:278`, personal `App.vue:123` | Could not reorder the rules. |
| Save failed. | `AlgorithmSection.vue:105`, `PermissionSection.vue:113` | Could not save. |
| Saving failed. | `App.vue:253`, personal `App.vue:98` | Could not save the rule. |
| Toggle failed. | `App.vue:296`, personal `App.vue:141` | Could not enable or disable the rule. (C13) |
| Recalculation failed. | `useSidebarHashes.ts:144, 148` | Could not recalculate the checksum. |
| Request failed. | `AlgorithmSection.vue:108`, `PermissionSection.vue:116`, `src/settings-personal-vue/PreferenceSection.vue:90` + | Could not complete the request. |
| Unable to open file for reading. | `lib/Service/HashCalculationService.php:1070, 1103` | Could not open the file for reading. |
| The list of accounts could not be loaded. | `TargetPicker.vue:224` | Could not load the accounts and groups. (A14) |
| grant and app-password listings (×4) | T8 | see T8 |

- Keep: "Could not change the grant.", "Could not revoke the grant.", "Could not save the options.", "Could not save the preference.", and the status words "Failed" and "Error".

**K5 · convention**: American spelling
Nextcloud writes "authorize" (2 against 0). The app's only British spellings are "authorisation" in `src/settings-personal-vue/SudoTokensSection.vue:48` and `src/settings-admin-vue/SudoTokensTab.vue:44`. C7 and C8 drop the word. If it stays: "authorization".

**K6 · convention**: ID
Nextcloud writes "user ID".

| msgid | where | proposed |
|---|---|---|
| Storage id | `src/rules-vue/RuleForm.vue:406, 412` | Storage ID |
| A storage (raw id) | `RuleForm.vue:323` | One storage (raw ID) |
| The raw id from the storages table, … | `RuleForm.vue:234` | The raw ID from the storages table, … (C20) |
| … one storage by its raw id … | `RuleForm.vue:222` | C9 |
| … one storage by its id … | `src/rules-vue/RuleTable.vue:90` | T2 |
| groupfolder: takes the numeric folder id. | `lib/Service/RuleDefinitionValidator.php:183` | groupfolder: takes the numeric folder ID. |

**K7 · convention (optional)**: dashes
The app is consistent: a spaced em dash in 37 texts, plus the literal "—" in two table helps. Nextcloud's English uses no em dash (one spaced en dash, three spaced hyphens) and prefers a new sentence. No change is needed for consistency. New texts should keep the spaced em dash, or, better, a colon, semicolon or full stop.

The 37 texts:
- "A disabled rule cannot be applied — …"
- "A granted app password …"
- "Administrator-enforced rules aimed at …"
- "Defaults for all home folders …"
- the four "Enforced — …" band labels
- "Evaluated top to bottom — …"
- "Evaluates in band {band} — {label}"
- "Every rule that can affect your files …"
- "Everything — every storage"
- the four "Members of these groups … — being in either is enough."
- "No enabled include rule exists …"
- "Noted — …"
- "Re-apply queued — …"
- "Rules are evaluated in band order …"
- "Rules for one specific slice — …"
- "Scripts and other apps …"
- "The algorithms rules may compute …"
- "The last resort …"
- "The raw id …"
- "The team folder this rule addresses …"
- "These are other people's files …"
- "These namespaces …"
- "Triggering a computation …"
- "What happens when this rule matches. "Include" …"
- "Where the rule sits …"
- "Which slice of files …"
- "Which slice of the file universe …"
- "no catch-all rule — …"
- "not covered — …"
- "token deleted — …"
- "{folders} — one folder"

**K8 · convention (optional)**: "Re-apply", "Re-apply failed.", "Re-apply queued — …" (`src/rules-vue/RuleRow.vue:177`; admin and personal `App.vue`). American dictionaries write "reapply".

---

### Clarity

**C1 · clarity**: quoted UI names that are translated, where the neighbouring comments say "keep them"
The Type and Mode table helps quote values that stay English, and their comments say "keep them". These texts quote names that the table shows translated, and say nothing. The current translators got them right; a new language may not.

| call (comment goes on the line directly above) | TRANSLATORS |
|---|---|
| Scope help, `src/rules-vue/RuleTable.vue:90` | `// TRANSLATORS: "All home folders" and "Everything" are scopes as the table shows them; translate them as those do` |
| Enforced help, `RuleTable.vue:123` | `// TRANSLATORS: "Yes" is the value this column shows; translate it as that does` |
| Idle banner body, `src/settings-admin-vue/App.vue:51` | `// TRANSLATORS: "All home folders" is a scope as the rule table shows it, "Include" a rule type as the dialog shows it; translate them as those do` |
| Idle banner scope, `App.vue:52` | `// TRANSLATORS: "All home folders" and "Everything" are scopes as the rule table shows them; translate them as those do` |
| Grants hint, `src/settings-admin-vue/SudoTokensTab.vue:44` | `// TRANSLATORS: "Who may look across accounts" is a heading on the Permissions tab; translate it as that heading does` |
| No app passwords, `src/settings-personal-vue/SudoTokensSection.vue:143` (template comment) | `<!-- TRANSLATORS: "Security" is Nextcloud's personal settings section; use Nextcloud's own translation -->` |
| Algorithms table help, `RuleTable.vue:107` | `// TRANSLATORS: "—" is what the table shows for a rule that computes nothing; keep it` |
| Mode table help, `RuleTable.vue:112` (extend) | `// TRANSLATORS: "auto", "missing", "force" and "lazy" are the modes as the table shows them, and "—" what it shows for a rule that computes nothing; keep them` |

**C2 · clarity**: three job names without comments (the other two have them)

| call | TRANSLATORS |
|---|---|
| "Rule sweep", `src/settings-admin-vue/App.vue:169` | `// TRANSLATORS: a background job's name: every few minutes it applies the rules to the files and queues those that need checksums` |
| "Queue drain", `App.vue:170` | `// TRANSLATORS: a background job's name: it computes the checksums of the queued files` |
| "Orphan purge", `App.vue:171` | `// TRANSLATORS: a background job's name: it removes the checksums of files that no longer exist` |

- Why: each is a noun pair that can be read as an imperative ("Drain queue") and names nothing a translator can see.

**C3 · clarity**: "outrun"
- `src/rules-vue/bands.ts:147`, `src/rules-vue/RuleForm.vue:228`, `src/rules-vue/RuleTable.vue:123`. Proposals are in T1 and A15 ("come before").
- Why: a race metaphor for precedence, which translators render literally.

**C4 · clarity**: abbreviations in the mode options
- Where: `src/rules-vue/RuleForm.vue:445-454`.

| msgid | proposed |
|---|---|
| Auto (recalc existing only if outdated) | Auto (recalculate existing checksums when outdated) |
| Missing (recalc existing + missing) | Missing (like Auto, and add missing checksums) |
| Force (delete all, recalc all) | Force (delete and recalculate all checksums) |
| Lazy (delete hashes, recalc later) | Lazy (delete checksums now, recalculate later) |

- "Recalc" (sidebar): T4.
- Why: "recalc" and "+" are notations, not words.

**C5 · clarity**
- msgid: "Pending Updates"
- Where: `src/settings-admin-vue/App.vue:544`.
- Proposed: "Queued files".
- TRANSLATORS: `<!-- TRANSLATORS: files waiting for the background job to compute their checksums, counted by mode -->`
- Why: in Nextcloud's admin pages, "updates" means app and server updates.

**C6 · clarity**
- msgid: "hashes were dropped on write because no rule maintains them; heals itself once a rule covers them again"
- Where: `src/settings-admin-vue/App.vue:156`.
- Code: `lib/Listener/FileListener.php:141-155`.
- Proposed: "the file changed while no rule maintained its checksums, so they were deleted; it gets them back once a rule covers it again"
- Why: "on write" and "heals itself" are idioms, and "Eroded" beside them is already a metaphor.

**C7 · clarity**
- msgid: "App passwords granted the cross-account routes without a password prompt. Each is a standing authorisation: this is where every one of them is visible, and where any can be taken back. Who may look across accounts at all is decided under “Who may look across accounts”; a grant replaces the prompt, not the permission."
- Where: `src/settings-admin-vue/SudoTokensTab.vue:44`.
- Proposed: "App passwords that may use the cross-account routes without a password prompt. A grant stays in force until it is revoked, so every one is listed here, and any can be revoked here. Who may look across accounts at all is decided under "Who may look across accounts"; a grant replaces the password prompt, not the permission."
- Why: the opening reads as a sentence in the past tense ("App passwords granted…").

**C8 · clarity**: the personal grant help and the filesystem texts
- msgid: "A granted app password may read across accounts through the /api/v1/sudo/ routes without anyone typing a password — a standing authorisation, listed for every administrator to see. Create the app password on Security first; only one allowed to access files can be granted. Who may look across accounts is still decided by the sudoers permission; a grant replaces the prompt, not the permission."
- Where: `src/settings-personal-vue/SudoTokensSection.vue:48`.
- Proposed: "A granted app password may read across accounts through the /api/v1/sudo/ routes without anyone typing a password. A grant stays in force until it is revoked, and every administrator can see it. Create the app password under "Security" first, with "Allow filesystem access" on. Whether you may look across accounts at all is still up to your administrator; a grant replaces the password prompt, not the permission."
- TRANSLATORS: `// TRANSLATORS: "Security" and "Allow filesystem access" are Nextcloud's own names; use Nextcloud's translations. Keep /api/v1/sudo/ as it is`
- Filesystem texts, one wording:

| msgid | where | proposed |
|---|---|---|
| This app password is kept out of the filesystem and cannot be granted file reads. | `SudoTokensSection.vue:69` | This app password has no filesystem access, so it cannot be granted. |
| This app password is kept out of the filesystem, so it cannot be granted file reads. | `lib/Service/SudoTokens.php:190` | (same text) |
| This app password may not access files. | `lib/Controller/PublicApiController.php:115` | This app password has no filesystem access. |

- Why: "only one allowed to access files" and "kept out of the filesystem" describe Nextcloud's "Allow filesystem access" switch without naming it. "the sudoers permission" is not a name the reader can find.

**C9 · clarity**: jargon in the scope helps

| msgid | where | proposed |
|---|---|---|
| Which slice of the file universe this rule addresses: … | `src/rules-vue/RuleForm.vue:222` | Which files this rule addresses: all home folders, the home folders of one group's members, a single account's home folder, one team folder, one storage by its raw ID, or everything — every storage, external storage and team folders included. |
| These namespaces exist on this instance but have no catch-all rule of their own, … Creating a rule from here starts one for that namespace; until then, nothing is stored. | `src/rules-vue/RuleTable.vue:129` | These places exist on this server but have no catch-all rule of their own, so their files are decided by a more general rule, or by none at all. "Create rule" opens the dialog with a catch-all rule for the place; nothing is saved until you save it. |
| … and their subjects cannot edit or disable them. | `src/rules-vue/bands.ts:147` | T1 ("the account cannot edit or disable them") |

- Why: "file universe", "namespace" and "subjects" are the code's words. "nothing is stored" can mean checksums or configuration.

**C10 · clarity**
- msgid: "Node is not a file."
- Where: `lib/Service/HashCalculationService.php:913, 1229`.
- Proposed: "This is not a file."
- Why: "node" is Nextcloud's internal word.

**C11 · clarity**: API messages that name a parameter

| call | proposed / TRANSLATORS |
|---|---|
| "fileIds must be a non-empty list of integers.", `lib/Controller/PublicApiController.php:1184` | `// TRANSLATORS: fileIds is a parameter name; keep it` |
| "value must be a string.", `PublicApiController.php:468` | `// TRANSLATORS: value is a parameter name; keep it` |
| "selector must be a non-empty string.", `lib/Service/RuleDefinitionValidator.php:156` | `// TRANSLATORS: selector is a parameter name; keep it` |
| "Hash parameter is required.", `lib/Public/ChecksumApi.php:280` | The "hash" parameter is required. + `// TRANSLATORS: "hash" is a parameter name; keep it` |

**C12 · clarity**: short texts that need a comment

| call | TRANSLATORS |
|---|---|
| "Granted", column heading, `src/settings-admin-vue/SudoTokensTab.vue:111` | `<!-- TRANSLATORS: a column heading: when the app password was granted, and by whom -->` |
| "Granted {time}", `src/settings-personal-vue/SudoTokensSection.vue:63` | `// TRANSLATORS: {time} is the date and time the app password was granted` |
| "Not processed", `src/duplicates-vue/composables/useDuplicates.ts:248` | `// TRANSLATORS: a file's verification result: the server did not get to this file` |

**C13 · clarity**
- msgid: "Toggle failed."
- Where: `src/settings-admin-vue/App.vue:296`, `src/settings-personal-vue/App.vue:141`.
- Proposed: "Could not enable or disable the rule."
- Why: the actions are called "Enable" and "Disable". "Toggle" names neither.

**C14 · clarity**
- msgid: "Confirm Delete" (dialog title)
- Where: `src/settings-admin-vue/App.vue:260`, `src/settings-personal-vue/App.vue:105`.
- Proposed: "Delete rule".
- Why: "Delete" can be read as a noun, and the title is in title case.

**C15 · clarity**: one field, three names
- "Cross-account picker: prefill up to" (visible label, `src/settings-admin-vue/TunablesSection.vue:88`), "Prefill limit" (help button, `:89`), "Prefill up to" (field label, `:97`).
- Proposed: the help button reuses the visible label.
- TRANSLATORS above `:88`: `<!-- TRANSLATORS: prefill: fill the picker's list before anything is typed -->`

**C16 · clarity**
- msgid: "Nobody to show"
- Where: `src/duplicates-vue/components/TargetPicker.vue:69`.
- Proposed: "No accounts or groups to show".
- Why: the list holds groups too.

**C17 · clarity**
- msgid: "A group" (scope option)
- Where: `src/rules-vue/RuleForm.vue:314`.
- Proposed: "Members of a group".
- Why: next to "{folders} — one folder", a reader can take "A group" for a group folder; it means the members' home folders.

**C18 · clarity (optional)**
- msgid: "Users may not edit this rule" (the "Enforced" switch)
- Where: `src/rules-vue/RuleForm.vue:467`.
- Proposed: "Only administrators can edit or disable this rule".
- Why: the caption names half of what the switch does. The other half, coming first, is in its help.

**C19 · clarity**: idle banner
- Where: `src/settings-admin-vue/App.vue:51` and `App.vue:117`.

| msgid | proposed |
|---|---|
| No enabled include rule exists, so no file is hashed until one says so — enable the “All home folders” default in the table below, or create a rule. Manual recalculation from the file sidebar keeps working either way. | No "Include" rule is enabled, so no file is hashed automatically. Enable the "All home folders" default in the table below, or add a rule. Recalculating by hand from the file sidebar still works. |
| Noted — the banner stays away until a rule is enabled and disabled again. | Noted — the banner stays hidden until an "Include" rule is enabled and then disabled again. |

- Code: the acknowledgement is cleared only when an include rule is enabled (`lib/Service/RuleService.php:556-565`).
- Why: "until one says so" has an unclear "one", and "a rule" is wider than the code.

**C20 · clarity (optional)**
- msgid: "The raw id from the storages table, matched exactly — whatever kind of storage it names. Use this for external mounts, or anything the other choices cannot say."
- Where: `src/rules-vue/RuleForm.vue:234`.
- Proposed: "The raw ID from the storages table, matched exactly, whatever kind of storage it names. Use this for external storage, or for any storage the other choices cannot address."

**C21 · clarity (optional)**
- msgid: "Re-apply queued — the background job takes it from here."
- Where: `src/settings-admin-vue/App.vue:285`, `src/settings-personal-vue/App.vue:130`.
- Proposed: "Reapply queued — a background job will go through the rule's files."

**C22 · clarity (optional)**: developer words in headings
- "Sudo tokens" (`src/settings-admin-vue/App.vue:339`, `src/settings-personal-vue/SudoTokensSection.vue:136-137`): a personal-settings heading for people who never met sudo. Option: "Cross-account app passwords".
- "The sudoers:" (`App.vue:62`) and "Tunables" (`src/settings-admin-vue/TunablesSection.vue:82`; option: "Fine-tuning").
- Keep them if the /sudo/ routes should stay recognisable. The German glossary already fixes "Sudo-Token" and "Sudoers".

---

### Grammar

**G1 · grammar**
- msgid: "never ran yet"
- Where: `src/settings-admin-vue/App.vue:181`.
- Proposed: "Not run yet".
- Why: "never" and "yet" do not combine. The CLI keeps its own English copy (`lib/Command/ShowStatus.php:154`).

**G2 · grammar**
- msgid: "How many groups one page shows. Nothing here is read from disk until you ask a group or a file to verify, so a larger page costs a longer query, not longer reads."
- Where: `src/duplicates-vue/components/DuplicateListing.vue:67`.
- Proposed: "How many groups one page shows. Nothing here is read from disk until you verify a group or a file, so a larger page costs a longer query, not longer reads."
- Why: a group does not verify; you verify it.

**G3 · grammar (optional)**
- "…so more algorithms means more work per file." (`src/rules-vue/RuleForm.vue:225`, `src/rules-vue/RuleTable.vue:107`)
- Proposed: "…so more algorithms mean more work per file."

**G4 · grammar (optional)**
- "…Two is every duplicate; a higher number finds the widely copied ones." (`src/duplicates-vue/components/DuplicateListing.vue:66`)
- Proposed: "…Two lists every duplicate; a higher number lists only the widely copied ones."

---

### Outside the template

- **Untranslated refusal.** A person creating a personal rule on a share or a team folder gets English whatever their language: "The path leads into a received share, a group folder or another mounted storage. A personal rule only governs your own files; …" and "The path is not in a folder you can write to."
  - Source: `lib/Service/RuleService.php:1648-1662`.
  - Shown by: `lib/Controller/RulesController.php:212-215, 289`, and through the PHP API at `lib/Public/ChecksumApi.php:1070, 1111`.
  - These belong in `IL10N`, and should say "team folder".
- **User guide.** `docs/user-guide.md:60` still calls the switch "Search anywhere", and the guide says "calculate by hand", "group folder" and "authorisation". It should follow once the texts above change.
