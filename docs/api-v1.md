# FCIAS Public API v1

Stable public API for the File Checksum Index & Search Nextcloud app. Three consumer surfaces share the same underlying contract:

| Surface | Audience | Access Method |
|---------|----------|---------------|
| **HTTP REST** | Scripts, external tools, other services | HTTP requests to `/ocs/v2.php/apps/file_checksum_search/api/v1/` |
| **PHP DI** | Other Nextcloud apps (in-process) | Dependency injection via `\OCP\Server::get()` |
| **PHP Bootstrap** | External PHP apps | `require_once` NC base, then container lookup |

All three surfaces use the same [`ChecksumApi`](../lib/Public/ChecksumApi.php) class as their single public contract.

## Table of Contents

1. [PHP API](#php-api)
2. [HTTP REST API](#http-rest-api)
3. [Rules](#rules)
4. [Authentication](#authentication)
5. [Versioning & Compatibility](#versioning--compatibility)
6. [Rate Limiting](#rate-limiting)
7. [Error Handling](#error-handling)

---

## PHP API

### Class: `OCA\FileChecksumSearch\Public\ChecksumApi`

Located at [`lib/Public/ChecksumApi.php`](../lib/Public/ChecksumApi.php). This is the **single public contract** — all HTTP endpoints delegate to the same methods, guaranteeing behavioral equivalence.

#### Dependency Injection (within NC)

```php
use OCA\FileChecksumSearch\Public\ChecksumApi;

class MyService {
    public function __construct(
        private ChecksumApi $checksumApi,
    ) {}
    
    public function doSomething(): void {
        $result = $this->checksumApi->findByHash('da39a3ee5e6b4b0d3255bfef95601890afd80709');
    }
}
```

#### Bootstrap Access (external PHP app)

```php
<?php
$ncRoot = '/var/www/nextcloud';
require_once "$ncRoot/lib/base.php";

/** @var \OCA\FileChecksumSearch\Public\ChecksumApi $api */
$api = \OC::$server->get(\OCA\FileChecksumSearch\Public\ChecksumApi::class);

// Search by hash
$result = $api->findByHash('da39a3ee5e6b4b0d3255bfef95601890afd80709');

// Get hashes for a file object
$file = \OC::$server->getRootFolder()->getUserFolder('alice')->get('Documents/report.pdf');
$hashes = $api->getHashesByFile($file);

// Get hashes by path (relative to user root)
$hashes = $api->getHashesByPath('Documents/report.pdf', 'alice');
```

### Method Reference

#### `findByHash(string $hash, ?string $algo = null, int $limit = 100, ?string $requestingUser = null): array`

Search for files matching a given hash value.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$hash` | `string` | Yes | Hex-encoded hash (8/32/40/64/128 chars depending on algorithm) |
| `$algo` | `?string` | No | Algorithm filter — any name `GET /api/v1/algorithms` lists for this instance |
| `$limit` | `int` | No | Max results (1–500, default 100) |
| `$requestingUser` | `?string` | No | Whose permissions the answer is checked against. `null` means server-side authority — the caller has already established who is asking, or is the server itself. A uid filters the result to what that user could open. |

**Returns:**
```php
[
    'results' => [
        ['fileid' => 12345, 'algo' => 'sha1', 'hash' => 'da39a3...', 'path' => 'Documents', 'name' => 'report.pdf'],
        // ...
    ],
]
```

**Throws:** `\InvalidArgumentException` if hash is empty.

---

#### `getHashesByFileId(int $fileId, ?string $requestingUser = null): array`

Get all checksums for a file by its filecache ID.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$fileId` | `int` | Yes | The filecache `fileid` |
| `$requestingUser` | `?string` | No | Whose permissions the answer is checked against. `null` means server-side authority — the caller has already established who is asking, or is the server itself. A uid filters the result to what that user could open. |

**Returns:**
```php
[
    'fileid' => 12345,
    'hashes' => [
        ['algo' => 'sha1', 'hash' => 'da39a3...', 'updated_at' => '2026-08-18T10:00:00+00:00'],
        ['algo' => 'sha256', 'hash' => 'e3b0c4...', 'updated_at' => '2026-08-18T10:00:00+00:00'],
    ],
]
```

---

#### `getHashesByFile(\OCP\Files\File $file): array`

Convenience method — get all checksums for a `File` object. Resolves `$file->getId()` internally.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$file` | `\OCP\Files\File` | Yes | A Nextcloud File node |

**Returns:** Same shape as `getHashesByFileId()`.

**Throws:** `\OCP\Files\NotFoundException` if the file cannot be resolved.

---

#### `getHashesByPath(string $path, ?string $user = null): array`

Convenience method — get checksums by filesystem path.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$path` | `string` | Yes | Filesystem path (see resolution rules below) |
| `$user` | `?string` | No | If provided, path is relative to this user's home folder |

**Path resolution rules:**

| `$user` | Path interpretation | Example |
|---------|-------------------|---------|
| `null` | Absolute filesystem path OR relative to NC data root | `$api->getHashesByPath('/alice/files/Photos/img.jpg')` |
| `'alice'` | Relative to Alice's home folder | `$api->getHashesByPath('Photos/img.jpg', 'alice')` |

**Returns:**
```php
[
    'fileid' => 12345,
    'path' => 'Photos/img.jpg',
    'hashes' => [
        ['algo' => 'sha1', 'hash' => 'da39a3...'],
    ],
]
```

**Throws:** `\OCP\Files\NotFoundException` if the path cannot be resolved to a file.

---

#### `findDuplicates(?string $algo = null, int $minCount = 2, int $limit = 50, int $offset = 0): array`

Find duplicate hash groups among the files the calling user can open.

Not instance-wide: the method resolves the session user and asks for that
user's duplicates, so a caller with no session gets an empty set. An
administrator sees their own files, not everyone's.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$algo` | `?string` | No | Algorithm filter |
| `$minCount` | `int` | No | Minimum files per group (default 2) |
| `$limit` | `int` | No | Max groups (1–500, default 50) |
| `$offset` | `int` | No | Pagination offset |

**Returns:**
```php
[
    'duplicates' => [
        [
            'algo' => 'sha1',
            'hash_value' => 'da39a3...',
            'file_count' => 3,
            'files' => [
                ['fileid' => 100, 'path' => 'Documents', 'name' => 'a.pdf'],
                ['fileid' => 200, 'path' => 'Photos', 'name' => 'b.pdf'],
                ['fileid' => 300, 'path' => 'Backup', 'name' => 'c.pdf'],
            ],
        ],
    ],
    'total_groups' => 1,
    'pagination' => ['offset' => 0, 'limit' => 50],
]
```

---

#### `findSameHash(int $fileId): array`

Find other files sharing hash values with the given file.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$fileId` | `int` | Yes | The filecache `fileid` of the reference file |

**Returns:**
```php
[
    'duplicates' => [
        [
            'algo' => 'sha1',
            'hash_value' => 'da39a3...',
            'files' => [
                ['fileid' => 200, 'path' => 'Photos', 'name' => 'copy.jpg'],
            ],
        ],
    ],
]
```

---

#### `recalcHash(int $fileId, ?string $algo = null, ?string $requestingUser = null): array`

Trigger hash recalculation for a file. **This is the only mutating operation** in the public API.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$fileId` | `int` | Yes | The filecache `fileid` |
| `$algo` | `?string` | No | Algorithm (default: `sha1`) |
| `$requestingUser` | `?string` | No | Whose permissions the answer is checked against. `null` means server-side authority — the caller has already established who is asking, or is the server itself. A uid filters the result to what that user could open. |

**Returns (success):**
```php
['success' => true, 'algo' => 'sha1', 'hash' => 'da39a3...', 'fileid' => 12345]
```

**Returns (failure):**
```php
['success' => false, 'error' => 'File not found.']
```

---

#### `getStatus(): array`

Read-only health/status snapshot.

**Returns:**
```php
[
    'version' => '1.9.2',
    'dbVersion' => '10.11.6',
    'rowCount' => 15423,
    'pendingRows' => 5,
]
```

### Rules

The rules surface mirrors the HTTP endpoints below — same validator, same
permission rules, same audit log — so a calling app cannot express something
REST would refuse. See [Rules](#rules) for the rule shape, the selector grammar
and the band model.

Every method takes an optional `$requestingUser`. **`null` means the caller is
server-side code acting with full authority** (the occ-equivalent); a non-null
user is enforced exactly as REST enforces that user. Mutations are audit-logged
with the actor named — `api` for a trusted caller, the uid otherwise.

| Method | Notes |
|--------|-------|
| `listRules(?string $requestingUser = null): array` | `['rules' => [...], 'canCreate' => bool]`. With a user, the personal view: the rules that can decide their files, their own marked editable |
| `createRule(array $definition, ?string $requestingUser = null): string` | Returns the new rule's id. From a non-administrator, `selector` is forced to their own home and `admin_enforced` to `false` |
| `updateRule(string $id, array $definition, ?string $requestingUser = null): void` | Omitted fields keep their stored values; enabling or disabling is an update of `enabled` |
| `deleteRule(string $id, ?string $requestingUser = null): void` | A deleted shipped default is recreated (disabled) by the repair step |
| `applyRule(string $id, ?string $requestingUser = null): array` | Runs the apply pass **synchronously** and returns its counts — `['matched' => int, 'marked' => int, 'skipped' => int, 'fresh' => int]` — where the REST endpoint queues a background job. Refuses a disabled or non-`include` rule |

Anything the caller may not do raises `InvalidArgumentException`; an unknown id
does the same.

---

## HTTP REST API

All endpoints are served over OCS, under `/ocs/v2.php/apps/file_checksum_search/api/v1/` —
`#[ApiRoute]` registers routes in Nextcloud's OCS collection, so the prefix is not optional.
Responses are nonetheless plain JSON: these are `ApiController`s, not `OCSController`s, so no
`{"ocs": {"meta": …, "data": …}}` envelope wraps the body.

### Endpoint Catalog

| # | Endpoint | Method | PHP Method | Description |
|---|----------|--------|------------|-------------|
| 1 | `/api/v1/lookup` | GET | `findByHash` | Search files by hash |
| 2 | `/api/v1/file/{fileId}/hashes` | GET | `getHashesByFileId` | Get checksums for a file |
| 3 | `/api/v1/file/{fileId}/duplicates` | GET | `findSameHash` | Find same-hash files |
| 4 | `/api/v1/file/{fileId}/recalc` | POST | `recalcHash` | Recalculate hash |
| 5 | `/api/v1/duplicates` | GET | `findDuplicates` | Global duplicate groups |
| 6 | `/api/v1/status` | GET | `getStatus` | Health/status |
| 7 | `/api/v1/rules` | GET | — | List hash-generation rules |
| 8 | `/api/v1/rules` | POST | — | Create a rule |
| 9 | `/api/v1/rules/{id}` | PUT | — | Update a rule (including enable/disable) |
| 10 | `/api/v1/rules/{id}` | DELETE | — | Delete a rule |
| 11 | `/api/v1/rules/order` | PUT | — | Reorder one segment partition |
| 12 | `/api/v1/rules/{id}/apply` | POST | — | Queue a full apply pass for one rule |
| 13 | `/api/v1/algorithms` | GET | — | The algorithms this instance computes, and its default |
| 14 | `/api/v1/preferences/{key}` | GET, PUT | — | One of the caller's own preferences; first key `preferred_algorithm` |
| 15 | `/api/v1/sudo/file/{fileId}/hashes` | GET | — | Any account's file; password confirmation, sudoers only |
| 16 | `/api/v1/sudo/file/{fileId}/duplicates` | GET | — | Any account's file, duplicates from every account; as 15 |
| 17 | `/api/v1/sudo/lookup` | GET | — | Every account; as 15 |
| 18 | `/api/v1/sudo/duplicates` | GET | — | One named account (`user`), a set (`users[]`/`groups[]`, merged), or every account; sudoers, or a sub-admin naming their own members |

> **Note:** `getHashesByFile()` and `getHashesByPath()` are PHP-only convenience methods with no HTTP equivalent. HTTP consumers should use `getHashesByFileId()` after obtaining a `fileId` from NC's WebDAV PROPFIND or other APIs.

### Endpoint Details

#### 1. Lookup by Hash

```
GET /ocs/v2.php/apps/file_checksum_search/api/v1/lookup?hash=<hex>&algo=<algo>&limit=<n>
```

| Parameter | Type | Required | Default | Max |
|-----------|------|----------|---------|-----|
| `hash` | string | **Yes** | — | — |
| `algo` | string | No | — | — |
| `limit` | int | No | 100 | 500 |

**Response (200):**
```json
{
  "results": [
    {
      "fileid": 12345,
      "algo": "sha1",
      "hash": "da39a3ee5e6b4b0d3255bfef95601890afd80709",
      "path": "Documents",
      "name": "report.pdf"
    }
  ]
}
```

**Error (400):**
```json
{"error": "Hash parameter is required."}
```

---

#### 2. Get Hashes by File ID

```
GET /ocs/v2.php/apps/file_checksum_search/api/v1/file/{fileId}/hashes
```

| Parameter | Type | Required |
|-----------|------|----------|
| `fileId` | int (path) | **Yes** |

**Response (200):**
```json
{
  "fileid": 12345,
  "hashes": [
    {"algo": "sha1", "hash": "da39a3ee5e6b4b0d3255bfef95601890afd80709", "updated_at": "2026-08-18T10:00:00+00:00"},
    {"algo": "sha256", "hash": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", "updated_at": "2026-08-18T10:00:00+00:00"}
  ],
  "algos": ["sha256", "sha1"],
  "preferred": "sha256",
  "default": "sha1"
}
```

The three fields after `hashes` are what the files sidebar composes its quick
buttons from: `algos` are the algorithms the file's governing `include` rule
computes (empty when no rule maintains the file), `preferred` is the asking
user's stored preference where it is still in force (empty otherwise), and
`default` is the instance's. The first button is `preferred`, else `default`;
the second is the first of `algos` that differs from it.

---

#### 3. Find Same-Hash Files

```
GET /ocs/v2.php/apps/file_checksum_search/api/v1/file/{fileId}/duplicates
```

| Parameter | Type | Required |
|-----------|------|----------|
| `fileId` | int (path) | **Yes** |

**Response (200):**
```json
{
  "duplicates": [
    {
      "algo": "sha1",
      "hash_value": "da39a3ee5e6b4b0d3255bfef95601890afd80709",
      "files": [
        {"fileid": 200, "path": "Photos", "name": "copy.jpg"}
      ]
    }
  ]
}
```

---

#### 4. Recalculate Hash

```
POST /ocs/v2.php/apps/file_checksum_search/api/v1/file/{fileId}/recalc
Content-Type: application/json

{"algo": "sha256"}
```

| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `fileId` | int (path) | **Yes** | — |
| `algo` | string (body or query) | No | `sha1` |

**Response (200):**
```json
{"success": true, "algo": "sha256", "hash": "e3b0c4...", "fileid": 12345}
```

**Error (400):**
```json
{"success": false, "error": "Unsupported algorithm: sha999"}
```

**Refused (403)** — an `exclude` rule covers the file:
```json
{
  "success": false,
  "error": "Hashing is excluded for this path by an administrator rule.",
  "excluded": true,
  "ruleId": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"
}
```

**Refused (403)** — the account may not calculate by hand:
```json
{
  "success": false,
  "error": "This account may not calculate by hand.",
  "forbidden": true
}
```

`exclude` means the file must not be read at all, so a manual recalculation is
refused along with every automatic route. The second refusal is the *Who may
calculate by hand* permission (admin settings, *Permissions* tab), which gates triggering a
computation on top of owning the file; reading what is already computed is
untouched, and the sidebar hides its Recalculate buttons for such an account
rather than offering them to fail. Members of `admin` always may. Both
statuses are 403 rather than 400 because the request is well-formed and
retrying it will not help — a client should surface the reason instead of
treating it as a transient failure.
`ruleId` names the rule that decided, so an administrator can find it in the
rules table. An `ignore` rule does **not** produce this: hashing on request is
exactly what `ignore` still allows.

---

#### 5. Find All Duplicates

```
GET /ocs/v2.php/apps/file_checksum_search/api/v1/duplicates?algo=<algo>&minCount=<n>&limit=<n>&offset=<n>
```

| Parameter | Type | Required | Default | Max |
|-----------|------|----------|---------|-----|
| `algo` | string | No | — | — |
| `minCount` | int | No | 2 | — |
| `limit` | int | No | 50 | 500 |
| `offset` | int | No | 0 | — |

**Response (200):**
```json
{
  "duplicates": [
    {
      "algo": "sha1",
      "hash_value": "da39a3ee5e6b4b0d3255bfef95601890afd80709",
      "file_count": 3,
      "files": [
        {"fileid": 100, "path": "Documents", "name": "a.pdf"},
        {"fileid": 200, "path": "Photos", "name": "b.pdf"},
        {"fileid": 300, "path": "Backup", "name": "c.pdf"}
      ]
    }
  ],
  "total_groups": 1,
  "pagination": {"offset": 0, "limit": 50}
}
```

---

#### 6. Status

```
GET /ocs/v2.php/apps/file_checksum_search/api/v1/status
```

No parameters.

**Response (200):**
```json
{
  "version": "1.9.2",
  "dbVersion": "10.11.6",
  "rowCount": 15423,
  "pendingRows": 5
}
```

#### 7. Algorithms

```
GET /ocs/v2.php/apps/file_checksum_search/api/v1/algorithms
```

No parameters.

**Response (200):**
```json
{
  "algorithms": ["sha1", "md5", "adler32", "crc32", "sha256", "sha384", "sha512", "sha3-256", "sha3-384", "sha3-512"],
  "default": "sha1"
}
```

The set is not fixed. It is what this server's PHP offers, narrowed to what the
administrator has allowed (admin settings → *Hash Algorithms*); the example above
is the shipped default. Every `algo` parameter in this API accepts exactly the
names this endpoint lists, and `default` — the administrator's designation,
else the first allowed — is what is used when none is given. A
client that carries its own list will be wrong the day an administrator changes
this one — ask, and cache per session.

Names are restricted to `[a-z0-9-]+` because they double as metadata keys, so
PHP algorithms such as `sha512/256` or `tiger192,3` are never offered.


#### 8. Preferences

```
GET /ocs/v2.php/apps/file_checksum_search/api/v1/preferences/{key}
PUT /ocs/v2.php/apps/file_checksum_search/api/v1/preferences/{key}
```

Per-user preferences, the caller's own. The namespace was reserved for this;
its first key is `preferred_algorithm`, the algorithm the sidebar offers first.
Any other key answers 404.

**Response (200), both verbs:**
```json
{
  "key": "preferred_algorithm",
  "value": "sha256",
  "default": "sha1",
  "active": "sha256"
}
```

`value` is what the user stored (empty when nothing), `default` the instance's,
`active` which of the two applies — a stored preference the administrator has
since disallowed is kept but not applied, so `active` falls back to `default`
until the user picks again.

**PUT body:** `{"value": "sha256"}`. An empty value returns to the default. For
`preferred_algorithm` the value must be one `GET /api/v1/algorithms` lists;
anything else is 400. Requires a session (401 without one).
---

## Rules

Hash-generation rules, as configured on the admin and personal settings pages.
These endpoints have no `ChecksumApi` equivalent — they are HTTP-only.

Nothing is hashed until a rule is enabled: a fresh installation ships two disabled rules
(`home:*` and `*`, both with path `**`), and an integration that expects hashes to appear on their
own should check that at least one enabled `include` rule exists.

### Priority bands

Rules are evaluated top to bottom and **the first match decides the file**. A rule's position is
not free-form: it is derived from what the rule *is*.

| Band | Selector addresses | `admin_enforced` |
|------|--------------------|------------------|
| 1 | one user's home (`home:<uid>`) or one storage (`storage:<id>`) | true |
| 2 | one group (`group:<gid>`) or one group folder (`groupfolder:<id>`) | true |
| 3 | every home folder (`home:*`) | true |
| 4 | everything (`*`) | true |
| 5 | one user's home or one storage | false |
| 6 | one group or one group folder | false |
| 7 | every home folder | false |
| 8 | everything | false |

Enforced beats unenforced; within each half, specific beats general. So a user's rule can override
the non-enforced defaults below it, but can never outrun an enforced one. A rule changes band by
changing its `selector` or `admin_enforced` — never by reordering, which only permutes rules
*inside* one segment.

A **segment** is one distinct `selector` value *within one band* — the same selector's enforced and
unenforced rules are separate segments, and their positions count independently — and reordering
happens inside one. Within every
segment, rules whose `path` is the bare catch-all (`**`, `/`, or empty) form a trailing **defaults
partition**: created rules are inserted before it, and a reorder may not move a rule across it.
Rules carry `isDefault` so a client can render that boundary without re-deriving it.

`band` and `position` are returned per rule and are **computed, never stored**. Do not send them.
There is deliberately no combined `"<band>.<position>"` field: it would be a third value derived
from two already present, free to disagree with them. Compose it client-side if you display it.

### Rule shape

| Field | Type | Notes |
|-------|------|-------|
| `id` | string | 32 hex characters; server-assigned |
| `enabled` | bool | |
| `type` | string | `include` (default) \| `ignore` \| `exclude` |
| `path` | string | glob, Symfony Finder `**` syntax |
| `selector` | string | `home:<uid>` \| `group:<gid>` \| `home:*` \| `groupfolder:<id>` \| `storage:<raw id>` \| `*` — split at the **first** colon, so a raw storage id may contain more |
| `algos` | string[] | include rules only; each a name `GET /api/v1/algorithms` lists |
| `mode` | string | include rules only: `auto` \| `missing` \| `force` \| `lazy` |
| `admin_enforced` | bool | administrator-only |
| `isDefault` | bool | computed: the rule's path is a bare catch-all, placing it in its segment's defaults partition |
| `band`, `position` | int | computed, read-only |
| `canEdit` | bool | computed for the calling user |

An `ignore` or `exclude` rule computes nothing, so it stores no `algos` and no `mode`; sending
them is not an error, they are simply not kept.

### `GET /api/v1/rules`

| Parameter | Values | Default | Notes |
|-----------|--------|---------|-------|
| `scope` | `own`, `all` | `own` | `all` requires administrator rights (403 otherwise) |

`scope` selects a **view**, not a permission. `own` lists the rules that can decide the caller's own
files — those whose selector reaches their home folder, able to match a path they can see — and
marks only their own as editable. `all` is the administrator's whole-instance view.

An administrator asking for `own` gets the personal view: the capability exists but is not
exercised. That is what lets the personal settings page stay personal for everyone. It is a
convenience for honest clients, not a security boundary — mutations are judged on capability
alone, so nothing depends on a client honouring it.

**Response (200):**
```json
{
  "success": true,
  "rules": [
    { "id": "0f1e…", "enabled": true, "type": "include", "path": "**/*.pdf",
      "selector": "home:*", "algos": ["sha256"], "mode": "auto",
      "admin_enforced": false, "isDefault": false,
      "band": 7, "position": 1, "canEdit": true }
  ],
  "canCreate": true,
  "supportedAlgos": ["sha1", "md5", "sha256"],
  "modes": ["auto", "missing", "force", "lazy"],
  "types": ["include", "ignore", "exclude"],
  "availableUsers": ["alice"],
  "availableGroups": ["staff"],
  "groupFoldersAvailable": true,
  "groupFoldersLabel": "Team Folders",
  "availableGroupFolders": [{ "id": 1, "name": "Team Docs" }],
  "availableStorages": ["smb::user@host//share/"]
}
```

The picker fields are present only for `scope=all` — they exist to populate selector pickers, and
no other view can assign those selectors:

| Field | Notes |
|-------|-------|
| `availableUsers`, `availableGroups` | for `home:<uid>` and `group:<gid>` |
| `groupFoldersAvailable` | whether the groupfolders app is installed and enabled; `false` means `groupfolder:` selectors cannot be offered at all |
| `groupFoldersLabel` | what that app calls itself ("Team Folders"); `null` when it is absent |
| `availableGroupFolders` | the folders that exist, as `{id, name}` |
| `availableStorages` | raw ids of storages only `storage:<id>` or `*` reaches — home storages, group folder jails, share wrappers and the instance root are excluded, since other selectors own them or no rule could match in them |

The last four are a soft dependency on another app: when it is missing, the fields degrade to
`false`/`null`/`[]` rather than failing the request. Together with each rule's `isDefault`, they are
enough for a client to show which namespaces have no catch-all rule of their own.

### `POST /api/v1/rules` — create

Body is the rule shape above; the response echoes the **stored** rule, including the server-assigned
`id` — the only way a caller learns it. The derived view fields (`band`, `position`, `canEdit`) are
annotations of the list endpoint and are not part of this response.

From a non-administrator, `selector` is forced to `home:<caller>` and
`admin_enforced` to `false`, whatever the payload says. A non-administrator must also have write
access to the rule's path **on their own home storage**: a path leading into a received share, a
group folder or another mounted storage is refused with that reason (403), because such a rule
could never match — those files answer to their owner's rules or to the folder's own.

### `PUT /api/v1/rules/{id}` — update

Same body, and the response likewise echoes the stored rule — so a partial update comes back as the
whole rule rather than the fragment that was sent. **Enabling or disabling a rule is an update of
`enabled`** — there is no separate
toggle endpoint. Omitted fields keep their stored values, so `{"enabled": false}` is a complete
and safe request.

Changing `selector` or `admin_enforced` moves the rule to the **end of its new segment**, since
position has no meaning across segments. Changing `path` into or out of a bare catch-all likewise
moves it between its segment's partitions.

### `DELETE /api/v1/rules/{id}`

Any rule the caller may mutate can be deleted, the two shipped defaults included: a repair step
recreates a missing one, disabled, so deleting one is reversible housekeeping rather than a
decision that cannot be taken back.

### `POST /api/v1/rules/{id}/apply` — apply one rule now

Queues a full apply pass: every file the rule currently governs is marked for background
hashing, uncapped (unlike the periodic sweep). The request enqueues a one-shot background job
and returns immediately —

```json
{ "success": true, "queued": true }
```

— the scan itself happens out of band, and its outcome appears in the audit log naming the
requesting user. Applying is judged as *writing* the rule: an administrator may apply any rule,
anyone else needs rule-editing permission and the rule must be their own. Files claimed by a
higher-band rule are skipped, never marked; files already fresh are skipped under `auto`/
`missing` modes.

**Errors:** 404 for an unknown id; 403 for a caller who may not change the rule; 400 for a rule
that cannot meaningfully be applied — disabled, or an `ignore`/`exclude` rule, which computes
nothing. These are refused at submission time rather than becoming a background job that can
only fail out of sight.

### `PUT /api/v1/rules/order` — reorder one segment partition

```json
{ "selector": "home:alice", "defaults": false, "orderedIds": ["0f1e…", "2a3b…"] }
```

A reorder addresses one **segment partition**: all rules sharing one `selector`, split by whether
their path is a bare catch-all (`defaults`). `orderedIds` must be **exactly a permutation** of that
partition's rule IDs — never a partial order, which would silently drop rules from evaluation, and
never a mix of the two partitions, which would let a rule cross the defaults boundary.

Rules addressing different things never compete for a file, so a reorder cannot change which rule
wins across segments; only the order inside one. A non-administrator may reorder only their own
segment (`home:<caller>`), whatever the payload names.

**Errors:** 400 on a non-permutation, an unorderable band, or an ID from another band; 403 for a
caller without rule-editing permission.

---

## Authentication

All HTTP endpoints require authentication. The API accepts three auth methods:

### 1. Session Cookie (Browser / NC UI)

Automatic for users already logged into Nextcloud. No additional setup needed.

### 2. HTTP Basic Auth

```
Authorization: Basic base64(username:app_password)
```

Create an app password in Nextcloud: **Settings → Security → Devices & sessions → App password**.

```bash
curl -u alice:your-app-password \
  "https://nc.example.com/ocs/v2.php/apps/file_checksum_search/api/v1/status"
```

### 3. Bearer Token

```
Authorization: Bearer <app_password_or_oauth_token>
```

```bash
curl -H "Authorization: Bearer your-app-password" \
  "https://nc.example.com/ocs/v2.php/apps/file_checksum_search/api/v1/status"
```

### CSRF

Most read endpoints carry `#[NoCSRFRequired]`, but the four rule mutations and the
two settings POSTs do not. What lets those through from a cookie session is the
`OCS-APIRequest: true` header, which Nextcloud accepts in place of a CSRF token.
Send it on every request and the distinction never arises. With basic auth it is
optional.

### Authorization

All API endpoints use `#[NoAdminRequired]`: any authenticated user can reach the
public API. **Rule management is part of it** — see the rules endpoints above —
but what a caller may do there depends on who they are: a non-administrator's
`selector` is forced to their own home and `admin_enforced` to `false`, and an
enforced rule is not theirs to change. The repair and queue operations are the
ones that stay out, in the admin settings page and the CLI.

**Reads are the caller's own.** Every endpoint that names a file — the hashes,
the per-file duplicates, recalculation — resolves it through the caller's own
folder and answers 404 when it does not resolve, and the lookup and the
duplicate listing return only files the caller can open. That holds for
administrators too: membership in `admin` grants nothing here. Looking across
accounts is a separate set of routes, named for it and behind Nextcloud's
password confirmation, described under *Cross-account routes* below; a script
that cannot confirm a password uses an app password that an administrator has
granted for it, described there as well.

---

### Cross-account routes

Every ordinary route reads the caller's own files. Four of them have a twin
under `/api/v1/sudo/` that reads across accounts, and the Duplicates page has
one for naming another account:

| Ordinary | Cross-account | Scope of the twin |
|---|---|---|
| `GET /api/v1/file/{fileId}/hashes` | `GET /api/v1/sudo/file/{fileId}/hashes` | any account's file |
| `GET /api/v1/file/{fileId}/duplicates` | `GET /api/v1/sudo/file/{fileId}/duplicates` | any account's file; duplicates from every account |
| `GET /api/v1/lookup` | `GET /api/v1/sudo/lookup` | every account |
| `GET /api/v1/duplicates` | `GET /api/v1/sudo/duplicates?user=` | one named account, or every account when `user` is omitted |
| — | `GET /api/v1/sudo/duplicates?users[]=&groups[]=` | the named accounts and the members of the named groups, as one merged listing |

Two things stand between a caller and a twin, in this order.

**Who may be asked.** A member of `admin`, or anyone the *instance_view*
permission names (admin settings → *Who may look across accounts*), may look
at any account and at every account. A sub-admin — Nextcloud's own delegation,
set on the Users page — may look at the members of the groups they administer,
by naming them; asking for everyone, or for someone outside their groups, is
403. Anyone else is 403 before any password is asked.

**Naming several at once.** `users[]` and `groups[]` name a set instead of the
single `user`. The server expands each group to its members — never the
client, since membership is not the caller's to enumerate — authorises every
resulting account, and answers one merged listing, so a file held by two named
accounts appears as one duplicate group. One account or group the caller may
not read refuses the whole request with 403 rather than quietly narrowing it:
a listing that answers for fewer accounts than were asked for hides the
refusal.

**`GET /duplicates/selectable`** (not under `/api/v1/`; it serves the
Duplicates page) answers what the caller may name — `groups`, `users`, `all`
for a sudoer, and `prefill` saying whether those lists are complete. When
`prefill` is false there are more than the picker holds at once and it must
pass `?search=` as the user types. The threshold is an instance setting
(admin settings → *Advanced*), 21 by default. An account that may name nobody
gets 403.

**Confirmation.** Each twin requires one of two things: a session that
confirmed its password within the last thirty minutes — Nextcloud's own
window, set by its own dialog, which the bundled pages run — or an app
password that has been granted, described under *Sudo tokens* below. Neither
present, the answer is 403 with `"message": "Password confirmation required"`,
the same message core's middleware uses. The check is this app's own rather
than core's `#[PasswordConfirmationRequired]`, because that middleware refuses
every app-password session outright and a granted token could never pass it.

The ordinary routes never cross accounts, whoever calls them.

**Who may use the API at all** is a permission of its own (admin settings →
*Who may use the API*): allow everyone, or name groups and users. It gates
requests that arrive with an app password, or with credentials in an
`Authorization` header — a script, another app — and answers 403 on every
public route for an account it does not name. It does not gate the browser
session: the bundled pages reach these same routes over it and keep working
for everyone, because they are the app and not the API.

### Sudo tokens

A script cannot confirm a password, so the cross-account routes accept a
second credential: an **app password that has been granted**. Grants are
made on the personal settings page (*Sudo tokens*), one switch per app
password, behind the same password confirmation as any other widening; an
administrator sees every grant on the instance under the admin page's *Sudo
tokens* tab and can revoke any of them there. Only an app password can be
granted — a browser session is made and discarded by a login — and only one
allowed to access files.

A grant replaces the confirmation, not the permission: the token's owner
must still be someone who may look across accounts, and an account the *Who
may use the API* permission does not name is not offered grants at all,
because it could not use them.

What a grant is, technically: a per-user setting keyed by the token's id —
never its secret and never its name, which core keeps neither unique nor
stable. A token deleted on the Security page cannot authenticate at all, so
a grant left behind is unreachable; it is shown on the administrator's tab
as such and dropped from the owner's list.

---

## Versioning & Compatibility

### Version Scheme

URL-path versioning: `/api/v1/`, `/api/v2/`, etc.

### What Constitutes a Breaking Change?

| Change | Allowed in same major? | Process |
|--------|----------------------|---------|
| Add new endpoint | Yes | Minor release |
| Add new method to ChecksumApi | Yes | Minor release |
| Add optional field to response | Yes | Minor release |
| Add optional query parameter | Yes | Minor release |
| Add optional method parameter (with default) | Yes | Minor release |
| Change field type | **No** | New major version |
| Remove field | **No** | Deprecate → one major → remove |
| Rename field / endpoint | **No** | New major version |
| Change HTTP method | **No** | New major version |
| Change error response shape | **No** | New major version |

### Deprecation

When a field or endpoint is deprecated, responses include a `Warning` header (RFC 7234):

```
Warning: 299 - "The field 'old_name' is deprecated. Use 'new_name' instead. Will be removed in v2."
```

Deprecated items remain functional for one full major version before removal.

---

## Rate Limiting

The expensive endpoints carry a per-user rate limit, enforced by Nextcloud's own
`RateLimitingMiddleware` via the [`#[UserRateLimit]`](https://docs.nextcloud.com/server/latest/developer_manual/basics/controllers.html)
attribute. Limits are counted **per user, per endpoint** — one user exhausting `lookup`
does not affect another user, nor their own access to the other endpoints.

| Endpoint | Limit |
|----------|-------|
| `GET /api/v1/lookup` | 60 requests / 60 s |
| `GET /api/v1/duplicates` | 60 requests / 60 s |
| `POST /api/v1/file/{fileId}/recalc` | 20 requests / 60 s |

Recalculation is limited more tightly because it reads file content from storage. The
remaining endpoints (`/status`, `/file/{fileId}/hashes`, `/file/{fileId}/duplicates`)
are index lookups only and are not rate limited.

Requests are counted only for authenticated users. There is no anonymous limit, because
every endpoint requires authentication in the first place.

### Exceeding a limit

Once the limit is exceeded within the window, the request is rejected **before** the
controller runs:

```
HTTP/1.1 429 Too Many Requests
Content-Type: application/json

[]
```

The body is empty and there is no `Retry-After` header — this is Nextcloud's standard
429 for non-HTML clients, not an app-specific response. Clients should back off for the
length of the window (60 seconds) and retry.

### Changing the limits

The limits are not app configuration; they are overridden per endpoint through
Nextcloud's `ratelimit_overwrite` system setting in `config/config.php`. The key is
`<app>.<controller-without-suffix>.<method>`, lowercased:

```php
'ratelimit_overwrite' => [
    'file_checksum_search.publicapi.lookup' => [
        'user' => ['limit' => 600, 'period' => 60],
    ],
    'file_checksum_search.publicapi.recalchash' => [
        'user' => ['limit' => 60, 'period' => 60],
    ],
],
```

Both `limit` and `period` must be present and greater than zero, or the override is
ignored and a warning is logged.

> **Note:** earlier revisions of this document described `occ config:app:set` keys
> (`rate_limit_enabled`, `rate_limit_max_requests`, `rate_limit_window_seconds`) and a
> `{"error": ..., "retry_after": ...}` response body. Those were never implemented and
> have been removed. Setting those config keys has no effect.

---

## Error Handling

### HTTP Status Codes

| Code | Meaning | Response Body |
|------|---------|---------------|
| 200 | Success | Normal response |
| 400 | Bad request (validation error) | `{"error": "message"}` |
| 401 | Not authenticated | `{"error": "message"}` |
| 403 | Authenticated, but not allowed this file or this rule | `{"error": "message"}` |
| 404 | Resource not found | `{"error": "message"}` |
| 429 | Rate limited (see [Rate Limiting](#rate-limiting)) | Empty body |
| 500 | Internal server error | `{"error": "message"}` |

### PHP Exceptions

The PHP API throws the following exceptions:

| Exception | When |
|-----------|------|
| `\InvalidArgumentException` | Invalid method parameters (empty hash, bad algorithm) |
| `\RuntimeException` | Internal service failure |
| `\OCP\Files\NotFoundException` | File/path cannot be resolved (`getHashesByFile`, `getHashesByPath`) |

### Two Error Shapes

The read endpoints answer with

```json
{"error": "Human-readable description"}
```

while the rule mutations and `recalc` answer with

```json
{"success": false, "error": "Human-readable description"}
```

`error` is present in both, so a consumer can check that field alone for the
message. It cannot use its **absence** to mean success on the second group —
there, `success` is the field that says so.
