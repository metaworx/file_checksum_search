<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\AppInfo;

use OCA\FileChecksumSearch\BackgroundJob\DrainPendingUpdates;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCA\FileChecksumSearch\Listener\FileListener;
use OCA\FileChecksumSearch\Listener\LoadDuplicatesScriptListener;
use OCA\FileChecksumSearch\Migration\LifecycleHandler;
use OCA\FileChecksumSearch\Search\HashSearchProvider;
use OCA\FileChecksumSearch\Service\CronJobService;
use OCA\FileChecksumSearch\Service\TriggerInitializationService;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\App\Events\AppDisableEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
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
		$context->registerSearchProvider( HashSearchProvider::class );

		$context->registerEventListener(
			LoadAdditionalScriptsEvent::class,
			LoadDuplicatesScriptListener::class,
		);

		$context->registerEventListener(
			NodeCopiedEvent::class,
			FileListener::class,
		);
		$context->registerEventListener(
			NodeWrittenEvent::class,
			FileListener::class,
		);
		$context->registerEventListener(
			NodeCreatedEvent::class,
			FileListener::class,
		);
		$context->registerEventListener(
			NodeDeletedEvent::class,
			FileListener::class,
		);
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

		// Lifecycle: strip SP + triggers, reset deploy flag,
		// backup cron job definitions before NC drops them
		$dispatcher->addListener(
			AppDisableEvent::class,
			function (
				AppDisableEvent $event,
			) {

				if ( $event->getAppId() !== self::APP_ID )
				{
					return;
				}

				Server::get( LifecycleHandler::class )
				      ->stripTriggers()
				;
				Server::get( TriggerInitializationService::class )
				      ->markUndeployed( self::APP_ID )
				;
				Server::get( CronJobService::class )
				      ->backup()
				;
			},
		);

		// Deploy triggers on first boot after enable
		Server::get( TriggerInitializationService::class )
		      ->deployIfNeeded( self::APP_ID )
		;

		// Re-register cron job definitions from backup
		Server::get( CronJobService::class )
		      ->restore()
		;

		// Register the pending hash update drain job
		Server::get( IJobList::class )
		      ->add( DrainPendingUpdates::class )
		;
	}

}
