<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCP\IAppConfig;

readonly class TriggerInitializationService
{

	public function __construct(
		private IAppConfig       $appConfig,
		private LifecycleHandler $lifecycleHandler,
	) {
	}


	public function deployIfNeeded( string $appId ): void
	{

		if ( $this->appConfig->getValueBool( $appId, 'triggers_deployed' ) )
		{
			return;
		}

		// Set flag first to minimize race window during concurrent requests.
		// deployTriggers() is idempotent (DROP IF EXISTS before CREATE),
		// so redundant calls during the race window are harmless.
		$this->appConfig->setValueBool( $appId, 'triggers_deployed', true );
		$this->lifecycleHandler->deployTriggers();
	}


	public function markUndeployed( string $appId ): void
	{

		$this->appConfig->setValueBool( $appId, 'triggers_deployed', false );
	}

}
