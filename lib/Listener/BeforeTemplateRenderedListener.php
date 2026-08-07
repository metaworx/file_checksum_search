<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Injects the Files sidebar script and CSS on every page load.
 *
 * Listens for {@see BeforeTemplateRenderedEvent} and adds the
 * 'sidebar' init script and 'style' CSS via {@see Util}.
 *
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 */
class BeforeTemplateRenderedListener
	implements
	IEventListener
{

	public static function register( IRegistrationContext $context ): void
	{

		$context->registerEventListener( BeforeTemplateRenderedEvent::class, self::class );
	}


	#[\Override]
	public function handle( Event $event ): void
	{

		if ( ! $event instanceof BeforeTemplateRenderedEvent )
		{
			return;
		}

		Util::addInitScript( Application::APP_ID, 'sidebar' );
		Util::addStyle( Application::APP_ID, 'style' );
	}

}
