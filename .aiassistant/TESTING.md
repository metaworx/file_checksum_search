# Testing Conventions (v1.5.0)

Project-specific testing conventions for Kunstarchiv.
Generic agent flow-control rules are in `AGENTS.md`; contributor context is in `CONTRIBUTING.md`.

## Contents

1. Gate Command
2. Expectations
3. Minimum Submission Checklist
4. When to Write Tests
5. Coverage Guidance
6. Testability Patterns
7. Diagnosis Strategy
8. Debug Output (`$DEBUG` flag)
9. Token Index Discovery
10. `InaccessibleMethodArg` Pattern
11. Idempotence Testing Pattern
12. Fixture File Format
13. Reading Fixture Test Diffs
14. Temporary Test Artifacts
15. Document Governance
16. Version History

## 1. Gate Command

Use `\\.aiassistant\\tools\\phpunit` as the preferred testing method for agents.
It is compatible with `vendor\\bin\\phpunit` while adding wrapper options such as `--use-diff-stats` and `--env`.

The gate test command is:

```powershell
.\\.aiassistant\\tools\\phpunit
```

Scoped run (single file or directory):

```powershell
.\\.aiassistant\\tools\\phpunit tests\\Unit\\augias\\BildTest.php
.\\.aiassistant\\tools\\phpunit tests\\Unit\\CS\\Fixer\\
```

Compatibility fallback (raw PHPUnit) remains supported:

```powershell
.\\vendor\\bin\\phpunit tests\\Unit\\augias\\BildTest.php
```

### WSL / Nextcloud Integration Tests

Nextcloud integration tests (e.g. `FileManagerIntegrationTest`) must run as the NC web server user
inside WSL:

```bash
wsl sudo --user www-data vendor/bin/phpunit tests/Integration/nextcloud/FileManagerIntegrationTest.php
```

Prerequisites:
- `NC_SERVER_CONFIG` and `NC_USER` configured in `.env.local`
- NC instance installed and running
- PHP available inside WSL

## 2. Expectations

- All relevant tests SHOULD pass with zero errors/failures before submission.
- Warnings should be resolved where possible.
- Deprecations from third-party vendor code may occur and should be noted but are not blocking.

## 3. Minimum Submission Checklist

- [ ] Ran tests for changed scope (at minimum).
- [ ] Ran dependent/adjacent tests when change risk is moderate or higher.
- [ ] Confirmed new/updated tests are green.
- [ ] Documented known non-blockers (e.g., vendor deprecations) in the final summary.

## 4. When to Write Tests

| Change type   | Requirement                                                                 |
|---------------|-----------------------------------------------------------------------------|
| Bug fix       | **Always** write a reproduction test; verify it fails before the fix.       |
| New feature   | Add tests proportional to complexity; skip only for trivial getters/labels. |
| Refactoring   | Rely on existing tests; add new ones only if coverage is clearly missing.   |
| Docs-only     | No tests required.                                                          |

## 5. Coverage Guidance

1. **Unit tests** – Utility/base classes should have focused unit tests.
2. **Entity tests** – For DB entities (`Bestand`, `Objekt`, `Bild`), prefer connection/query abstraction points for mocks.
3. **Integration tests** – For major workflow changes, add/extend tests around entry points such as `foto.php` or `index.php` where feasible.

## 6. Testability Patterns

- Avoid tight coupling to non-mockable global calls when it blocks unit testing.
- Prefer dependency injection or existing setter-based seams (e.g. DB connection injection via `SimpleDbObj::setConnection()`).
- For output buffering/log flushing paths, ensure CLI-safe behavior under PHPUnit.

## 7. Diagnosis Strategy

- Prefer concise, filtered test output for first-pass diagnosis; use full/raw output only when detailed traces are required.
- For preliminary fixture overviews with reduced noise, run the wrapper with `--use-diff-stats`:

```powershell
.\\.aiassistant\\tools\\phpunit --use-diff-stats tests\\Unit\\CS\\Fixer\\ControlStructureBraceFixer\\ControlStructureBraceFixerNewlineConfigTest.php
```

- Run all relevant tests for changed code **and** dependent modules before submission.
- For stochastic/ML paths: set random seeds for reproducibility; validate all required metrics on a small sample before full runs.
- After a failing run, apply only obvious, low-risk fixes first.
- If failures remain complex after limited attempts, pause, provide a short analysis, and ask the user for guidance before deeper fix/refactor attempts.
- For richer failure context during diagnosis, use `PHPUNIT_DEBUG` flow in `§8 Debug Output ($DEBUG flag)`.

