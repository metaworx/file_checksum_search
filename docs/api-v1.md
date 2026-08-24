# FCIAS Public API v1

Stable public API for the File Checksum Index & Search Nextcloud app. Three consumer surfaces share the same underlying contract:

| Surface | Audience | Access Method |
|---------|----------|---------------|
| **HTTP REST** | Scripts, external tools, other services | HTTP requests to `/apps/file_checksum_search/api/v1/` |
| **PHP DI** | Other Nextcloud apps (in-process) | Dependency injection via `\OCP\Server::get()` |
| **PHP Bootstrap** | External PHP apps | `require_once` NC base, then container lookup |

All three surfaces use the same [`ChecksumApi`](lib/Public/ChecksumApi.php) class as their single public contract.

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

Located at [`lib/Public/ChecksumApi.php`](lib/Public/ChecksumApi.php). This is the **single public contract** — all HTTP endpoints delegate to the same methods, guaranteeing behavioral equivalence.

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

#### `findByHash(string $hash, ?string $algo = null, int $limit = 100): array`

Search for files matching a given hash value.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$hash` | `string` | Yes | Hex-encoded hash (8/32/40/64/128 chars depending on algorithm) |
| `$algo` | `?string` | No | Algorithm filter (`sha1`, `md5`, `sha256`, `sha512`, `sha3-256`, `sha3-512`, `crc32`, `adler32`) |
| `$limit` | `int` | No | Max results (1–500, default 100) |

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

#### `getHashesByFileId(int $fileId): array`

Get all checksums for a file by its filecache ID.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$fileId` | `int` | Yes | The filecache `fileid` |

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

Find all duplicate hash groups across the entire system.

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

#### `recalcHash(int $fileId, ?string $algo = null): array`

Trigger hash recalculation for a file. **This is the only mutating operation** in the public API.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$fileId` | `int` | Yes | The filecache `fileid` |
| `$algo` | `?string` | No | Algorithm (default: `sha1`) |

**Returns (success):**
```php
['success' => true, 'algo' => 'sha1', 'hash' => 'da39a3...', 'fileid' => 12345]
```

**Returns (failure):**
```php
['success' => false, 'error' => 'File not found: 99999']
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

---

## HTTP REST API

All endpoints are under `/apps/file_checksum_search/api/v1/`. Responses are plain JSON — no OCS wrapper.

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
| 11 | `/api/v1/rules/order` | PUT | — | Reorder one priority band |

> **Note:** `getHashesByFile()` and `getHashesByPath()` are PHP-only convenience methods with no HTTP equivalent. HTTP consumers should use `getHashesByFileId()` after obtaining a `fileId` from NC's WebDAV PROPFIND or other APIs.

### Endpoint Details

#### 1. Lookup by Hash

```
GET /apps/file_checksum_search/api/v1/lookup?hash=<hex>&algo=<algo>&limit=<n>
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
GET /apps/file_checksum_search/api/v1/file/{fileId}/hashes
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
  ]
}
```

---

#### 3. Find Same-Hash Files

