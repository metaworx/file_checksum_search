# AP SharedDoubles v1.0: test doubles from the package

## TL;DR

The unit tests' own copies of Nextcloud doubles give way to `mwx/nextcloud-testing`,
the package LFP already uses, and the test bootstrap becomes its one call.
Only `tests/`, `composer.json` and the tools' configuration change.

- `require-dev`: `mwx/nextcloud-testing` ^0.1, then ^0.2 (D4)
- `tests/bootstrap.php` → the vendor autoloader and `Bootstrap::nextcloud()`;
  a checkout without a server falls back to `nextcloud/ocp`
- `EnglishL10n`, `tests/stubs/` → the package's; `psalm.xml`, `rector.php` follow
- `AppDoubles`: the package's traits in one,
  for `FciasUnitTestCase` and the tests that do not extend it (D2)
- `IAppConfig` in 26 files, `IUserConfig` in 3 → memory doubles;
  call counts stay, as `expects()` on top (D1, D5)
- system values in 4 files → `MemorySystemConfig` (D3)
- no CHANGELOG bullet: nothing shipped changes

### Blocks

1. The package, the bootstrap, `AppDoubles`, `EnglishL10n`, the Emitter stub
2. The `IAppConfig` and `IUserConfig` doubles, on 0.2.0
3. The system-config doubles

## Discussion

- **Origin.**
  mwx-lib's AP NextcloudTesting made the package from LFP's doubles
  and left this app's adoption to this app's session.
- **Decided on 2026-11-03:**
  - D1-A: all 26 `IAppConfig` files move.
    The 14 that count calls keep their `expects()` on top of the double;
    a value one returned through a stub goes into `$this->appValues` instead.
  - D2-A: `tests/Unit/AppDoubles.php`, as in LFP.
  - D3-A: the system-config doubles move where they only store and answer.
  - D4-A: `getAllValues()` goes into the library's `MemoryAppConfig`,
    released as 0.2.0; Block 2 waits for it.
  - D5-B: a `MemoryUserConfig` in the library now,
    since FileRenameHistory will use it too; it ships in 0.2.0.
  - Rejected: keeping the 14 counting files on plain mocks (D1-B);
    a local `getAllValues()` stub (D4-B); a local `IUserConfig` stub (D5-A).
- **Corrected on the way:**
  the TL;DR gate said an `expects()` stacked on the package's stubs sees no call.
  It does: PHPUnit hands a call to every matching matcher,
  and takes only the return value from the first.
  `FciasUnitTestCase` says the same of its `like` stub; Block 2 corrects it.
- **Narrowed on the way:**
  the file cache these tests mock is `OCP\Files\Cache\ICache`,
  not the memory cache `MemoryCache` stands in for,
  and `MimeTypeService` asks the detector `detectString()`,
  which `KnownMimeTypes` does not answer.
  Both stay as they are; D3 is the `IConfig` doubles alone.
- **Asked of the library** (its session, 2026-11-03):
  `getAllValues()`, `MemoryUserConfig`,
  and a README note with a test that a stacked expectation counts but does not answer.

## Analysis

**The package**, 0.1.0:
`MemoryAppConfig`, `MemorySystemConfig`, `MemoryCache`, `EnglishL10n`,
`KnownMimeTypes`, traits over a `TestCase`'s `createMock()`;
`Bootstrap::nextcloud()`; `stubs/OC/Hooks/Emitter.php`.
0.2.0, asked for: `getAllValues()` and `MemoryUserConfig`.

**The bootstrap.**
The package's first two places are this app's:
the server the app is deployed in, then a `nextcloud-v*` tree
or `FCIAS_NC_ROOT`.
The third, `nextcloud/ocp` with the Emitter stub, is new;
`nextcloud/ocp` is already in `require-dev`.
`vendor/autoload.php` now loads first in every place, as in LFP,
since the package's classes come from it.
Composer prepends each loader it registers,
so the server's, registered after it, answers first.

