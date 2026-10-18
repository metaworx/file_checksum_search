#!/usr/bin/env bash
#
# The install steps of the app, checked on a server it was just installed
# on from the app store: the version the server runs and records, and no
# upgrade pending. Then the app's own checks, from tests/e2e/store/checks.sh
# beside this file where the app has one: it defines each check as a
# function and an app_checks() that runs them through check(), with APP,
# VERSION, NC and occ at hand. Prints one line per check and exits non-zero
# if any failed. The app id is the checkout's manifest's.
#
# Usage: tests/e2e/store/check-install.sh <nextcloud-dir> <version>

# Every check is a function check() calls by name, which shellcheck cannot
# follow.
# shellcheck disable=SC2317

set -uo pipefail

NC="${1:?the Nextcloud directory}"
VERSION="${2:?the version the store published}"
APP="$(sed -n 's|.*<id>\([^<]*\)</id>.*|\1|p' appinfo/info.xml | head -n1 | tr -d '[:space:]')"

occ() { php "$NC/occ" "$@"; }

failed=0

check() {
	local what="$1"
	shift
	local out
	if out="$( "$@" 2>&1 )"; then
		echo "ok      ${what}"
	else
		echo "FAILED  ${what}"
		# What the check read, so a failure on a runner can be read too.
		printf '%s\n' "$out" | sed 's/^/        | /' | head -40
		failed=1
	fi
}

# Each check prints what it read before judging it, so the lines above have
# something to show.

enabled_version() {
	local got
	got="$(occ app:list --output=json | python3 -c "import sys, json; print(json.load(sys.stdin)['enabled'].get('${APP}', ''))")"
	echo "enabled: ${got}"
	[ "$got" = "$VERSION" ]
}

recorded_version() {
	local got
	got="$(occ config:app:get "$APP" installed_version)"
	echo "installed_version: ${got}"
	[ "$got" = "$VERSION" ]
}

no_upgrade_pending() {
	local status
	status="$(occ status --output=json)"
	echo "$status"
	printf '%s' "$status" | python3 -c "import sys, json; sys.exit(0 if json.load(sys.stdin)['needsDbUpgrade'] is False else 1)"
}

check "the server runs ${VERSION}"                      enabled_version
check "the server records ${VERSION} as installed"      recorded_version
check "no upgrade is pending"                           no_upgrade_pending

# The app's own checks, where it has any. The file is the app's and is not
# here to follow.
if [ -f tests/e2e/store/checks.sh ]; then
	# shellcheck source=/dev/null
	. tests/e2e/store/checks.sh
	app_checks
fi

exit "$failed"
