<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Migration;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sort stored hash-generation rules into priority bands.
 *
 * Runs post-migration on install and on every upgrade. The work itself is
 * idempotent ({@see RuleService::migrateToBands()}), so re-running on a
 * already-banded instance is a no-op.
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class RepairBandOrder
	implements
	IRepairStep
{

	public function __construct(
		private readonly RuleService     $ruleService,
		private readonly LoggerInterface $logger,
	) {
	}


	public function getName(): string
	{

		return 'File Checksum Index & Search: sort rules into priority bands';
	}


	public function run( IOutput $output ): void
	{

		try
		{
			$result = $this->ruleService->migrateToBands();
		}
		catch ( Throwable $e )
		{
			// A repair step that throws blocks the whole upgrade. Rule order
			// is recoverable by hand from the admin page, so log loudly and
			// let the upgrade finish rather than wedging the instance.
			$this->logger->error(
				'FCIAS RepairBandOrder: failed to sort rules into bands',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			$output->warning(
				'Could not sort hash-generation rules into priority bands: ' . $e->getMessage(),
			);

			return;
		}

		if ( $result['rules'] === 0 )
		{
			$output->info( 'No hash-generation rules to sort.' );

			return;
		}

		$output->info(
			sprintf(
				'Sorted %d hash-generation rule(s) into priority bands%s.',
				$result['rules'],
				$result['pinnedId'] === null
					? ' (no catch-all default found)'
					: '; catch-all default pinned to the last band',
			),
		);
	}

}
