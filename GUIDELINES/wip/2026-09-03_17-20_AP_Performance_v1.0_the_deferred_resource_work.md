# AP Performance v1.0: the deferred resource work

> **Status: proposal, 2026-09-03.** The resource work the security review
> named that is cost, not exposure — so it was kept out of the security
> fixes (AP SecurityFixes v1.0) and named here instead, where the earlier
> APs' prose promised "a follow-up performance AP" that did not yet exist.
> Nothing here closes a security finding; the two findings whose *exposure*
> was resource-shaped (F3's unbounded work, F4's unbounded sweep) are closed
> under SecurityFixes — F3's clamp and rate limit in `fd46dce`, F4's bounded
> sweep in full there too. What remains is making the same paths cheaper.

## Blocks

1. **[PERF] Keyset-page the duplicates listing instead of over-fetching
   10 000 groups.** `HashIndexService::listDuplicatesForUser()` asks
   `findAllDuplicates()` for `UNFILTERED_GROUP_FETCH_LIMIT = 10000` groups
   and trims to the caller's `$limit` after per-user filtering, because how
   many groups survive that filter is unknown until it runs. Each of the
   10 000 rows carries `MAX(m.json)` — a whole metadata document — and a
   `GROUP_CONCAT` of member ids. Replace the fixed over-fetch with keyset
   paging that fetches a page, filters it to the caller, and stops once
   `$limit` surviving groups are in hand — so a caller wanting 50 groups
   reads roughly 50, not 10 000. The clamp and the rate limit already cap
   the damage; this removes the waste on every ordinary request.
2. **[PERF] Verify truncated groups a page at a time, not a query per
   member.** `MetadataService::verifyTruncatedDuplicateGroups()` issues one
   `getMetadata()` per member of every 63-character group to split
   prefix-collisions by full hash — thousands of point reads for a large
   group (the empty-file hash puts many ids in one). Fetch the documents for
   a group's members in one batched read (`fetchDocuments()` or an
   `IN (…)` over the metadata table) and split in memory.

## Not here

- **F4's sweep** is done in full under SecurityFixes, not deferred: the
  per-candidate `governingRuleForLocation()` resolution that removes the
  cross-rule id sets, and the freshness stamp folded into
  `pageStorageFiles()` so a fresh file is never fetched and the walk yields
  only work to do. This AP predates that decision; the earlier prose that
  called F4's refactor "deferred" is superseded by it.
- **F9** (the search-limit cap) shipped under SecurityFixes.

## Gate

Per block: PHP suite (the duplicates and metadata unit tests carry the
behaviour), and the `duplicates` e2e for block 1. No frontend.

## Open decisions

1. Order against other queued work (Screenshots AP, the sub-admin picker).
   These are cost-not-correctness, so they can wait; do them when the
   duplicates page is next in hand.

## Change History

- v1.0 (2026-09-03): proposal.
