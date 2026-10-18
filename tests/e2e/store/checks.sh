# This app's checks after an install from the store, sourced by the vendored
# tests/e2e/store/check-install.sh after its generic ones: the migration
# executed and its indices present, the default rules created, the
# background jobs registered, and the filecache copy queued. APP, VERSION,
# NC, occ() and check() are the caller's.
#
# shellcheck shell=bash
# shellcheck disable=SC2317

NS='OCA\FileChecksumSearch\BackgroundJob'

# Every migration the app ships, recorded as run. Read from the migrations
# table: `occ migrations:status` exists only on a server in debug mode.
migrations_executed() {
	( cd "$NC" && php -r '
		require "lib/base.php";
		$app  = "file_checksum_search";
		$dir  = \OCP\Server::get( \OCP\App\IAppManager::class )->getAppPath( $app ) . "/lib/Migration";
		$want = array_map(
			static fn ( string $file ): string => substr( basename( $file, ".php" ), strlen( "Version" ) ),
			glob( $dir . "/Version*.php" ) ?: [],
		);
		$db = \OCP\Server::get( \OCP\IDBConnection::class );
		$qb = $db->getQueryBuilder();
		$qb->select( "version" )
		   ->from( "migrations" )
		   ->where( $qb->expr()->eq( "app", $qb->createNamedParameter( $app ) ) );
		$result = $qb->executeQuery();
		$have   = [];
		while ( ( $row = $result->fetch() ) !== false ) {
			$have[] = (string) $row["version"];
		}
		$result->closeCursor();
		echo "shipped: ", implode( ", ", $want ), "\n", "recorded: ", implode( ", ", $have ), "\n";
		exit( $want !== [] && array_diff( $want, $have ) === [] ? 0 : 1 );
	' )
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
	local rules
	rules="$(occ fcias:rules:list --output=json 2>&1)"
	echo "$rules"
	# A rule's scope is its selector and its glob, split at the last //.
	printf '%s' "$rules" | python3 -c "
import sys, json
rules = json.load(sys.stdin)
defaults = {r['scope'].rsplit('//', 1)[0] for r in rules if r.get('default') == 'yes'}
sys.exit(0 if {'home:*', '*'} <= defaults else 1)
"
}

job_listed() {
	local jobs
	jobs="$(occ background-job:list --class "${NS}\\$1" --output=json 2>&1)"
	echo "$jobs"
	printf '%s' "$jobs" | python3 -c "import sys, json; sys.exit(0 if json.load(sys.stdin) else 1)"
}

app_checks() {
	check "the app's migrations have run"                migrations_executed
	check "the metadata indices exist"                   indices_present
	check "both default rules exist"                     default_rules
	check "the rule sweep is scheduled"                  job_listed RuleProcessingJob
	check "the queue drain is scheduled"                 job_listed ProcessPendingUpdates
	check "the filecache copy is queued"                 job_listed FilecacheBackfill
}
