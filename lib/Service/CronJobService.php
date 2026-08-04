<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\CronGenerateHashes;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Centralized management of CronGenerateHashes job definitions.
 *
 * oc_jobs.id is the natural identifier. Arguments have no embedded UUID.
 * IAppConfig is only used as a backup for the disable/enable cycle.
 *
 * Note: IJobList has no update() method — add() with a different argument
 * inserts a new row rather than updating the existing one. For edits and
 * toggles we update the argument column directly via IDBConnection to avoid
 * remove+add dirty-reads.
 */
class CronJobService
{

	public function __construct(
		private readonly IJobList        $jobList,
		private readonly IAppConfig      $appConfig,
		private readonly IDBConnection   $db,
		private readonly LoggerInterface $logger,
	) {
	}


	/**
	 * @return array<int, array{id: string, enabled: bool, userScope: string, path: string, algo: string, batchSize:
	 *                    int, interval: int}>
	 */
	public function listDefinitions(): array
	{

		$definitions = [];

		foreach ( $this->jobList->getJobsIterator( CronGenerateHashes::class, null, 0 ) as $job )
		{
			$arg = $job->getArgument();

			if ( is_array( $arg ) && isset( $arg['userScope'] ) )
			{
				$arg['id']           = $job->getId();
				$arg['lastRun']      = $job->getLastRun();
				$arg['lastChecked']  = 0;
				$arg['execDuration'] = 0;

				$details = $this->jobList->getDetailsById( $job->getId() );

				if ( is_array( $details ) )
				{
					$arg['lastChecked']  = (int) ( $details['last_checked'] ?? 0 );
					$arg['execDuration'] = (int) ( $details['execution_duration'] ?? 0 );
				}

				$definitions[] = $arg;
			}
		}

		return $definitions;
	}


	/**
	 * Add a new job. For editing an existing job, use updateDefinition().
	 */
	public function saveDefinition( array $definition ): void
	{

		if ( ! empty( $definition['enabled'] ) )
		{
			$this->jobList->add( CronGenerateHashes::class, $definition );
		}
	}


	/**
	 * Update an existing job's argument in-place via direct DB write.
	 *
	 * Avoids remove+add which triggers dirty-read detection in NC v33.
	 */
	public function updateDefinition(
		string $id,
		array  $definition,
	): void {

		$argumentJson = json_encode( $definition, JSON_UNESCAPED_SLASHES );

		$query = $this->db->getQueryBuilder();
		$query->update( 'jobs' )
		      ->set( 'argument', $query->createNamedParameter( $argumentJson ) )
		      ->set( 'argument_hash', $query->createNamedParameter( hash( 'sha256', $argumentJson ) ) )
		      ->where(
			      $query->expr()
			            ->eq( 'id', $query->createNamedParameter( $id ) ),
		      )
		;
		$query->executeStatement();
	}


	public function deleteDefinition( string $id ): void
	{

		$this->jobList->removeById( $id );
	}


	/**
	 * Toggle enabled state via direct argument column update.
	 */
	public function toggleDefinition(
		string $id,
		bool   $enabled,
	): void {

		$job = $this->jobList->getById( $id );

		if ( $job === null )
		{
			return;
		}

		$arg = $job->getArgument();

		if ( ! is_array( $arg ) )
		{
			return;
		}

		$arg['enabled'] = $enabled;
		$arg['_v']      = CronGenerateHashes::ARG_VERSION;

		$this->updateDefinition( $id, $arg );

		$this->logger->info(
			'FCIAS CronJobService: job {action}.',
			[
				'app'        => Application::APP_ID,
				'action'     => $enabled
					? 'enabled'
					: 'disabled',
				'definition' => $arg,
			],
		);
	}


	/**
	 * Persist current definitions to IAppConfig as a backup.
	 *
	 * Called by the AppDisableEvent listener so definitions survive
	 * NC dropping jobs when the app is disabled.
	 */
	public function backup(): void
	{

		$definitions = $this->listDefinitions();

		// Strip oc_jobs.id from backup — new IDs are assigned on restore
		$definitions = array_map(
			static function (
				array $def,
			): array {

				unset( $def['id'] );

				return $def;
			},
			$definitions,
		);

		$this->appConfig->setValueString(
			Application::APP_ID,
			'cron_job_definitions',
			json_encode( $definitions, JSON_UNESCAPED_SLASHES ),
		);

		$this->logger->info(
			'FCIAS CronJobService: backed up {count} definitions.',
			[
				'app'   => Application::APP_ID,
				'count' => count( $definitions ),
			],
		);
	}


	/**
	 * Restore enabled definitions from the IAppConfig backup.
	 *
	 * Clears the backup after successful restore to prevent
	 * re-registration on every boot cycle.
	 *
	 * @return int Number of definitions restored
	 */
	public function restore(): int
	{

		$definitions = json_decode(
			$this->appConfig->getValueString( Application::APP_ID, 'cron_job_definitions', '[]' ),
			true,
		);

		if ( ! is_array( $definitions ) || empty( $definitions ) )
		{
			return 0;
		}

		$count = 0;

		foreach ( $definitions as $def )
		{
			if ( ! empty( $def['enabled'] ) )
			{
				$this->jobList->add( CronGenerateHashes::class, $def );
				$count ++;

				$this->logger->info(
					'FCIAS CronJobService: restored definition on boot.',
					[
						'app'       => Application::APP_ID,
						'userScope' => $def['userScope'] ?? 'all',
						'algo'      => $def['algo'] ?? '?',
					],
				);
			}
		}

		$this->appConfig->setValueString( Application::APP_ID, 'cron_job_definitions', '[]' );

		return $count;
	}

}
