## Test Runner
Always prefix with `wsl --cd "$PWD"`:

```
wsl --cd "$PWD" vendor/bin/phpunit tests/Unit/...
```

## Integration Tests (as www-data)
```
wsl --cd "$PWD" sudo --user www-data vendor/bin/phpunit tests/Integration/...
```

## IDE Fallback
JetBrains MCP: execute_run_configuration with filePath + line

## Commit
1. Write message to .aiassistant/temp/commit-msg.txt
2. `wsl --cd "$PWD" git add <files>`
3. `wsl --cd "$PWD" git commit -F .aiassistant/temp/commit-msg.txt --trailer "Co-authored-by: Agent <agent@example.com>"`

## Native Agent execute_command
Always pass `cwd: "C:\\"` to avoid CMD.EXE UNC path errors with `\\wsl.localhost\...` paths.