```
GET /apps/file_checksum_search/api/v1/file/{fileId}/duplicates
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
POST /apps/file_checksum_search/api/v1/file/{fileId}/recalc
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

`exclude` means the file must not be read at all, so a manual recalculation is
refused along with every automatic route. The status is 403 rather than 400
because the request is well-formed and retrying it will not help — a client
should surface the reason instead of treating it as a transient failure.
`ruleId` names the rule that decided, so an administrator can find it in the
rules table. An `ignore` rule does **not** produce this: hashing on request is
exactly what `ignore` still allows.

---

#### 5. Find All Duplicates

```
GET /apps/file_checksum_search/api/v1/duplicates?algo=<algo>&min_count=<n>&limit=<n>&offset=<n>
```

| Parameter | Type | Required | Default | Max |
|-----------|------|----------|---------|-----|
| `algo` | string | No | — | — |
| `min_count` | int | No | 2 | — |
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
GET /apps/file_checksum_search/api/v1/status
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

---

## Rules

Hash-generation rules, as configured on the admin and personal settings pages.
These endpoints have no `ChecksumApi` equivalent — they are HTTP-only.

### Priority bands

Rules are evaluated top to bottom and **the first match decides the file**. A rule's position is
not free-form: it is derived from what the rule *is*.

| Band | Contents |
|------|----------|
| 1 | user-scoped, `admin_enforced` |
| 2 | group-scoped, `admin_enforced` |
| 3 | global, `admin_enforced` |
| 4 | user-scoped, not enforced |
| 5 | group-scoped, not enforced |
| 6 | global, not enforced |
| 7 | the single pinned `**` catch-all default |

Enforced beats unenforced; within each half, specific beats general; the catch-all is last. So a
user's rule can override the non-enforced defaults below it, but can never outrun an enforced
one. A rule changes band by changing its `userScope` or `admin_enforced` — never by reordering,
which only permutes rules *inside* one band.

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
| `userScope` | string | `all` \| `group:<gid>` \| `<uid>` |
| `algos` | string[] | include rules only |
| `mode` | string | include rules only: `auto` \| `missing` \| `force` \| `lazy` \| `off` |
| `admin_enforced` | bool | administrator-only |
| `pinned` | bool | the catch-all default; administrator-only, at most one |
| `band`, `position` | int | computed, read-only |
| `canEdit` | bool | computed for the calling user |

An `ignore` or `exclude` rule computes nothing, so it stores no `algos` and no `mode`; sending
them is not an error, they are simply not kept.

### `GET /api/v1/rules`

| Parameter | Values | Default | Notes |
|-----------|--------|---------|-------|
| `scope` | `own`, `all` | `own` | `all` requires administrator rights (403 otherwise) |

`scope` selects a **view**, not a permission. `own` lists the rules that concern the caller's own
files — covered by scope *and* able to reach a path they can see — and marks only their own as
editable. `all` is the administrator's whole-instance view.

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
      "userScope": "all", "algos": ["sha256"], "mode": "auto",
      "admin_enforced": false, "pinned": false,
      "band": 6, "position": 1, "canEdit": true }
  ],
  "canCreate": true,
  "supportedAlgos": ["sha1", "md5", "sha256"],
  "modes": ["auto", "missing", "force", "lazy", "off"],
  "types": ["include", "ignore", "exclude"],
  "availableUsers": ["alice"],
  "availableGroups": ["staff"]
}
```

`availableUsers` and `availableGroups` are present only for `scope=all` — they exist to populate
scope pickers, and no other view can assign those scopes.

### `POST /api/v1/rules` — create

Body is the rule shape above. From a non-administrator, `userScope` is forced to the caller and
`admin_enforced` to `false`, whatever the payload says; `pinned` is ignored. A non-administrator
must also have write access to the rule's path (403 otherwise).

### `PUT /api/v1/rules/{id}` — update

Same body. **Enabling or disabling a rule is an update of `enabled`** — there is no separate
toggle endpoint. Omitted fields keep their stored values, so `{"enabled": false}` is a complete
and safe request.

Changing `userScope` or `admin_enforced` moves the rule to the **end of its new band**, since
position has no meaning across bands.

### `DELETE /api/v1/rules/{id}`

400 for the pinned default — it cannot be deleted, only disabled.

### `PUT /api/v1/rules/order` — reorder one band

```json
{ "band": 4, "ownerId": "alice", "orderedIds": ["0f1e…", "2a3b…"] }
```

`orderedIds` must be **exactly a permutation** of that band's rule IDs — never a partial order,
which would silently drop rules from evaluation. Band 7 is not orderable. Band 4 holds every
user's own rules, so it additionally names whose segment is being reordered; different users'
rules never compete, and one user's reorder cannot move another's.

A non-administrator may reorder only band 4, always their own segment — `ownerId` is ignored and
forced to the caller.

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
  "https://nc.example.com/apps/file_checksum_search/api/v1/status"
```

### 3. Bearer Token

```
Authorization: Bearer <app_password_or_oauth_token>
```

```bash
curl -H "Authorization: Bearer your-app-password" \
  "https://nc.example.com/apps/file_checksum_search/api/v1/status"
```

### CSRF

All API endpoints use `#[NoCSRFRequired]`. CSRF tokens are not needed for API access.

### Authorization

All API endpoints use `#[NoAdminRequired]`. Any authenticated user can access the public API. Admin-only operations (rebuild, rule management, etc.) are **not** exposed through the public API — they remain in the admin settings page and CLI.

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

### All Error Responses Follow the Same Shape

```json
{"error": "Human-readable description"}
```

Consumers can reliably check `response.error` for error conditions.
