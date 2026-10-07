<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\JobStatsService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One-shot background pass applying a single rule.
 *
 * The REST apply endpoint enqueues this and returns immediately — an
 * uncapped scan over a large instance has no place inside an HTTP request.
 * The argument carries the rule id and the actor who asked, so the audit
 * line names them rather than "unknown".
 *
 * The rule is re-fetched at run time and may legitimately be gone or no
 * longer applicable — deleted or disabled between enqueue and run. That is
 * a log line, not an error: the job's contract is "apply it if it still
 * makes sense".
 */
class ApplyRuleJob
    extends
    QueuedJob
{

//  constructor

	public function __construct(
		ITimeFactory                     $time,
		private readonly RuleService     $ruleService,
		private readonly JobStatsService $jobStats,
		private readonly LoggerInterface $logger,
	)
	{
		parent::__construct( $time );
	}


//  config/init/exe/run methods

	/**
	 * @param  array{ruleId?: string, actor?: string}  $argument
	 */
	#[\Override]
	protected function run( $argument ): void
	{
		$ruleId  = (string) ( $argument['ruleId'] ?? '' );
		$actor   = (string) ( $argument['actor'] ?? 'unknown' );
		$started = hrtime( true );

		try
		{
			$rule = $this->ruleService->findRuleById( $ruleId );

			// A rule gone since it was queued is a run that applied nothing.
			$result = [
				'matched' => 0,
				'marked'  => 0,
			];

			if ( $rule === null )
			{
				$this->logger->info(
					'FCIAS ApplyRuleJob: rule {ruleId} no longer exists — nothing to apply.',
					[
						'app'    => Application::APP_ID,
						'ruleId' => $ruleId,
					],
				);
			}
			else
			{
				// applyRule() audit-logs the result itself, actor included.
				$result = $this->ruleService->applyRule( $rule, null, null, $actor );
			}

			$this->jobStats->record(
				JobStatsService::JOB_RULE_APPLY,
				[
					'matched' => $result['matched'],
					'marked'  => $result['marked'],
				],
				JobStatsService::millisecondsSince( $started ),
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS ApplyRuleJob: applying rule {ruleId} failed',
				[
					'app'       => Application::APP_ID,
					'ruleId'    => $ruleId,
					'actor'     => $actor,
					'exception' => $e,
				],
			);

			$this->jobStats->recordFailure(
				JobStatsService::JOB_RULE_APPLY,
				$e,
				JobStatsService::millisecondsSince( $started ),
			);
		}
	}
}
