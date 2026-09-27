#!/usr/bin/env bash
#
# The install steps of the app, checked on a server it was just installed
# on from the app store: the version the server runs and records, no
# upgrade pending, the migration executed and its indices present, the
# default rules created, the background jobs registered, and the filecache
# copy queued. Prints one line per check and exits non-zero if any failed.
#
# Usage: tests/e2e/store/check-install.sh <nextcloud-dir> <version>

set -uo pipefail

NC="${1:?the Nextcloud directory}"
VERSION="${2:?the version the store published}"
APP=file_checksum_search
NS='OCA\FileChecksumSearch\BackgroundJob'

occ() { php "$NC/occ" "$@"; }

failed=0

check() {
	local what="$1"
	shift
	if "$@" > /dev/null 2>&1; then
		echo "ok      ${what}"
	else
		echo "FAILED  ${what}"
		failed=1
	fi
}

enabled_version() {
	[ "$(occ app:list --output=json | python3 -c "import sys, json; print(json.load(sys.stdin)['enabled'].get('${APP}', ''))")" = "$VERSION" ]
}

recorded_version() {
	[ "$(occ config:app:get "$APP" installed_version)" = "$VERSION" ]
}

no_upgrade_pending() {
	occ status --output=json | python3 -c "import sys, json; sys.exit(0 if json.load(sys.stdin)['needsDbUpgrade'] is False else 1)"
}

migrations_executed() {
	occ migrations:status "$APP" | grep -Eq 'Pending Migrations:[[:space:]]+None'
}

# Read through Doctrine rather than a database client, so the check holds
# for whichever backend the server runs on.
indices_present() {
	( cd "$NC" && php -r '
		require "lib/base.php";
		$c      = \OCP\Server::get( \OC\DB\Connection::class );
		$prefix = $c->getPrefix();
		$have   = array_map( "strtolower", array_keys(
			$c->createSchemaManager()->listTableIndexes( $prefix . "files_metadata_index" )
		) );
		foreach ( [ "fcias_f_metadata_str_idx", "fcias_f_metadata_int_idx" ] as $name ) {
			if ( ! in_array( strtolower( $prefix . $name ), $have, true ) ) {
				fwrite( STDERR, "missing index {$prefix}{$name}\n" );
				exit( 1 );
			}
		}
	' )
}

default_rules() {
	occ fcias:rules:list --output=json | python3 -c "
import sys, json
rules = json.load(sys.stdin)
defaults = {r['selector'] for r in rules if r.get('default') == 'yes'}
sys.exit(0 if {'home:*', '*'} <= defaults else 1)
"
}

job_listed() {
	occ background-job:list --class "${NS}\\$1" --output=json | python3 -c "import sys, json; sys.exit(0 if json.load(sys.stdin) else 1)"
}

check "the server runs ${VERSION}"                   enabled_version
check "the server records ${VERSION} as installed"   recorded_version
check "no upgrade is pending"                        no_upgrade_pending
check "the app's migrations have run"                migrations_executed
check "the metadata indices exist"                   indices_present
check "both default rules exist"                     default_rules
check "the rule sweep is scheduled"                  job_listed RuleProcessingJob
check "the queue drain is scheduled"                 job_listed ProcessPendingUpdates
check "the filecache copy is queued"                 job_listed FilecacheBackfill

exit "$failed"
