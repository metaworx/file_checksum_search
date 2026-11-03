<?php

declare( strict_types=1 );

namespace OCA\FileChecksumSearch\Vendor\mwx\Nextcloud\App\Update;

/**
 * What the update command prints, as plain text or as JSON: the installed
 * version and its kind, the newest stable and the newest unstable release
 * above it, the channel the flags choose and the release it takes, whether
 * the server would offer it, and the outcome.
 *
 * Whether the server would offer it is the `appstoreenabled` system value
 * and the installer's own answer, which also covers an apps directory it
 * cannot write to.
 */
final class Report
{

//  constructor

    /**
     * @param string $channel     `stable` or `nightly`
     * @param bool   $channelFlag whether a flag chose the channel, rather
     *                            than the installed version's kind
     */
    public function __construct(
        public readonly StoreRelease $store,
        public readonly string       $channel,
        public readonly bool         $channelFlag,
        public readonly bool         $appStoreEnabled,
        public readonly bool         $check,
        public readonly Outcome      $outcome,
    )
    {
    }


//  getters / setters / is* / has*

    /**
     * The release the channel takes, as the store lists it.
     */
    public function getChosen(): ?Release
    {
        return $this->store->getNewest( $this->channel );
    }

    /**
     * The installer's `$allowUnstable`: true on the nightly channel.
     */
    public function isUnstableAllowed(): bool
    {
        return $this->channel === Release::NIGHTLY;
    }


//  other non-static methods

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'app'             => $this->store->getAppId(),
            'installed'       => [
                'version' => $this->store->getInstalledVersion(),
                'kind'    => $this->store->getInstalledKind(),
            ],
            'store'           => [
                'url'      => $this->store->getUrl(),
                'readable' => $this->store->isReadable(),
                'error'    => $this->store->getError(),
            ],
            'newestStable'    => $this->store->getNewestStable()
                                             ?->toArray(),
            'newestUnstable'  => $this->store->getNewestUnstable()
                                             ?->toArray(),
            'channel'         => $this->channel,
            'channelFrom'     => $this->channelFlag ? 'flag' : 'installed',
            'allowUnstable'   => $this->isUnstableAllowed(),
            'chosen'          => $this->getChosen()?->version,
            'appStoreEnabled' => $this->appStoreEnabled,
            'serverOffers'    => $this->outcome->serverOffers(),
            'check'           => $this->check,
            'outcome'         => $this->outcome->toArray(),
        ];
    }

    public function toJson( bool $pretty = false ): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

        return json_encode( $this->toArray(), $pretty ? $flags | JSON_PRETTY_PRINT : $flags );
    }

    /**
     * @return list<string>
     */
    public function toLines(): array
    {
        $unknown = $this->store->isReadable() ? 'none' : 'unknown';
        $offers  = $this->outcome->serverOffers();

        $rows = [
            'App'             => $this->store->getAppId(),
            'Installed'       => $this->store->getInstalledVersion().' ('.$this->store->getInstalledKind().')',
            'Store'           => $this->store->isReadable() ? $this->store->getUrl() : 'unknown: '.$this->store->getError(),
            'Newest stable'   => self::describe( $this->store->getNewestStable(), $unknown ),
            'Newest unstable' => self::describe( $this->store->getNewestUnstable(), $unknown ),
            'Channel'         => $this->channel.( $this->channelFlag ? ' (the flag)' : ' (the installed kind)' ),
            'Chosen'          => self::describe( $this->getChosen(), $unknown ),
            'App store'       => $this->appStoreEnabled ? 'enabled' : 'disabled (appstoreenabled)',
            'Server offers'   => match ( true )
            {
                $offers === null  => 'not asked',
                $offers === false => 'none',
                default           => $offers,
            },
            'Outcome'         => $this->outcome->describe(),
        ];

        $lines = [];

        foreach ( $rows as $label => $value )
        {
            $lines[] = sprintf( '%-16s %s', $label.':', $value );
        }

        return $lines;
    }

    public function toText(): string
    {
        return implode( PHP_EOL, $this->toLines() ).PHP_EOL;
    }


//  static methods

    private static function describe(
        ?Release $release,
        string   $none,
    ): string
    {
        return $release === null ? $none : $release->version.' ('.$release->kind.')';
    }
}
