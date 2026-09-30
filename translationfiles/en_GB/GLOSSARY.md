# British English glossary

This translation is an AI draft, not yet reviewed by a native speaker.

`en_GB` is the app's American English with only what British usage changes.
Everything not listed here stays the English, character for character. The
model is Nextcloud's own British English (core, settings, files,
files_sharing, dav), which changes little. A word that changes, changes here
and in the `.po` file at once.

## Spelling

| Rule | Nextcloud's British English | This app |
|---|---|---|
| *-ize* → *-ise*: authorise, recognise, organise, prioritise, normalise, customise, finalise, and the like | “authorised”, “organisation”, “Customise link” | none yet |
| *-yze* → *-yse*: analyse | | none yet |
| *-or* → *-our*: behaviour, colour, favourite | “Favourites” | none yet |
| doubled *l*: cancelled, labelled, modelled, travelled | “has been cancelled” | none yet; *Cancel* is the same in both, and `{label}` is a placeholder |
| *license* (the noun) → *licence*; *catalog* → *catalogue*; *gray* → *grey*; *toward* → *towards* | | none yet |
| *dialog* → *dialogue* | “Close "{dialogTitle}" dialogue” | “"Add rule" opens the dialogue with a catch-all rule …” |

## Wording

Wording changes only where Nextcloud's British English changes the same
wording in the same kind of text: “Wrong password” → “Incorrect password”,
“Access forbidden” → “Access denied”, “expiration date” → “expiry date”. The
app has no such text yet.

*Reapply*, *recalculate* and *reorder* stay solid, as British dictionaries
(Oxford, Cambridge, Collins) and Nextcloud's own British English (reload,
reshare, rescan, retry) write *re-* words.

## Never changes

- The terms checksum, account, team folder, rule, band, scope, grant,
  sudoers, recalculate.
- `{placeholders}`, `%s`, `%n`.
- Values in straight quotes: `"include"`, `"hash"`, `"**"`, `local::/path/`.
- Code, route and parameter names: `/api/v1/sudo/`, `fileIds`, `selector`,
  `value`, `groupfolder:`.
- Nextcloud's own names the texts quote, “Security” and “Allow filesystem
  access”, which Nextcloud's British English keeps.
- The no-break space (U+00A0) before “…”, as Nextcloud's British English
  keeps it.
- Punctuation and quotation marks.