**The copies.**
- `EnglishL10n`: the package's has the same two methods and the same output;
  12 test files use it, 5 of them through `FciasUnitTestCase`.
- The Emitter stub: the same signatures;
  `psalm.xml` names it, `rector.php` skips its directory.

**The `IAppConfig` doubles:** 26 files, 14 with about 83 `expects()`;
`getAllValues()` stubbed 5 times in `ShowConfigTest`.

**The `IUserConfig` doubles:**
- `SudoTokensTest`: a store written by hand, and `getValuesByUsers()`;
- `PublicApiControllerTest`: `getValueString()`, four `expects()`;
- `ChecksumApiTest`: a bare mock it never asks.

**Stacking.**
PHPUnit 10.5's `InvocationHandler::invoke()` calls every matching matcher,
and returns the first registered one's value.
An `expects()` added after the double is built is counted,
its `with()` checked, its callback run; its return value is dropped.

**The system values:**
`ExportServiceTest`, `ImportServiceTest`, `FilecacheServiceTest`,
`TableNameServiceTest`, one value each;
`TableNameServiceTest`'s read-once test keeps its `expects( once() )` on top.

**Not changed:** `FciasUnitTestCase`'s query-builder mocks,
the file-cache mocks, the MIME detector's.

## Implementation Plan

1. **The package, the bootstrap, `AppDoubles`, `EnglishL10n`, the Emitter stub.**
   - `composer require --dev mwx/nextcloud-testing:^0.1`.
   - `tests/bootstrap.php`: the vendor autoloader,
     then `Bootstrap::nextcloud( dirname( __DIR__ ), 'FCIAS_NC_ROOT' )`.
   - `tests/Unit/AppDoubles.php`, using the package's `EnglishL10n`;
     `FciasUnitTestCase` uses it.
     Of the 12 tests, those extending `FciasUnitTestCase` drop their `use`,
     the others `use AppDoubles`.
   - `tests/Unit/EnglishL10n.php` and `tests/stubs/` deleted;
     `psalm.xml`'s stub to the package's, `rector.php`'s skip of `tests/stubs` gone.
   - **Verification:** both suites on 33 and 34; the unit suite on the trees;
     ECS, Psalm; `GUIDELINES/shared/tools/check`.
2. **The `IAppConfig` and `IUserConfig` doubles, on 0.2.0.**
   Waits for the library's 0.2.0.
   - `composer require --dev mwx/nextcloud-testing:^0.2`.
   - `AppDoubles` adds `MemoryAppConfig` and `MemoryUserConfig`.
   - The 26 files: `memoryAppConfig()`, values in `$this->appValues`;
     the counting tests keep `expects()` on top;
     `ShowConfigTest` reads `getAllValues()` from the store.
   - `SudoTokensTest`, `PublicApiControllerTest`, `ChecksumApiTest`:
     `memoryUserConfig()`.
   - `FciasUnitTestCase`: the comment on `$capturedLikes` says what PHPUnit does.
   - **Verification:** the unit suite on 33 and 34; ECS;
     `GUIDELINES/shared/tools/check`.
3. **The system-config doubles.**
   - `AppDoubles` adds `MemorySystemConfig`.
   - The 4 files: `systemConfig()`, values in `$this->systemValues`;
     the read-once count stays on top.
   - **Verification:** the unit suite on 33 and 34; ECS;
     `GUIDELINES/shared/tools/check`.

## Proposed commit messages

Written per block: `[TASK]`, citing `AP SharedDoubles v<X.Y>, Block <n>`,
each saying in `Key changes:` that it carries no CHANGELOG bullet,
since `tests/` and the root's files are outside the shipped paths.

## Change History

- v1.0 (2026-11-03): first revision, from the TL;DR gate and its `EVAL`,
  with D1-A to D4-A and D5-B.
