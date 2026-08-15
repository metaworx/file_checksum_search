<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\AppInfo;

use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCA\FileChecksumSearch\Listener\AppDisableListener;
use OCA\FileChecksumSearch\Listener\BeforeTemplateRenderedListener;
use OCA\FileChecksumSearch\Listener\FileListener;
use OCA\FileChecksumSearch\Listener\LoadDuplicatesScriptListener;
use OCA\FileChecksumSearch\Listener\MetadataListener;
use OCA\FileChecksumSearch\Search\HashSearchProvider;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Server;

class Application
	extends
	App
	implements
	IBootstrap
{

	public const APP_ID = 'file_checksum_search';


	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct()
	{

		parent::__construct( self::APP_ID );
	}


	public function register( IRegistrationContext $context ): void
	{

		$context->registerConfigLexicon( ConfigLexicon::class );
		$context->registerSearchProvider( HashSearchProvider::class );

		LoadDuplicatesScriptListener::register( $context );
		FileListener::register( $context );
		MetadataListener::register( $context );
		BeforeTemplateRenderedListener::register( $context );
		AppDisableListener::register( $context );
	}


	public function boot( IBootContext $context ): void
	{

		// Register metadata keys with oc_files_metadata
		Server::get( MetadataService::class )
		      ->register()
		;
	}

}
