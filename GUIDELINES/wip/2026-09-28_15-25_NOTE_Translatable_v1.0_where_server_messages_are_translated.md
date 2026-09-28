# NOTE Translatable v1.0: where the server's messages are translated

Accompanies `2026-09-28_12-20_AP_Translatable_v1.0_every_text_translatable.md`
and `2026-09-28_12-30_NOTE_Translatable_v1.0_decisions_and_the_l10n_tool.md`.
Where they differ, this note holds.

## Block 3: a message is translated where it is made, as Nextcloud core does

About 30 of the server's messages are written by the controllers, and reach
only the web interface and the REST API. About 25 more are thrown or returned
by services — the rule validator, `ChecksumApi`, `HashCalculationService`, the
sudo-token and settings services — and reach the web interface, `occ`, and
other apps through the PHP API alike. The plan said both that a message is
translated where it is made and that `occ` output stays English, which for
the second kind cannot both hold.

Decided, after reading how Nextcloud handles it:

- **Services translate their own messages through `IL10N`**, as core's share
  manager does (`lib/private/Share20/Manager.php`: 42 of its exceptions carry
  `$this->l->t()`, three internal ones stay English). On the web that is the
  user's language; on the command line, where there is no user and no
  request, `L10N\Factory::findLanguage()` falls through to `force_language`,
  then `default_language`, then English — so `occ` shows such a message in the
  server's default language, as core's commands do.
- **A command's own output and every log stay English.** Where a message is
  shown to a person and also logged, the log gets English: an
  `OCP\HintException`-style split, the message untranslated and a translated
  hint for the person.
- **The REST API's errors follow the caller's account language**, as
  Nextcloud's OCS APIs do; a script reads the status code.
- PHP placeholders are `IL10N`'s, `%s` and `%1$s`, not the frontend's `{name}`.

`AGENTS.md` §3.7 is amended to say so.

For comparison: Deck's `CardService` throws 23 messages, none translated, and
Group folders' `FolderController` 14 OCS errors, none translated — apps often
leave their API errors in English. Core does not, and this app follows core.
