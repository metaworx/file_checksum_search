<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Injects the Duplicates page script when the Files app loads.
 *
 * Listens for {@see LoadAdditionalScriptsEvent} and, if the app is
 * enabled for the current user, adds the 'duplicates' init script.
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class LoadDuplicatesScriptListener
	implements
	IEventListener
{

	public function __construct(
		private readonly IAppManager $appManager,
	) {
	}


	public static function register( IRegistrationContext $context ): void
	{

		$context->registerEventListener( LoadAdditionalScriptsEvent::class, self::class );
	}


	#[\Override]
	public function handle( Event $event ): void
	{

		if ( ! $event instanceof LoadAdditionalScriptsEvent )
		{
			return;
		}

		if ( ! $this->appManager->isEnabledForUser( Application::APP_ID ) )
		{
			return;
		}

		Util::addInitScript( Application::APP_ID, 'duplicates' );
	}

}
