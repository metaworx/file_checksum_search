<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\AppInfo;

use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCA\FileChecksumSearch\Search\HashSearchProvider;
use OCP\App\Events\AppDisableEvent;
use OCP\App\Events\AppEnableEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Search\IManager as SearchManager;
use OCP\Server;
use OCP\Util;

class Application
	extends
	App
	implements
	IBootstrap
{

	public function __construct()
	{

		parent::__construct( 'file_checksum_search' );
	}


	public function register( IRegistrationContext $context ): void
	{

		// Hard deletion: drop shadow table only on explicit app removal
		$context->registerUninstallHandler(
			function ()
			{

				LifecycleHandler::purgeShadowTable();
			},
		);
	}


	public function boot( IBootContext $context ): void
	{

		$context->injectFn(
			function (
				SearchManager    $searchManager,
				IEventDispatcher $dispatcher,
			) {

				// Unified Search provider
				$searchManager->registerProvider(
					Server::get( HashSearchProvider::class ),
				);

				// Frontend scripts via event listener (NC v33-compatible)
				$dispatcher->addListener(
					BeforeTemplateRenderedEvent::class,
					function ()
					{

						Util::addScript( 'file_checksum_search', 'sidebar' );
						Util::addStyle( 'file_checksum_search', 'style' );
					},
				);

				// Lifecycle: deploy SP + triggers on enable
				$dispatcher->addListener(
					AppEnableEvent::class,
					function (
						AppEnableEvent $event,
					) {

						if ( $event->getAppId() === 'file_checksum_search' )
						{
							LifecycleHandler::deployTriggers();
						}
					},
				);

				// Lifecycle: strip SP + triggers on disable (preserves table + data)
				$dispatcher->addListener(
					AppDisableEvent::class,
					function (
						AppDisableEvent $event,
					) {

						if ( $event->getAppId() === 'file_checksum_search' )
						{
							LifecycleHandler::stripTriggers();
						}
					},
				);
			},
		);
	}

}
