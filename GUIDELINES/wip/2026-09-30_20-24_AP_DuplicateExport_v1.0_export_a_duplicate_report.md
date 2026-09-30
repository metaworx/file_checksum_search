# AP DuplicateExport v1.0: export a duplicate report

## Discussion

- **Origin.** Carried over from AP SuggestedFeatures v1.5 (2026-08-22), at
  the user's request of 2026-09-30.
- **Not scheduled.** It is re-confirmed with the user before it runs.

## Analysis

**Today:** the Duplicates page loads one page of groups at a time (`limit`,
`offset`), for a tab and its filters, all of which the address carries. The
CLI can print JSON (`find-duplicates --output=json`). The page has no way to
take the listing away.

**Recommended:** export what is on screen, client-side, with no new route.
A full export beyond the current page is a later option.

**Constraints:**
- every new text is translatable, in every language kept here;
- `npm run typecheck` stays at zero errors.

## Implementation Plan

1. **The export.**
   - `toJson(groups)` and `toCsv(groups)`, as pure functions.
   - The CSV has one row per file: algorithm, hash, file id, the path as the
     row shows it (its location where it is not the viewer's), name, and the
     verification state where verification ran.
   - Two buttons in the listing's toolbar download through a `Blob`.
   - Specs: the exact output, with commas, quotes and line breaks in paths
     escaped.
2. **Documentation.** The README and the user guide.

**Verification:** Vitest, `npm run typecheck`, ESLint,
`scripts/l10n.sh check`, and the Duplicates e2e spec.

## Proposed commit messages

`[TASK] The Duplicates page exports what it shows`, in full at its gate.

## Change History

- v1.0 (2026-09-30): first version, carried over from AP SuggestedFeatures
  v1.5.
