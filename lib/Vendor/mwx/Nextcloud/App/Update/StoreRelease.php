<?php

declare( strict_types=1 );

namespace OCA\FileChecksumSearch\Vendor\mwx\Nextcloud\App\Update;

use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Util;
use Throwable;

/**
 * What the store holds for one app, against the version installed: the
 * installed version and its kind, and the newest stable and the newest
 * unstable release above it among those compatible with this server and
 * PHP.
 *
 * Reads `<appstoreurl>/apps.json` once, on the first question, through
 * `IClientService`: the list the server's app fetcher reads, every app with
 * every release, which `Release` filters for this server as the fetcher
 * does. A store that cannot be read is not thrown: `isReadable()` answers
 * false, `getError()` says why, and no release is known, so a report can
 * show it as unknown.
 *
 * Where the server's fetcher would not ask the store, this does not
 * either: with `appstoreenabled` false, or with `has_internet_connection`
 * false while `appstoreurl` is the default store. `getUnavailableReason()`
 * says which, without a request.
 */
final class StoreRelease
{

//  constants

    public const DEFAULT_STORE_URL = 'https://apps.nextcloud.com/api/v1';

    /** Seconds, the server's fetcher's default. */
    public const TIMEOUT = 120;


//  private properties

    private readonly string $serverVersion;

    private ?string $installedVersion = null;

    /** @var list<Release>|null */
    private ?array $releases = null;

    private ?string $error = null;


//  constructor

    /**
     * @param string|null $serverVersion major.minor.patch;
     *                                   `OCP\Util::getVersion()` where not given
     * @param string      $phpVersion    the PHP the releases are held against
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly IConfig        $config,
        private readonly IAppManager    $appManager,
        private readonly string         $appId,
        ?string                         $serverVersion = null,
        private readonly string         $phpVersion = PHP_VERSION,
    )
    {
        $this->serverVersion = $serverVersion ?? implode( '.', array_slice( Util::getVersion(), 0, 3 ) );
    }


//  getters / setters / is* / has*

    public function getAppId(): string
    {
        return $this->appId;
    }

    public function getServerVersion(): string
    {
        return $this->serverVersion;
    }

    /**
     * The URL of the store's list, as the server's fetcher builds it.
     */
    public function getUrl(): string
    {
        return $this->getStoreUrl().'/apps.json';
    }

    /**
     * Why the server's fetcher would not ask the store, or null where it
     * would; nothing is requested to answer it.
     */
    public function getUnavailableReason(): ?string
    {
        if ( !$this->config->getSystemValueBool( 'appstoreenabled', true ) )
        {
            return 'the app store is disabled: appstoreenabled is false in config.php';
        }

        if ( !$this->config->getSystemValueBool( 'has_internet_connection', true ) && $this->getStoreUrl() === self::DEFAULT_STORE_URL )
        {
            return 'the server has no internet connection: has_internet_connection is false in config.php, and appstoreurl is the default store';
        }

        return null;
    }

    private function getStoreUrl(): string
    {
        return $this->config->getSystemValueString( 'appstoreurl', self::DEFAULT_STORE_URL );
    }

    public function getInstalledVersion(): string
    {
        return $this->installedVersion ??= $this->appManager->getAppVersion( $this->appId );
    }

    /**
     * Nightly where the installed version carries a pre-release suffix, or
     * where the store lists that version as a nightly only; stable
     * otherwise, a store not read included.
     */
    public function getInstalledKind(): string
    {
        $installed = $this->getInstalledVersion();

        if ( str_contains( $installed, '-' ) )
        {
            return Release::NIGHTLY;
        }

        $listed = array_filter(
            $this->getReleases(),
            static fn( Release $release ): bool => $release->version === $installed,
        );

        if ( $listed === [] )
        {
            return Release::STABLE;
        }

        foreach ( $listed as $release )
        {
            if ( $release->kind !== Release::NIGHTLY )
            {
                return Release::STABLE;
            }
        }

        return Release::NIGHTLY;
    }

    /**
     * Every release the store lists for the app, compatible or not; none
     * where the store could not be read or does not list the app.
     *
     * @return list<Release>
     */
    public function getReleases(): array
    {
        return $this->releases ??= $this->read();
    }

    public function isReadable(): bool
    {
        $this->getReleases();

        return $this->error === null;
    }

    /**
     * Why the store could not be read, or null where it was.
     */
    public function getError(): ?string
    {
        $this->getReleases();

        return $this->error;
    }

    /**
     * The newest compatible stable release above the installed version.
     */
    public function getNewestStable(): ?Release
    {
        return $this->newestOf( static fn( Release $release ): bool => $release->isStable() );
    }

    /**
     * The newest compatible unstable release, nightly or pre-release, above
     * the installed version.
     */
    public function getNewestUnstable(): ?Release
    {
        return $this->newestOf( static fn( Release $release ): bool => $release->isUnstable() );
    }

    /**
     * The newest release a channel takes: on `stable` the newest stable
     * above the installed version, on `nightly` the newest of any kind.
     */
    public function getNewest( string $channel ): ?Release
    {
        if ( $channel === Release::STABLE )
        {
            return $this->getNewestStable();
        }

        return $this->newestOf( static fn( Release $release ): bool => true );
    }


//  other non-static methods

    /**
     * The newest compatible release above the installed version among those
     * the filter keeps; of two listings of one version, the stable one.
     *
     * @param callable( Release ): bool $filter
     */
    private function newestOf( callable $filter ): ?Release
    {
        $installed = $this->getInstalledVersion();
        $newest    = null;

        foreach ( $this->getReleases() as $release )
        {
            if ( !$release->compatible || !$release->isAbove( $installed ) || !$filter( $release ) )
            {
                continue;
            }

            if (
                $newest === null
                || $release->isAbove( $newest->version )
                || ( $release->version === $newest->version && $release->isStable() )
            )
            {
                $newest = $release;
            }
        }

        return $newest;
    }

    /**
     * @return list<Release>
     */
    private function read(): array
    {
        $this->error = $this->getUnavailableReason();

        if ( $this->error !== null )
        {
            return [];
        }

        try
        {
            $response = $this->clientService->newClient()
                                            ->get( $this->getUrl(), [ 'timeout' => self::TIMEOUT ] )
            ;
            $status   = $response->getStatusCode();

            if ( $status !== 200 )
            {
                $this->error = sprintf( 'the store answered HTTP %d', $status );

                return [];
            }

            $body = $response->getBody();
            $body = is_resource( $body ) ? stream_get_contents( $body ) : $body;
            $apps = is_string( $body ) ? json_decode( $body, true ) : null;
        }
        catch ( Throwable $exception )
        {
            $this->error = 'the store could not be read: '.$exception->getMessage();

            return [];
        }

        // An object, `{"detail": …}` say, is an error the store answered, not a list.
        if ( !is_array( $apps ) || !array_is_list( $apps ) )
        {
            $this->error = 'the store answered no list of apps';

            return [];
        }

        $releases = [];

        foreach ( $apps as $app )
        {
            if ( !is_array( $app ) || ( $app['id'] ?? null ) !== $this->appId || !is_array( $app['releases'] ?? null ) )
            {
                continue;
            }

            foreach ( $app['releases'] as $entry )
            {
                $release = is_array( $entry ) ? Release::fromStore( $entry, $this->serverVersion, $this->phpVersion ) : null;

                if ( $release !== null )
                {
                    $releases[] = $release;
                }
            }
        }

        return $releases;
    }
}
