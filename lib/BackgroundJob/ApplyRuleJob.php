<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\BackgroundJob;

use OCA\FileChecksumSearch\AppInfo\Application;
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
		private readonly LoggerInterface $logger,
	)
	{
		parent::__construct( $time );
	}


//  config/init/exe/run methods

	/**
	 * @param  array{ruleId?: string, actor?: string}  $argument
	 */
	protected function run( $argument ): void
	{
		$ruleId = (string) ( $argument['ruleId'] ?? '' );
		$actor  = (string) ( $argument['actor'] ?? 'unknown' );

		try
		{
			$rule = $this->ruleService->findRuleById( $ruleId );

			if ( $rule === null )
			{
				$this->logger->info(
					'FCIAS ApplyRuleJob: rule {ruleId} no longer exists — nothing to apply.',
					[
						'app'    => Application::APP_ID,
						'ruleId' => $ruleId,
					],
				);

				return;
			}

			// applyRule() audit-logs the result itself, actor included.
			$this->ruleService->applyRule( $rule, null, null, $actor );
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
		}
	}
}
