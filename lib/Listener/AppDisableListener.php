<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCA\FileChecksumSearch\Service\CronJobService;
use OCA\FileChecksumSearch\Service\TriggerInitializationService;
use OCP\App\Events\AppDisableEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * @template-implements IEventListener<AppDisableEvent>
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class AppDisableListener
	implements
	IEventListener
{

	public function __construct(
		private readonly LifecycleHandler             $lifecycleHandler,
		private readonly TriggerInitializationService $triggerInitService,
		private readonly CronJobService               $cronJobService,
	) {
	}


	public static function register( IRegistrationContext $context ): void
	{

		$context->registerEventListener( AppDisableEvent::class, self::class );
	}


	#[\Override]
	public function handle( Event $event ): void
	{

		if ( ! $event instanceof AppDisableEvent )
		{
			return;
		}

		if ( $event->getAppId() !== Application::APP_ID )
		{
			return;
		}

		$this->lifecycleHandler->stripTriggers();
		$this->triggerInitService->markUndeployed( Application::APP_ID );
		$this->cronJobService->backup();
	}

}