## 8. Debug Output (`$DEBUG` flag)

Test classes using `GenerateTokensFromCodeTrait` expose a `public static bool $DEBUG` property (default `false`).
This includes classes extending `AbstractFixerTestCase` or `FixerMethodTestCase`, which use the trait.
When `true`, assertion failures include a full token dump and the input code in the error message.

The trait's `setUpBeforeClass()` reads the `PHPUNIT_DEBUG` environment variable **only when it is set**,
using `filter_var(..., FILTER_VALIDATE_BOOLEAN)` so values like `1`, `true`, `yes` all work:

```powershell
# Enable debug output for a single run (PowerShell — inline prefix, no persistent change)
$env:PHPUNIT_DEBUG=1; .\\vendor\\bin\\phpunit tests\\Unit\\CS\\Fixer\\ControlStructureBraceFixer\\ControlStructureBraceFixerConfigTest.php

# Or set, run, then clean up explicitly
$env:PHPUNIT_DEBUG=1
.\\vendor\\bin\\phpunit tests\\Unit\\CS\\Fixer\\ControlStructureBraceFixer\\ControlStructureBraceFixerConfigTest.php
Remove-Item Env:PHPUNIT_DEBUG
```

When using the wrapper `\\.aiassistant\\tools\\phpunit`, `--env KEY=VALUE` is supported via
`mwx\\Tests\\ConditionalDiffFilter::parsePhpUnitArgs`, for example:

```powershell
.\\.aiassistant\\tools\\phpunit --env PHPUNIT_DEBUG=1 tests\\Unit\\CS\\Fixer\\ControlStructureBraceFixer\\ControlStructureBraceFixerConfigTest.php
```

> **Note:** Raw PHPUnit 10 (`.\\vendor\\bin\\phpunit`) does not support a `--env` CLI flag. Use either the wrapper `--env` option or the PowerShell `$env:` prefix shown above.

Individual test classes may still hardcode `public static bool $DEBUG = true;` to override the default
during active development — flip it back to `false` (or remove the override) before committing.



## 9. Token Index Discovery

Never hardcode token indices without first verifying the token structure. Use a temporary
PHPUnit test (standalone PHP scripts cannot resolve the ECS-bundled `Tokens` class) to dump
the token array — place it in `/.aiassistant/temp/` and delete after use.

Example output for `"<?php if ($a) {}\nreturn;"` (verified via `NormalizeNewlineAfterTest`):

```
0:  "<?php "   T_OPEN_TAG  (trailing space is part of the token)
1:  "if"       T_IF
2:  " "        T_WHITESPACE
3:  "("
4:  "$a"       T_VARIABLE
5:  ")"
6:  " "        T_WHITESPACE
7:  "{"
8:  "}"
9:  "\n"       T_WHITESPACE
10: "return"   T_RETURN
11: ";"
```

Key facts:
- `T_OPEN_TAG` always includes its trailing whitespace/newline — index 0 is never just `<?php`.
- Whitespace tokens (spaces, newlines) occupy their own indices between meaningful tokens.
- Always verify indices in the actual token stream before writing provider cases.

## 10. `InaccessibleMethodArg` Pattern

For private/protected methods with mixed value args and by-ref out-params, use the fluent builder:

```php
$change = $this->invokeInaccessibleMethod(
    'normalizeNewlineAfter',
    InaccessibleMethodArg::create( $tokens, $targetIndex, $indentation, $throw )
                         ->withRef( $resolvedIndex )       // out-param (by ref)
                         ->withRef( $adjacentMeaningful )  // out-param (by ref)
                         ->with( $min )                    // value arg
                         ->with( $max )                    // value arg
                         ->with( $commentMode )            // value arg
);
```

- `create(...)` — positional args passed directly to the method
- `->withRef($var)` — appends a by-reference out-parameter (variable must be declared before)
- `->with($val)` — appends a regular value argument

## 11. Idempotence Testing Pattern

Re-run the method on its own output and assert the result is unchanged. The existing `$args`
object can be reused for the idempotence pass: replace the tokens argument at position 0 via
`->with( $tokens, 0 )`, then reset any `withRef` variables to `null` before the call (the refs
are still live inside `$args`):

