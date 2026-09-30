# AP TranslationPlatform v1.0: where translators work

## Discussion

- **Where it comes from.** The user, 2026-09-30: the L10n analysis can go
  once its open question is carried into a plan.
- **Not scheduled.** It is a brief plan, for a decision.

## Analysis

**Today:**
- Every text a person reads is translatable (`/AGENTS.md` §3.7).
- Eleven languages are kept in `translationfiles/<lang>/`, each with its
  glossary, and built into `l10n/` by `scripts/l10n.sh`.
- German is reviewed by a native speaker. British English and eight others
  are agent-drafted and marked as machine-translated.
- The repository is hosted on GitLab and mirrored to GitHub. It has a
  `.l10nignore` and no `.tx/config`.

**The options:**
1. **Nextcloud's own Transifex sync:** Nextcloud's translator community,
   with the app opted in by request.
   - **Only github.com is supported.** `nextcloud-bot` commits to GitHub
     `master` every night.
   - **Clash:** the GitLab-to-GitHub mirror would force-push over those
     commits. It needs a workflow that fast-forwards GitLab `master` first,
     or GitHub becomes the source of `master`.
   - **Steps only the user can take:** join the Nextcloud project on
     Transifex, give the bot write access, and open "Request translations"
     in nextcloud/docker-ci.
   - **Repository changes:** `.tx/config` and `l10n/.gitkeep`.
   - **The bot's commits** carry no `[TAG]` and no changelog bullet, and each
     one runs the full CI matrix.
2. **A Transifex project of our own:** push the template and pull the
   languages from this project's scheduled CI, committing to GitLab. No
   mirror clash, but no Nextcloud translator community.
3. **Hosted Weblate:** its GitLab integration syncs the `.po` files both
   ways, and anyone can join as a translator. No mirror clash, and no
   Nextcloud community either.

**Common to all three:**
- The glossaries become the platform's glossary.
- The machine-translated languages are imported as drafts, to be reviewed
  there.

**Not verified:** the current free plans for open source at Transifex and
Weblate, and whether Nextcloud accepts an app whose canonical host is GitLab.

## Implementation Plan

1. **Verify** the two open points above.
2. **Decide** with the user, and write a new revision of this plan with its
   blocks.

## Proposed commit messages

None yet.

## Change History

- v1.0 (2026-09-30): first version, carrying over the open question of the
  L10n analysis of 2026-09-28.
