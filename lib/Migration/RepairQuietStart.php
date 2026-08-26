<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Post-migration repair for the quiet-start model.
 *
 * Three idempotent cleanups, each safe to re-run (`occ maintenance:repair`
 * included — that is the documented lever for working copies that track the
 * repository between releases and therefore never enter the upgrade path):
 *
 * 1. Ensure a catch-all default rule exists, created **disabled**. No app
 *    should silently start doing work the administrator has not configured;
 *    a disabled band-7 rule makes the decision visible and enabling it one
 *    click, instead of an empty table with nothing to look at. An existing
 *    global rule — enabled or not — is left exactly as it is: upgrades never
 *    turn off what an administrator turned on.
 * 2. Purge leftover 'pending:new' index rows. Under the old model one such
 *    row existed per file to mean "seeded, never considered"; the new
 *    model's word for that is *no row*.
 * 3. Deregister the deleted SeedPendingUpdates job by its FQCN string —
 *    Nextcloud does not remove scheduled instances of a class that no
 *    longer exists, and the class name has to be a literal here because
 *    the class is gone.
 *
 * Warns and logs rather than throwing: a throwing repair step aborts the
 * whole Nextcloud upgrade, and everything here is recoverable by hand.
 */
class RepairQuietStart
	implements
	IRepairStep
{

	private const LEGACY_SEED_JOB = 'OCA\\FileChecksumSearch\\BackgroundJob\\SeedPendingUpdates';

	private const LEGACY_PENDING_NEW = 'pending:new';


	public function __construct(
		private readonly RuleService     $ruleService,
		private readonly IDBConnection   $db,
		private readonly IJobList        $jobList,
		private readonly LoggerInterface $logger,
	) {
	}


	public function getName(): string
	{

		return 'File Checksum Index & Search: quiet-start defaults and cleanup';
	}


	public function run( IOutput $output ): void
	{

		$this->ensureDefaultRule( $output );
		$this->purgeLegacyPendingNew( $output );
		$this->removeLegacySeedJob( $output );
	}


	private function ensureDefaultRule( IOutput $output ): void
	{

		try
		{
			foreach ( $this->ruleService->loadRules() as $rule )
			{
				if ( RuleService::scopeKind( $rule['userScope'] ?? RuleService::SCOPE_ALL ) === 'global' )
				{
					$output->info( 'FCIAS: a global rule already exists — leaving the rules untouched.' );

					return;
				}
			}

			$this->ruleService->ruleAdd(
				[
					'enabled'        => false,
					'type'           => RuleService::TYPE_INCLUDE,
					'path'           => '**',
					'userScope'      => RuleService::SCOPE_ALL,
					'mode'           => 'auto',
					'algos'          => [
						'sha1',
						'md5',
					],
					'admin_enforced' => false,
					'pinned'         => true,
				],
				'repair',
			);

			$output->info(
				'FCIAS: created the catch-all default rule, disabled. '
				. 'No automatic hashing starts until a rule is enabled.',
			);
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, 'could not ensure the default rule', $e );
		}
	}


	private function purgeLegacyPendingNew( IOutput $output ): void
	{

		try
		{
			$qb = $this->db->getQueryBuilder();
			$qb->delete( MetadataService::TABLE_FILES_METADATA_INDEX )
			   ->where(
				   $qb->expr()
				      ->eq(
					      MetadataService::FIELD_META_KEY,
					      $qb->createNamedParameter( MetadataService::KEY_FILE_CHECKSUM_UPDATED_AT ),
				      ),
				   $qb->expr()
				      ->eq(
					      MetadataService::FIELD_META_VALUE_STRING,
					      $qb->createNamedParameter( self::LEGACY_PENDING_NEW ),
				      ),
			   )
			;

			$purged = $qb->executeStatement();

			if ( $purged > 0 )
			{
				$output->info(
					sprintf( 'FCIAS: purged %d legacy pending:new rows.', $purged ),
				);
			}
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, 'could not purge legacy pending:new rows', $e );
		}
	}


	private function removeLegacySeedJob( IOutput $output ): void
	{

		try
		{
			if ( ! $this->jobList->has( self::LEGACY_SEED_JOB, null ) )
			{
				return;
			}

			$this->jobList->remove( self::LEGACY_SEED_JOB );
			$output->info( 'FCIAS: removed the legacy seeding background job.' );
		}
		catch ( Throwable $e )
		{
			$this->warn( $output, 'could not remove the legacy seeding job', $e );
		}
	}


	private function warn(
		IOutput   $output,
		string    $what,
		Throwable $e,
	): void {

		$this->logger->error(
			'FCIAS RepairQuietStart: ' . $what,
			[
				'app'       => Application::APP_ID,
				'exception' => $e,
			],
		);

		$output->warning(
			sprintf( 'FCIAS: %s: %s', $what, $e->getMessage() ),
		);
	}

}
