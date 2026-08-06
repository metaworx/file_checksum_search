<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\App\Events\AppDisableEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Handles app-disable lifecycle: logs the event.
 *
 * @template-implements IEventListener<AppDisableEvent>
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class AppDisableListener
	implements
	IEventListener
{

	public function __construct(
		private readonly LoggerInterface $logger,
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

		$this->logger->info(
			'FCIAS AppDisableListener: app disabling.',
			[ 'app' => Application::APP_ID ],
		);
	}

}
