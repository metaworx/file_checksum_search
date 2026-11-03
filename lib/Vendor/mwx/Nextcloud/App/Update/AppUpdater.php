<?php

declare( strict_types=1 );

namespace OCA\FileChecksumSearch\Vendor\mwx\Nextcloud\App\Update;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use Throwable;

/**
 * Lets the server's installer take an app's update, after clearing what
 * would keep it from seeing the store as it is.
 *
 * The server's app fetcher answers its cached `apps.json` within its TTL
 * whatever `$allowUnstable` asks, and re-filters that already filtered
 * content on a `304`, so a nightly never appears until the store's ETag
 * changes; a failed fetch also holds every fetch off for five minutes.
 * Both are deleted before the installer is asked: the file in the
 * `appstore` app-data folder, and `settings`/`appstore-fetcher-lastFailure`.
 */
final class AppUpdater
{

//  constants

    public const CACHE_FOLDER = 'appstore';
    public const CACHE_FILE   = 'apps.json';
    public const HOLD_APP     = 'settings';
    public const HOLD_KEY     = 'appstore-fetcher-lastFailure';

    /**
     * Every class and interface of the package, loaded before the
     * installer replaces the app's files: what it puts there is whatever
     * the store shipped, so the rest of the run may load nothing from it.
     *
     * @var list<class-string>
     */
    public const PRELOAD = [
        AppUpdater::class,
        Installer::class,
        Outcome::class,
        Release::class,
        Report::class,
        ServerInstaller::class,
        StoreRelease::class,
        UpdateCommand::class,
    ];


//  constructor

    public function __construct(
        private readonly Installer       $installer,
        private readonly IAppDataFactory $appDataFactory,
        private readonly IAppConfig      $appConfig,
    )
    {
    }


//  other non-static methods

    /**
     * Deletes the fetcher's cached `apps.json` and its failure hold; a file
     * that is not there is nothing to delete.
     *
     * @throws Throwable where the file is there and cannot be deleted
     */
    public function clearFetcherCache(): void
    {
        try
        {
            $this->appDataFactory->get( self::CACHE_FOLDER )
                                 ->getFolder( '/' )
                                 ->getFile( self::CACHE_FILE )
                                 ->delete()
            ;
        }
        catch ( NotFoundException )
        {
            // Nothing cached yet, or the folder not created: nothing to clear.
        }

        $this->appConfig->deleteKey( self::HOLD_APP, self::HOLD_KEY );
    }

    /**
     * Clears the fetcher's cache, asks the installer what it offers, and
     * takes it; where only checking, answers what it offers and takes
     * nothing.
     *
     * Where the version the store says the channel takes is given, an
     * installer offering another fails before anything is taken: on a
     * server on the beta, daily or git channel the fetcher admits unstable
     * releases whatever `$allowUnstable` says.
     *
     * @param string|null $expected the version the channel takes, where the
     *                              store was read
     */
    public function update(
        string  $appId,
        bool    $allowUnstable,
        bool    $check = false,
        ?string $expected = null,
    ): Outcome
    {
        try
        {
            $this->clearFetcherCache();
        }
        catch ( Throwable $exception )
        {
            return Outcome::failed( 'the fetcher\'s cache could not be cleared: '.$exception->getMessage() );
        }

        try
        {
            $offered = $this->installer->isUpdateAvailable( $appId, $allowUnstable );
        }
        catch ( Throwable $exception )
        {
            return Outcome::failed( 'the server\'s installer could not be asked: '.$exception->getMessage() );
        }

        if ( $offered === false )
        {
            return Outcome::nothing( 'the server\'s installer offers no update', true );
        }

        if ( $expected !== null && $offered !== $expected )
        {
            return Outcome::failed(
                sprintf(
                    'the channel takes %s, but the server\'s installer offers %s, as the server\'s own update channel may admit; nothing was taken',
                    $expected,
                    $offered,
                ),
                true,
                $offered,
            );
        }

        if ( $check )
        {
            return Outcome::offered( $offered );
        }

        self::preload();

        try
        {
            $updated = $this->installer->updateAppstoreApp( $appId, $allowUnstable );
        }
        catch ( Throwable $exception )
        {
            return Outcome::failed( 'the server\'s installer failed: '.$exception->getMessage(), true, $offered );
        }

        if ( !$updated )
        {
            return Outcome::failed(
                sprintf( 'the server\'s installer did not complete the update to %s; the server\'s log says why', $offered ),
                true,
                $offered,
            );
        }

        return Outcome::updated( $offered );
    }


//  static methods

    /**
     * Loads every class and interface in `PRELOAD` that is not loaded yet.
     */
    public static function preload(): void
    {
        foreach ( self::PRELOAD as $name )
        {
            class_exists( $name ) || interface_exists( $name );
        }
    }
}