```php
// First pass
$resolvedIndex      = null;
$adjacentMeaningful = null;
$args = InaccessibleMethodArg::create( $tokens, $targetIndex, $indentation, $throw )
                              ->withRef( $resolvedIndex )
                              ->withRef( $adjacentMeaningful )
                              ->with( $min )->with( $max )->with( $commentMode );

$change = $this->invokeInaccessibleMethod( 'myMethod', $args );
$actual = $tokens->generateCode();
$this->assertSame( $expected, $actual, 'first pass: code mismatch' );

// Idempotence pass — re-tokenize from actual output, replace tokens at position 0
$tokens             = $this->generateTokensFromCode( $actual );
$resolvedIndex      = null;   // reset — $args still holds the ref
$adjacentMeaningful = null;

$change = $this->invokeInaccessibleMethod( 'myMethod', $args->with( $tokens, 0 ) );
$actual = $tokens->generateCode();
$this->assertSame( $expected, $actual, 'idempotence: second pass changed output' );
$this->assertSame( BaseWhitespacesAwareFixer::CHANGE_NONE, $change, 'second pass: change type mismatch' );
```

Note: `->with( $value, $position )` replaces the argument at the given positional index (0-based)
rather than appending a new one.

## 12. Fixture File Format

Fixture files use `-----` to split input from expected output:

```
<?php
// input code here
-----
<?php
// expected output here
```

- If no `-----` is present, the file is treated as both input and expected (no-change assertion).
- The input section is never modified when updating fixtures — only the expected section.
- Semantic errors reported by the linter on `.php.inc` fixture files are expected (undefined
  variables, duplicate function names across sections, `-----` parsed as PHP) — ignore them.

## 13. Reading Fixture Test Diffs

Quick scan — show only changed lines:

```powershell
.\\.aiassistant\\tools\\phpunit .\\tests\\Unit\\CS\\Fixer\\ControlStructureBraceFixer\\ControlStructureBraceFixerNewlineConfigTest.php `
    --no-coverage --filter my_fixture.php 2>&1 | Select-String "@@ @@|^\+|^-"
```

Full diff with context, show diff section:

```powershell
.\\.aiassistant\\tools\\phpunit .\\tests\\Unit\\CS\\Fixer\\ControlStructureBraceFixer\\ControlStructureBraceFixerNewlineConfigTest.php `
    --no-coverage --filter my_fixture.php 2>&1 | Select-Object -First 80
```

The `=== RESULT ===` section in the output contains the unified diff between expected and actual.
`--- Expected` / `+++ Actual` — lines prefixed `-` are in expected but not actual; `+` are in
actual but not expected.

## 14. Temporary Test Artifacts

- Place temporary scripts/test output in `/.aiassistant/temp/`; do not commit them unless explicitly requested.

## 15. Document Governance

- This document follows the shared governance rules in `.aiassistant/CHANGELOG.md`.
- Update the title version on each change and append a new row in `Version History`.

## 16. Version History

| Version | Date       | Changed sections                              | Change type | Agent impact                                                      |
|---------|------------|-----------------------------------------------|-------------|-------------------------------------------------------------------|
| v1.5.0  | 2026-04-23 | Title, 1, 13, 16                              | minor       | Makes `\\.aiassistant\\tools\\phpunit` the preferred agent test entrypoint while preserving explicit `vendor\\bin\\phpunit` compatibility fallback. |
| v1.4.0  | 2026-04-22 | Title, 7, 8, 16                               | minor       | Documents wrapper support for `--use-diff-stats` and `--env`, and clarifies `$DEBUG` applicability for trait/base-class fixer tests. |
| v1.3.0  | 2026-04-22 | Title, 7, 16                                  | minor       | Restores explicit escalation guidance for complex test failures and links diagnosis to `§8` debug workflow. |
| v1.2.0  | 2026-04-22 | Contents, 1-16                               | minor       | Adds section numbering (except `Contents`) and aligned numbered `Contents` entries. |
| v1.1.0  | 2026-04-22 | Title, Contents, Document Governance, History | minor       | Adds explicit versioning, ToC, and local history tracking format. |
| v1.0.0  | 2026-04-22 | Initial document                              | minor       | Baseline testing guidance for agent workflows in this repository. |
