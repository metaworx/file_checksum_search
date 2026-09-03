# AP SecurityFixes v1.0: close F3–F10

> **Status: proposal, 2026-09-03.** Closes the eight findings the security
> review left open after F1 and F2 were fixed. Each finding is one commit,
> `[SECURITY]` where it shuts an exposure, `[FIX]`/`[TASK]` where it is a
> hardening or a comment. The analysis document
> (`…_ANALYSIS_Security_v1.0_…`) stays untracked until the last of these
> lands, then is committed as the record.

## Findings, one block each

1. **[SECURITY] F3 — unbounded duplicates work per request.** `minCount`
   reaches the query unclamped on both HTTP paths (`findAll`,
   `findAllSudo`), so `minCount=1` makes every hashed file a group; the occ
   command already clamps to `max(2, …)`, the controllers do not. Clamp it
   once, in `ChecksumApi::findDuplicatesFor()`, where both the browser and
   the API pass through. Rate-limit `DuplicatesController::findAll` and
   `findAllSudo` (`#[UserRateLimit(limit: 60, period: 60)]`, as the API
   twins already carry). The 10 000-group over-fetch and the per-member
   `getMetadata()` on truncated groups are a resource cost, not an
   exposure; they move to a follow-up performance AP, noted here so the
   block is not mistaken for closing them.
2. **[SECURITY] F4 — the rule sweep materialises every matched file id.**
   The 100-mark cap bounds marks, not the walk: on a fresh instance
   `sweepLocations()` walks every filecache row, `$fileIds` grows to the
   file count, and one `getUpdatedAt()` fires per row, every five minutes
   in cron. Stop the walk when the mark budget (or a time budget) is spent.
   The larger refactor — folding the freshness test into the page query,
   dropping the cross-rule id sets — is performance, deferred with F3's
   paging; this block bounds the walk so a wide rule cannot exhaust cron
   memory.
3. **[SECURITY] F5 — exception text echoed to rule editors.**
   `RulesController::serverError()` returns `$e->getMessage()` — driver
   text and SQL fragments — to anyone with rule-editing permission. Return
   a generic message, the exception to the log, as `PublicApiController`
   already does. The four `badRequest($e->getMessage())` sites are
   validation messages meant for the editor and stay.
4. **[SECURITY] F6 — instance-wide counters to non-admins.**
   `/settings/status` is `#[NoAdminRequired]` and returns row counts,
   backlog and job state; only the admin page reads it. Drop
   `#[NoAdminRequired]`. `/api/v1/status` answers non-admins with `version`
   only (admins keep the full payload); the sidebar and Duplicates pages do
   not read the counters, so nothing bundled breaks.
5. **[SECURITY] F7 — two mutating POSTs are CSRF-exempt.** `recalc` and
   `rules/{id}/apply` carry `#[NoCSRFRequired]`, which also drops the
   strict-cookie check. Mitigated by the `SameSite=Lax` cookie and the
   `OCS-APIRequest` header the clients already send, so the attribute buys
   nothing — remove it from both. The read routes keep it: a GET is not the
   concern, and the API is called with an app password that carries no CSRF
   token.
6. **[SECURITY] F8 — `lookup` scoping is too narrow and limit-before-filter.**
   `DuplicateService`'s lookup calls `queryByHash()` without the caller's
   storage ids, so the limit is applied before the home filter — the exact
   defect the search provider was fixed for — and the filter is
   `home::<uid>` only, so object-store homes, shares and group folders
   answer nothing. Resolve through `getUserFolder()->getById()` as
   `findSameHash()` does, and narrow by the caller's mount storage ids. Not
   a leak — the wrong direction — but the API says "not found" for files
   the user holds.
7. **[SECURITY] F9 — unified search limit passed through.** The provider
   passes `$query->getLimit()` to `queryByHash()` uncapped, one `getById()`
   per row. Whether core caps it is unverified; cap it in the provider
   regardless, `min($query->getLimit(), 100)`.
8. **[TASK] F10 — whole-document rewrites, a line where the trade-off is
   made.** `renameKeyInDocuments()`'s `REPLACE()` over whole documents and
   `verifyTruncatedDuplicateGroups()`'s truncated-prefix fallback are both
   repair-only and already narrowed; the review asks only for a comment at
   each where the trade-off is accepted. No behaviour change.

## Gate

Per block: PHP suite; for a block touching frontend, `npm run lint` +
`npx vitest run` + `npm run build`; the e2e specs the block touches. A new
or extended test per security block proves the close — F3 a clamped
`minCount`, F5 a generic message, F6 a non-admin's trimmed status, F7 the
routes answering without the attribute, F8 a file found through a share.
Full e2e at the end.

## Open decisions

1. F6 `/api/v1/status` for non-admins: `version` only (built), or document
   the whole payload as public by design. Trimming unless struck.
2. The deferred performance work (F3 paging, F4 walk refactor, F9 already
   here): a separate AP after this, or folded in. Separate unless struck.

## Change History

- v1.0 (2026-09-03): proposal.
