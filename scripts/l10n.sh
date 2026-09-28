#!/usr/bin/env sh
#
# l10n.sh — the app's translations; the door to l10n.py for POSIX shells.
# A PowerShell door, l10n.ps1 beside it, would do the same.
# `scripts/l10n.sh --help` lists the commands.

exec python3 "$(dirname "$0")/l10n.py" "$@"
