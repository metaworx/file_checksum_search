## Test Runner
Always prefix with `wsl --cd ~/projects/nc_file_checksum_search`:

```
wsl --cd ~/projects/nc_file_checksum_search vendor/bin/phpunit tests/Unit/...
```

## Integration Tests (as www-data)
```
wsl --cd ~/projects/nc_file_checksum_search sudo --user www-data vendor/bin/phpunit tests/Integration/...
```

## IDE Fallback
JetBrains MCP: execute_run_configuration with filePath + line

## Commit
1. Write message to .aiassistant/tools/commit-msg.txt
2. `wsl --cd ~/projects/nc_file_checksum_search /home/mdr/bin/git add <files>`
3. `wsl --cd ~/projects/nc_file_checksum_search /home/mdr/bin/git commit -F .aiassistant/tools/commit-msg.txt --trailer "Co-authored-by: Agent <agent@example.com>"`

## Native Agent execute_command
Always pass `cwd: "C:\\"` to avoid CMD.EXE UNC path errors with `\\wsl.localhost\...` paths.
