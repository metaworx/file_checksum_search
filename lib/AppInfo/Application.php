<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\AppInfo;

use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCA\FileChecksumSearch\Service\TriggerInitializationService;
use OCP\App\Events\AppDisableEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Server;
use OCP\Util;

class Application
	extends
	App
	implements
	IBootstrap
{

	public const APP_ID = 'file_checksum_search';


	public function __construct()
	{

		parent::__construct( self::APP_ID );
	}


	public function register( IRegistrationContext $context ): void
	{

		$context->registerConfigLexicon( ConfigLexicon::class );
	}


	public function boot( IBootContext $context ): void
	{

		$dispatcher = Server::get( IEventDispatcher::class );

		// Frontend scripts via event listener (NC v33-compatible)
		$dispatcher->addListener(
			BeforeTemplateRenderedEvent::class,
			function ()
			{

				Util::addInitScript( self::APP_ID, 'sidebar' );
				Util::addStyle( self::APP_ID, 'style' );
			},
		);

		// Lifecycle: strip SP + triggers on disable, reset deploy flag
		$dispatcher->addListener(
			AppDisableEvent::class,
			function (
				AppDisableEvent $event,
			) {

				if ( $event->getAppId() === self::APP_ID )
				{
					Server::get( LifecycleHandler::class )
					      ->stripTriggers()
					;
					Server::get( TriggerInitializationService::class )
					      ->markUndeployed( self::APP_ID )
					;
				}
			},
		);

		// Deploy triggers on first boot after enable
		Server::get( TriggerInitializationService::class )
		      ->deployIfNeeded( self::APP_ID )
		;
	}

}
