<?php

declare( strict_types=1 );

namespace OCA\FileChecksumSearch\Vendor\mwx\Nextcloud\App\Update;

/**
 * The part of the server's installer an update needs: whether the store
 * offers one, and taking it. The server's own downloads, verifies the
 * certificate and the signature, extracts and upgrades; nothing of that is
 * done here. `ServerInstaller` adapts the server's, a test mocks this.
 */
interface Installer
{

//  getters / setters / is* / has*

    /**
     * The version the server would update the app to, or false where it
     * offers none: nothing above the installed version, the store disabled,
     * no apps directory to write to, or a git checkout.
     */
    public function isUpdateAvailable(
        string $appId,
        bool   $allowUnstable,
    ): string|false;


//  other non-static methods

    /**
     * Whether the server downloaded, installed and upgraded the update it
     * offers; false where it offers none or failed on the way.
     */
    public function updateAppstoreApp(
        string $appId,
        bool   $allowUnstable,
    ): bool;
}
