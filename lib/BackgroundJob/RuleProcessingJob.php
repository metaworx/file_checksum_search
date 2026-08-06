<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 * @author    metaworx
 */

namespace OCA\FileChecksumSearch\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Evaluates configured hash-generation rules and marks matching files
 * as pending:{mode} for deferred processing.
 *
 * Thin orchestrator — all rule evaluation logic lives in RuleService.
 *
 * Registered in Application::boot() via IJobList.
 */
class RuleProcessingJob
	extends
	TimedJob
{

	public function __construct(
		ITimeFactory                $time,
		private readonly RuleService    $ruleService,
		private readonly IAppConfig     $appConfig,
		private readonly IJobList       $jobList,
		private readonly LoggerInterface $logger,
	) {

		parent::__construct( $time );

		$interval = $this->appConfig->getValueInt(
			Application::APP_ID,
			'rule_processing_interval',
			300,
		);

		$this->setInterval( $interval );
		$this->setTimeSensitivity( self::TIME_INSENSITIVE );

		$this->logger->debug(
			'FCIAS RuleProcessingJob: job instance constructed (interval={interval}s).',
			[
				'app'      => Application::APP_ID,
				'interval' => $interval,
			],
		);
	}


	protected function run( $argument ): void
	{

		$this->logger->info(
			'FCIAS RuleProcessingJob: run() called.',
			[
				'app'      => Application::APP_ID,
				'argument' => $argument,
			],
		);

		try
		{
			$result = $this->ruleService->evaluateRules();

			if ( $result['marked'] > 0 )
			{
				$this->jobList->add( ProcessPendingUpdates::class );
			}
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS RuleProcessingJob: evaluation failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);
		}
	}

}
