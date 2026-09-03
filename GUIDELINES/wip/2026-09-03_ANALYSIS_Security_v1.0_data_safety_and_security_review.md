# ANALYSIS Security v1.0: data safety and security review

> **Status:** all ten findings resolved; committed as the record once the
> last fix landed, per the standing rule that this document stays out of
> the tree until everything it names is fixed. See **Resolution** below for
> the commit that closes each; the finding text is the review as first
> written, unchanged.

## Resolution

Every finding is fixed. F1 and F2 were closed first (they are described in
the CHANGELOG's *Security* section directly); F3–F10 under AP SecurityFixes
v1.0, one commit each.

| # | Severity | Closed by |
|---|----------|-----------|
| F1 | high | `findSameHash()` ownership-checks before reading (pre-AP) |
| F2 | medium | adopted filecache checksums verified before trust (pre-AP) |
| F3 | medium | `fd46dce` — clamp `minCount`, rate-limit the browser routes |
| F4 | medium | `6d6d8e2` — stale-only sweep query, per-candidate governance |
| F5 | low | `eb4dc31` — generic error to rule editors, detail to the log |
| F6 | low | `f352d5b` — status is the administrator's; non-admins get the version |
| F7 | low | `571faa2` — `recalc` and `apply` no longer waive CSRF |
| F8 | low | `22d1a65` — lookup scopes by mounts and resolves through `getById()` |
| F9 | low | `87221e8` — the unified-search limit is capped |
| F10 | low | `72e55d4` — the two repair-only trade-offs are marked in the code |

The remaining resource *cost* named in F3 (the 10 000-group over-fetch, the
per-member truncated read) is not exposure and carries over to AP
Performance v1.0.

---

> **Original status (as written):** analysis only, no code changed.

2026-09-03. Reviewed against the working tree between HEAD `761eddc` and
`4e2e333`, uncommitted changes included. A documentation pass was adding doc
comments to `lib/Service/*.php` and `lib/Controller/*.php` while this was
written; every line number below was re-derived against the tree as it stood
at the final check, and each citation also names the method and quotes the
anchor line, so a small drift can be resolved by the text. Nextcloud core
behaviour was read from the local 34.0.3 instance
(`~/projects/nextcloud_testing/instances/34`), never executed. Every finding
is a code trace; nothing was run against a database.

## Verdict

Nothing critical. The authorisation model is coherent and, with one exception,
enforced in code as well as by attribute: rule mutation cannot be escalated by
a non-administrator, every read path except one resolves files through the
caller's own mount tree, SQL is bound throughout, there is no command execution,
no HTML sink in the frontend, and hashing streams. The exception is real:
`GET /api/v1/file/{fileId}/duplicates` reads the hashes of **any** file id
before asking whether the caller may see the file, which turns it into an
unthrottled "does file N have content H" oracle across the whole instance —
and because the index copies client-supplied `OC-Checksum` values without
verifying them, the attacker needs only the hash, not the content. The rest
is medium and low: an unclamped `minCount` that lets any user make the server
fetch ten thousand metadata documents per request, a rule sweep that
materialises every matched file id in memory, and a handful of
defence-in-depth gaps (exception text echoed to rule editors, instance-wide
counters to non-admins, two mutating POSTs marked CSRF-exempt).

## Findings

| # | Severity | Area | Who | Finding | State |
|---|----------|------|-----|---------|-------|
| F1 | high | access / leak | any logged-in user | `findSameHash($fileId)` reads a foreign file's hashes before any ownership check; confirms content equality for arbitrary file ids | confirmed |
| F2 | medium | integrity | any logged-in user | Client-supplied `oc_filecache.checksum` (`OC-Checksum` upload header) is copied into the index unverified; amplifies F1 and poisons duplicates; over-long algorithm token breaks `meta_key` width | confirmed (trace); DB error mode needs verification |
| F3 | medium | resource | any logged-in user | `minCount` unclamped; every duplicates request over-fetches 10 000 groups each carrying a full metadata document, then one `getMetadata()` per member of every long-hash group; `/duplicates/data` is not rate limited | confirmed |
| F4 | medium | resource | admin (rule config) / cron | `evaluateRules()` accumulates every matched file id of every rule in memory and issues one query per matched file; the 100-mark cap does not bound the walk | confirmed |
| F5 | low | leak | rule editor | `RulesController::serverError()` returns `$e->getMessage()` to the client | confirmed |
| F6 | low | leak | any logged-in user | `/settings/status` and `/api/v1/status` expose instance-wide counters and job state to non-admins | confirmed |
| F7 | low | CSRF | cross-site page | `recalc` and `rules/{id}/apply` are mutating POSTs marked `#[NoCSRFRequired]`, which also disables the strict-cookie check; mitigated by SameSite=Lax session cookie | confirmed, mitigated |
| F8 | low | availability | non-admin | `lookup` applies the limit before the home filter and filters to `home::<uid>` only — own files can be hidden behind foreign copies; object-store homes, shares and group folders return nothing | confirmed |
| F9 | low | resource | any logged-in user | Unified search limit is passed through unclamped, then one `getById()` per row | needs verification (core cap) |
| F10 | low | integrity | admin (repair) | `REPLACE()` over whole `oc_files_metadata.json` documents can touch another app's value; `verifyTruncatedDuplicateGroups` falls back to the truncated prefix when the document lacks the key | confirmed, acknowledged in code |

## F1 — `findSameHash()` is an instance-wide content-equality oracle (high)

**Trigger:** any logged-in user. `GET /ocs/v2.php/apps/file_checksum_search/api/v1/file/{fileId}/duplicates`, no rate limit (`docs/api-v1.md:847-848` documents it as unlimited).

**Path.** `lib/Controller/PublicApiController.php:370-386`:

```php
#[NoAdminRequired]
#[NoCSRFRequired]
#[ApiRoute( verb: 'GET', url: '/api/v1/file/{fileId}/duplicates' )]
public function findDuplicates( int $fileId ): DataResponse
…
    $result = $this->api->findSameHash( $fileId );
```

Unlike its siblings (`:238/:247` and `:502/:517` resolve `$scope` and pass it),
no scope is computed or passed. `lib/Public/ChecksumApi.php:302-305`:

```php
public function findSameHash( int $fileId ): array
{
    $hashes = $this->metadataService->getHashes( $fileId );
```

`getHashes()` (`MetadataService.php:168-200`) reads the metadata document of
whatever id was given. Only the *candidate duplicates* are then filtered to the
session user's tree (`ChecksumApi.php:342-361`, `$userFolder->getById( $dupFileId )`);
the reference file never is. The response (`:363-375`) carries `algo`,
`hash_value` and the caller's own matching files.

**Scenario.** Alice holds `contract.pdf`. She sweeps `fileId = 1…N` (ids are
sequential); every id whose response is non-empty is a file, anywhere on the
instance, whose content equals one of hers — with its algorithm and hash.
With F2 she does not even need the content: uploading a one-byte file with
`OC-Checksum: SHA1:<H>` and calling `recalc?algo=md5` on it plants `H` in her
own tree, after which the sweep answers "which file ids on this instance have
hash H" for any `H` she knows.

**What it does not leak:** the foreign file's path, name or owner. A response
is empty both for a non-existent id and for an id with no equal in the
caller's tree, so plain existence is not distinguishable — only equality.

**Fix.** Give `findSameHash()` the same `?string $requestingUser` parameter as
`getHashesByFileId()` and refuse (`NotFoundException`) when
`userCanAccessFile()` fails, before `getHashes()`; pass
`resolveRequestingUserScope()` from the controller; add `#[UserRateLimit]`.
The tests (`tests/Unit/Public/ChecksumApiTest.php:600-761`) never assert
ownership of the reference id — add one that does.

## F2 — the index trusts client-supplied filecache checksums (medium)

**Trigger:** any user who can upload over WebDAV.

Core stores the `OC-Checksum` header verbatim,
`apps/dav/lib/Connector/Sabre/File.php:382-384` (NC 34.0.3):

```php
$checksumHeader = $this->request->getHeader('oc-checksum');
if ($checksumHeader) {
    $fileInfoUpdate['checksum'] = trim($checksumHeader);
```

This app copies every `ALGO:hex` pair of that column into the metadata
document on every recalculation, `lib/Service/HashCalculationService.php:927-941`:

```php
$checksums = $this->filecacheService->getChecksums( $file );
// Sync: copy hash from filecache.checksum → metadata
foreach ( $checksums as $prefix => $hexHash )
{
    $metaKey = MetadataService::getHashKey( $prefix );
    if ( ! $metadata->hasKey( $metaKey ) )
    {
        $metadata->setString( $metaKey, $hexHash, false );
```

`parseChecksumString()` (`FilecacheService.php:113-140`) accepts any token as
the algorithm and any string as the value. The copy is saved in `finally`
(`:1031-1033`) through `MetadataService::saveMetadata()` (`:3060-3070`), which
calls `syncHashIndex()` (`:2380-2410`) and writes an index row for it. Only
the algorithm actually requested is recomputed (`:945-960`); the others are
persisted as copied. The HTTP path `recalcHash → recalcFileHash → recalcHashes`
(`:1195-1234`, `:1153-1181`) reaches this, and so do the drain (`processFile`,
`:601-635`) and the repair step `rebuild-from-filecache`
(`HashIndexService::backfillFromFilecache`, `:145-192`), whose doc comment
(`RepairQuietStart.php:387`) calls the values "trusted".

**Consequences.** (a) F1 without possessing the content. (b) Any user can make
their file appear in anyone's duplicate group for any hash — for a victim who
receives a share of it, the sidebar will report the planted file as a
duplicate. (c) The algorithm token becomes the metadata key
`file-checksum-hash-<token>` (`MetadataService::getHashKey()`, `:3134-3138`);
`oc_files_metadata_index.meta_key` is `varchar(31)`
(`core/Migrations/Version28000Date20231004103301.php:58-61`), so a token longer
than 12 characters produces a row the column cannot hold. On a strict-mode
MySQL that is an exception inside `saveMetadata()` and a 500 on the user's own
recalc; on other backends it may truncate silently. **Not verified by
execution.**

**Fix.** In `recalcHashes()` and `backfillHashes()` copy only pairs whose
algorithm `$this->catalogue->isValid()` and whose value is lowercase hex of
the length that algorithm produces; better, never index a copied value until
this app has recomputed it, or record provenance and exclude unverified rows
from `queryByHash()` / `queryDuplicates()`.

## F3 — unbounded duplicates work per request (medium)

**Trigger:** any logged-in user. `GET /duplicates/data?minCount=1&algo=sha256`
(`lib/Controller/DuplicatesController.php:60-66`, no `#[UserRateLimit]`) or
`GET /api/v1/duplicates` (limited to 60/min, `PublicApiController.php:321`).

`minCount` is never clamped on the HTTP path: `DuplicatesController.php:65`
→ `HashIndexService::listDuplicatesForUser()` (`:212-225`) →
`DuplicateService::findAllDuplicates()` (`:51-58`) →
`MetadataService::queryDuplicates()` (`:2839-2842`):

```php
$qb->having(
    $qb->expr()
       ->gte( 'cnt', $qb->createNamedParameter( $minCount, IQueryBuilder::PARAM_INT ) ),
```

`minCount=1` (or `0`, or negative) makes every hashed file a group. The occ
command clamps it (`lib/Command/FindDuplicates.php:165`,
`$minCount = max( 2, $minCount );`); the controllers do not.

Independently of `minCount`, every per-user listing over-fetches
`UNFILTERED_GROUP_FETCH_LIMIT = 10000` groups (`HashIndexService.php:35`, `:225`),
each row carrying `MAX(m.json)` — a whole metadata document — and a
`GROUP_CONCAT` of every member id (`MetadataService.php:2795-2807`), read with
`fetchAll()` (`:2849`). For every group whose index value is 63 characters
(every sha256/sha384/sha512/sha3 group), `verifyTruncatedDuplicateGroups()`
then issues one `getMetadata()` per member (`:2923-2937`). The empty-file
hash alone can put thousands of ids into one group.

**Scenario.** One user, one request per second, each costing a 10 000-row
aggregate plus thousands of document reads. No admin action required.

**Fix.** Clamp `minCount` to `max( 2, … )` in both controllers (or in
`ChecksumApi::findDuplicates()`); add `#[UserRateLimit]` to
`DuplicatesController::findAll()`; replace the 10 000 over-fetch with keyset
paging that stops once `$limit` surviving groups are found; verify truncated
groups with one `fetchDocuments()` per group instead of one query per member.

## F4 — the rule sweep materialises every matched file id (medium)

**Trigger:** any enabled rule with a wide selector; runs every
`rule_processing_interval` (300 s, `ConfigLexicon.php:71-78`).

`lib/Service/RuleService.php:602-654`:

```php
$batchSize = 100;
…
foreach ( $this->sweepLocations( $rule ) as $location )
{
    …
    $matched ++;
    $fileIds[] = $location->fileId;
    …
    $updatedAt = $this->metadataService->getUpdatedAt( $location->fileId );
    …
    if ( $marked >= $batchSize ) { break; }
```

The break fires only on *marked* files. On an instance where most files are
fresh, `sweepLocations()` (`:714-757`) walks every filecache row of every
swept storage, `$fileIds[]` grows to the instance's file count, one
`getUpdatedAt()` query is issued per row, and `evaluateRules()` merges the
result into `$excludedFileIds` (`:272`) which the next rule `array_flip()`s
(`:614`). A `*` rule on ten million files means ten million ints held twice
and ten million point queries, every five minutes, in cron.

**Fix.** Stop the walk when the mark budget is spent (or when a time budget
is spent), track exclusion by resolving `governingRuleForLocation()` per
candidate as `applyRule()` already does (`:1258-1260`) instead of carrying id
sets between rules, and fold the freshness test into
`pageStorageFiles()` (a join on the stamp row) so fresh files are never
fetched.

## F5 — exception text echoed to rule editors (low)

`lib/Controller/RulesController.php:682-702`:

```php
return new DataResponse(
    [
        'success' => false,
        'error'   => $e->getMessage(),
    ],
    Http::STATUS_INTERNAL_SERVER_ERROR,
);
```

Reached from `create`, `update`, `destroy`, `reorder` by anyone with
rule-editing permission. A `\OCP\DB\Exception` message carries driver text and
SQL fragments. `PublicApiController` does this correctly (`:266-269`, generic
message, exception in the log). **Fix:** return `'Internal server error.'`.
`SettingsController.php:253-258` does the same but is admin-only.

## F6 — instance-wide counters to non-admins (low)

`lib/Controller/SettingsController.php:67-85` (`#[NoAdminRequired]`) returns
`rowCount`, `pendingStats`, `staleStats`, `jobs` (last-run times and counts)
and `idleBannerAcknowledged` to any user; only the admin page reads it
(`src/settings-admin-vue/composables/useAdminSettings.ts:62`).
`PublicApiController.php:279-311` returns `version`, `dbVersion`, `rowCount`,
`pendingRows`. Neither reveals a file, but both describe the instance
(database version, hashing backlog, how many files are indexed) to every
account. **Fix:** drop `#[NoAdminRequired]` from `/settings/status`; for
`/api/v1/status` either document it as public by design or answer
non-admins with `version` only.

## F7 — two mutating POSTs are CSRF-exempt (low, mitigated)

`PublicApiController.php:475-478` (`recalc`) and `RulesController.php:366-372`
(`rules/{id}/apply`) carry `#[NoCSRFRequired]`. In core that attribute skips
**both** checks — `SecurityMiddleware::beforeController()` ("Check for strict
cookie requirement" block) only runs `passesStrictCookieCheck()` when the
attribute is absent, and `isInvalidCSRFRequired()` returns false with it.

Mitigation: the session cookie is `SameSite=Lax`
(`lib/private/Session/Internal.php:224`), so a cross-site form POST carries no
session; and OCS accepts `OCS-APIRequest: true` in place of a token
(`lib/private/AppFramework/Http/Request.php:455-457`), which is what the
frontend already relies on (it also sends `requesttoken`,
`useSidebarHashes.ts:94`, `useDuplicates.ts:139`). The attribute buys nothing. **Fix:** remove it
from both methods; they are POSTs and the clients already send the header.

## F8 — `lookup` scoping is too narrow and limit-before-filter (low)

`lib/Service/DuplicateService.php:100-114`:

```php
$rows = $this->metadataService->queryByHash( $hash, $algo, $limit );
…
$fcPaths = $this->filecacheService->batchLookupFilecachePaths( $fileIds, $userName );
```

`queryByHash()` is called without `$visibleStorageIds`, so the database applies
`$limit` before the user filter — the defect the search provider was fixed
for (`HashSearchProvider.php:130-149`). A non-admin whose own copy sorts
behind `limit` foreign copies gets `[]`. The filter itself
(`FilecacheService.php:905-913`) is `s.id = 'home::<uid>'`: on primary object
storage (`object::user:<uid>`), and for received shares and group folders,
nothing is returned. Not a leak — the wrong direction — but the API answers
"not found" for files the user has. **Fix:** narrow with the user's mount
storage ids and resolve through `getUserFolder()->getById()` as
`findSameHash()` does.

## F9 — unified search limit passed through (low, needs verification)

`lib/Search/HashSearchProvider.php:145` passes `$query->getLimit()` to
`queryByHash()`; each row then costs `getById()` (`:157`) and, for long
hashes, a document read. Core's `UnifiedSearchController::search()` takes
`?int $limit = null` (`core/Controller/UnifiedSearchController.php:96`); I did
not trace whether it caps the value. If it does not, a search for the
empty-file hash with a huge `limit` is a cheap way to spend thousands of
queries. **Fix:** `min( $query->getLimit(), 100 )` in the provider regardless.

## F10 — whole-document rewrites (low)

`MetadataService::renameKeyInDocuments()` (`:1959-1993`) runs
`REPLACE(json, '"file-checksum-<algo>":', '"file-checksum-hash-<algo>":')`
over the entire document; a value of another app containing that literal would
be rewritten. The doc comment (`:1867-1869`) accepts this; it is repair-only
and narrowed to files the index says still hold the legacy key.
`verifyTruncatedDuplicateGroups()` (`:2931-2934`) falls back to the truncated
index value when a document lacks the key, so two such files would be grouped
by a 63-character prefix — only reachable when index and document disagree.
Both low; both worth a line in the code where the trade-off is made.

## Endpoint matrix

| Route | Verb | Attributes | Admin-only | Enforced where |
|-------|------|------------|------------|----------------|
| `/api/v1/preferences/{key}` | GET | NoAdmin, NoCSRF | no | own uid only (`PublicApiController.php:120-132`) |
| `/api/v1/preferences/{key}` | PUT | NoAdmin | no | own uid; value validated against catalogue (`:154-184`) |
| `/api/v1/algorithms` | GET | NoAdmin, NoCSRF | no | — |
| `/api/v1/file/{fileId}/hashes` | GET | NoAdmin, NoCSRF | no | scope → `userCanAccessFile()` (`ChecksumApi.php:96`) |
| `/api/v1/status` | GET | NoAdmin, NoCSRF | no | none (F6) |
| `/api/v1/duplicates` | GET | NoAdmin, NoCSRF, RateLimit 60/60 | no | session uid → `home::<uid>` filter |
| `/api/v1/file/{fileId}/duplicates` | GET | NoAdmin, NoCSRF | no | **none for the reference id (F1)** |
| `/api/v1/lookup` | GET | NoAdmin, NoCSRF, RateLimit 60/60 | no | scope → `home::<uid>` filter (F8) |
| `/api/v1/file/{fileId}/recalc` | POST | NoAdmin, NoCSRF, RateLimit 20/60 | no | scope → `userCanAccessFile()` (`ChecksumApi.php:407`) |
| `/api/v1/rules` | GET | NoAdmin, NoCSRF | `scope=all` only | code (`RulesController.php:107-110`) |
| `/api/v1/rules` | POST | NoAdmin | no | `authorizeWrite()` + validator + `ruleTargetRefusal()` |
| `/api/v1/rules/{id}` | PUT/DELETE | NoAdmin | no | `mayMutate()` (`:494-507`) |
| `/api/v1/rules/{id}/apply` | POST | NoAdmin, NoCSRF | no | `mayMutate()` (F7) |
| `/api/v1/rules/order` | PUT | NoAdmin | no | `reorderSegment()` own segment (`RuleService.php:1415-1418`) |
| `/duplicates/data` | GET | NoAdmin, NoCSRF | `?user=` only | code (`DuplicatesController.php:87-97`); no rate limit (F3) |
| `/duplicates` | GET | NoAdmin, NoCSRF (page) | no | — |
| `/settings/status` | GET | NoAdmin, NoCSRF | no | none (F6) |
| `/settings/idle-banner/ack` | POST | — | yes | middleware |
| `/settings/global` | GET | NoCSRF | yes | middleware |
| `/settings/global` | PUT | — | yes | middleware |
| `/admin/docs` | GET | NoCSRF | yes | middleware (`PageController.php:65-66`) |
| `/help` | GET | NoAdmin, NoCSRF | no | fixed file list (`:153-181`) |

`appinfo/routes.php` registers nothing; every route is an attribute, so the
table is complete.

## Sound

**Rule mutation cannot escalate.** `RuleDefinitionValidator::definitionFrom()`
(`:80-84`) fixes `selector` to `'home:' . $userId` and `admin_enforced` to
`false` for every non-admin, whatever the payload says, on create and update
alike. `canUserMutateRule()` (`RuleService.php:1591-1608`) requires: not
enforced, selector canonicalises to exactly `home:<uid>`, and
`ruleTargetRefusal()` null. `ruleTargetRefusal()` (`:1660-1694`) resolves only
the literal prefix of the glob (`pathToFolder()`, `:1700-1725`) through
`getUserFolder( $userId )->get()`, requires `IHomeStorage` (`:1675-1681`, so a
received share or group folder is refused with a reason) and `isCreatable()`.
Reorder is confined to the caller's own segment and must be an exact
permutation (`:1415-1418`, `:1451-1457`). `RulesController::update()` decides
`mayMutate()` against the stored rule before reading the body (`:255-266`).
`ChecksumApi` applies the same three checks for DI callers (`:505-527`,
`:661-673`). Sweeps match by identity (`selectorMatchesLocation()`,
`:770-799`): a `home:<uid>` rule can only ever govern rows on that user's home
storage; group folders and shares are a different namespace.

**Per-user reachability.** `getHashesByFileId()` and `recalcHash()` resolve
through `getUserFolder( $uid )->getById()` (`ChecksumApi.php:683-700`), which
covers received shares and mounted group folders. `HashSearchProvider` narrows
by the user's mounts, keeps `getById()` as the authority and confirms full
hashes (`:136-188`). `listDuplicatesForUser()` filters every member to
`home::<uid>` before counting (`HashIndexService.php:246-269`). Admin scope
(`null`) is unrestricted by design and documented (`docs/api-v1.md:79`).

**63-character truncation.** Every read path confirms the full value:
`confirmFullHash()` (`MetadataService.php:707-735`) in `findSameHash`
(`ChecksumApi.php:325-328`), `DuplicateService::findByHash()` (`:120`) and the
search provider (`HashSearchProvider.php:141-149`); duplicate groups are split by full value
(`verifyTruncatedDuplicateGroups()`, `:2902-2954`). No truncated prefix reaches
a client, across users or otherwise, except the F10 fallback.

**SQL.** Every user-influenced value is bound: the hash (`MetadataService.php:2684`),
the algorithm key (`:2748`, via `eq`), `minCount` (`:2841`), storage ids
(`:2707-2710`, `PARAM_INT_ARRAY`), file id chunks of at most 1000
(`FilecacheService.php:218-223`, `MetadataService.php:938`). The only
`createFunction()` calls are constant text (`COUNT(DISTINCT file_id)`,
`MAX(m.json)`, `:1353`, `:2801-2805`), a `REPLACE()` whose operands are named
parameters (`:1974-1980`), and a sub-query whose one parameter is created on
the outer builder (`:1747`, `:1755-1757`). LIKE inputs derived from user data
are escaped (`escapeLikeParameter`, `:2737`, `:520`); rule globs are
escaped by `globToLike()` (`:1839-1865`) and post-filtered with `fnmatch`.
The lone raw statement is `SELECT VERSION()` (`DatabaseService.php:64`).

**No command execution.** No `exec`, `shell_exec`, `system`, `passthru`,
`proc_open`, `popen` anywhere under `lib/`.

**No path traversal.** Rule paths are globs matched against filecache rows
(`sweepLocations()`, `PathUtil::matchesRelativeGlob()`), never opened. The one
filesystem resolution of a user-supplied path goes through
`Folder::get()`, and core rejects `..` (`lib/private/Files/Filesystem.php:400-409`,
`Folder::getFullPath()` `:58-64`), caught as a refusal (`RuleService.php:1690-1693`).
`getHashesByPath()` with `$user = null` (`ChecksumApi.php:153-180`) is DI-only;
no route reaches it (`docs/api-v1.md:311`). `HashFiles --path` is occ.

**Hashing streams.** `hash_file()` for local storage, `hash_update_stream()`
otherwise (`HashCalculationService.php:1055-1074`); multi-algorithm reads in
8 KiB chunks (`:35`, `:1111-1124`); per-file exclusive lock (`:104-120`,
released in `finally`, `:1036`).

**Batches are bounded on every server-driven path.** Pending drain: 50 per
run (`ProcessPendingUpdates.php:82-86`), re-dispatch when full (`:170-173`).
Orphan purge: `pending_batch_limit` × at most 20 batches per run
(`RuleProcessingJob.php:47`, `:161-167`), and `fetchOrphanedFileIds()` caps
both queries (`MetadataService.php:489`, `:525`). Repair steps page by keyset
(`pageHashedFileIdsAfter`, `pageHashDocumentsAfter`, `walkHashDocuments`);
`markStale` chunks at 1000; the full-scan step is `manualOnly`
(`RepairQuietStart.php:571-582`, gate `:133`). "Batch size 0 = unlimited"
exists only in occ (`HashFiles.php:313`, `collectFilesForUser()` `HashCalculationService.php:154`),
never over HTTP. HTTP limits are clamped server-side: 1–500 for lookup and
duplicates (`ChecksumApi.php:231`, `:273`; `DuplicatesController.php:82`).

**Metadata keys.** Admin-set algorithm names must match `[a-z0-9-]+`
(`AlgorithmCatalogue.php:65`) and exist in `hash_algos()`; API-supplied
algorithms pass `isValid()` before any write
(`HashCalculationService.php:867`); search terms are hex-only
(`MetadataService::parseQueryTerm()`, `:3177`). The only unvalidated key source is
the filecache column — F2.

**Shared `oc_files_metadata` document.** Only `file-checksum-*` keys are
written or removed (`removeStartsWith( self::KEY_FILE_CHECKSUM_PREFIX )`,
`MetadataService.php:365`, `:407`, `:887`); `purgeMetadata()` deletes a document only when
nothing else remains (`:409-413`). The app reads other apps' keys nowhere.

**Frontend.** No `v-html`, `innerHTML` write, `document.write` or
`insertAdjacentHTML` under `src/` (the one `innerHTML` is a read in an escape
helper, `utils.ts:17`). Every server field is rendered through `{{ }}`
(`RuleRow.vue`, `DuplicateGroup.vue:47-48`, `ChecksumsSidebarTab.vue:141-143`, `:208`, `:215`).
Links are built with `generateUrl()` and `encodeURIComponent()`
(`useDuplicates.ts:186`, `ChecksumsSidebarTab.vue:103`). The docs viewer
(`DocsViewer.vue:143-148`) renders Markdown with `NcRichText` from a
server-fixed list of bundled files (`PageController.php:153-181`); no user
input reaches it. PHP templates emit nothing dynamic
(`templates/duplicates.php`; `partials/settings-header.php:21` inlines the
app's own SVG).

**Logging.** Nothing logs a hash, a file path or a request body at `info` or
above. The audit lines (`RuleService.php:1160-1181`) carry rule id, glob,
selector, verdict and actor — the rule, not the files. Exceptions are passed
as objects. Everything with a `fileId` or a uid is `debug`.

**CSRF and cookies elsewhere.** Every mutating route except the two in F7
lacks `#[NoCSRFRequired]`, so core enforces both the strict-cookie check and
the token (or `OCS-APIRequest`). Admin settings PUT/POST are admin-only by
absence of `#[NoAdminRequired]`.

**Preferences.** Read and write only the session uid's own key; the key name
is whitelisted (`PublicApiController.php:127`, `:154`); the value must be an
algorithm in force.

## What I could not verify

- Nothing was executed. Every finding above is a trace through the source;
  none was reproduced against the test instance, and I did not read its
  database.
- F2's failure mode for over-long `meta_key` values depends on the backend's
  strict mode; I read the column width from the core migration only.
- F3's real cost depends on the backend: MySQL truncates `GROUP_CONCAT` at
  `group_concat_max_len` (default 1024 characters, so a large group silently
  loses members — a correctness defect in its own right); PostgreSQL does not.
- F9: whether core caps the unified-search `limit` before it reaches
  `ISearchQuery::getLimit()`.
- Whether `IUserMountCache::getMountsForUser()` and `Folder::getById()` behave
  identically on primary object storage; F8's `home::` filter certainly does
  not.
- `NcRichText`'s HTML sanitisation with `useExtendedMarkdown`. Irrelevant
  while the docs are bundled files; relevant the day any endpoint serves
  user-supplied Markdown through the same component.
- Concurrency of whole-document `saveMetadata()` against another app writing
  the same document at the same time — a core read-modify-write, not this
  app's, but this app performs it on every hash write.
- The `ApiController` base class (not `OCSController`) on `#[ApiRoute]`
  routes: I confirmed the attribute registers under the OCS section and that
  the frontend calls them through `generateOcsUrl()`; I did not trace whether
  core applies any OCS-specific middleware to a non-`OCSController` on that
  path.
