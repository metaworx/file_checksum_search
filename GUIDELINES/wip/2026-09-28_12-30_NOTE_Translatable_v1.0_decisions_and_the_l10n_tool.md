# NOTE Translatable v1.0: decisions, and the l10n tool as a Python core

Accompanies `2026-09-28_12-20_AP_Translatable_v1.0_every_text_translatable.md`,
which stays as written. Where the two differ, this note holds.

## Decisions taken, as the plan recommended

- **`.po` files are kept here**, one per language under
  `translationfiles/<lang>/`, merged from the `.pot` and converted to `l10n/`.
- **The translation tool is pinned by download and checksum**, not
  committed.
- **Server messages are translated in PHP**, where they are made, in the
  user's language.
- **German is drafted here in both registers** (`de` informal, `de_DE`
  formal) and reviewed by the maintainer, the terms settled first.

## Block 1: `l10n.py` does the work, `l10n.sh` is a door

The commands live in `l10n.py`: fetching and checking `translationtool.phar`,
`pot`, `update`, `build` and `check`, with the Python standard library only,
as `tests/e2e/store/appstore.py` does. `l10n.sh` only hands its arguments to
it (`exec python3 "$(dirname "$0")/l10n.py" "$@"`), so a `l10n.ps1` for
Windows is the same two lines later. The tool itself still needs PHP and
gettext (`xgettext`, `msgmerge`, `msgfmt`) on the path. All three files sit
beside `package.sh`, and `.nc.publish.ignore` keeps them, and
`translationfiles/`, out of the release.

## For later: what Nextcloud's Transifex sync does with translations made here

From `nextcloud/docker-ci`, `translations-app/handleAppTranslations.sh`,
lines 160–224, read on 2026-09-28:

- It pushes **sources only** (`tx push -s`): the `.pot`, never a
  translation from the repository.
- It pulls every language at least 5% translated on Transifex
  (`tx pull -f -a --minimum-perc=5`), then **deletes `l10n/*.js` and
  `l10n/*.json`** and rebuilds them from what it pulled, and commits that.
- It does not commit `translationfiles/` (except for ExApps).

So the translations kept here reach Transifex only if someone uploads them
there, and the sync's first run, which pushes the sources and pulls at once,
removes every `l10n/` file whose language Transifex does not yet have. Before
joining, the `.po` files have to be uploaded to the new resource, and the
repository's `translationfiles/<lang>/` retired, since the sync would no
longer update them.
