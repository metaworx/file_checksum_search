<?php

declare( strict_types=1 );

namespace OCA\FileChecksumSearch\Vendor\mwx\Nextcloud\App\Update;

use OCP\Server;

/**
 * The package's `Installer` over the server's own, `OC\Installer`.
 *
 * This is the one class of the package that names an internal server
 * class. `OC\Installer` is not in `nextcloud/ocp` and carries no API
 * promise, so it is named here as a string, never as a symbol static
 * analysis would have to find, resolved from the server's container by
 * that name on the first call, and never constructed.
 *
 * The app consuming the package owes the pin: an integration test,
 * against the server it runs on, asserting that `OC\Installer` exists and
 * that `isUpdateAvailable( string $appId, bool $allowUnstable ): string|false`
 * and `updateAppstoreApp( string $appId, bool $allowUnstable ): bool` keep
 * their signatures, so that a server changing them fails the app's suite
 * rather than an update.
 */
final class ServerInstaller
    implements
    Installer
{

//  constants

    /** The server's installer, by name alone. */
    public const SERVER_CLASS = 'OC\\Installer';


//  private properties

    private ?object $server = null;


//  getters / setters / is* / has*

    #[\Override]
    public function isUpdateAvailable(
        string $appId,
        bool   $allowUnstable,
    ): string|false
    {
        /** @psalm-suppress MixedMethodCall, MixedAssignment the server's class, unknown to analysis */
        $offered = $this->server()
                        ->isUpdateAvailable( $appId, $allowUnstable )
        ;

        return is_string( $offered ) ? $offered : false;
    }


//  other non-static methods

    #[\Override]
    public function updateAppstoreApp(
        string $appId,
        bool   $allowUnstable,
    ): bool
    {
        /** @psalm-suppress MixedMethodCall the server's class, unknown to analysis */
        return $this->server()
                    ->updateAppstoreApp( $appId, $allowUnstable ) === true
        ;
    }

    private function server(): object
    {
        if ( $this->server === null )
        {
            /** @var object $server */
            $server       = Server::get( self::SERVER_CLASS );
            $this->server = $server;
        }

        return $this->server;
    }
}
