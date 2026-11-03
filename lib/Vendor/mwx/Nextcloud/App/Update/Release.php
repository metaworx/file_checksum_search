<?php

declare( strict_types=1 );

namespace OCA\FileChecksumSearch\Vendor\mwx\Nextcloud\App\Update;

/**
 * One release of an app as the store lists it: its version, its kind, the
 * Nextcloud and PHP ranges it declares, and whether this server and this
 * PHP fulfil them.
 *
 * Compatibility is decided as the server's app fetcher decides it. The
 * Nextcloud minimum is held against the server and must be there: the
 * server's comparison throws on an empty one, `*` or `<=33`, and the
 * fetcher skips the release. The Nextcloud maximum is not held at all,
 * since the fetcher ignores it for `apps.json`. Both PHP bounds are held
 * against PHP, and `*` admits any. A bound is compared to as many
 * components of the actual version as it has, one to three, so `<=34`
 * admits every 34.x; a bound of another shape is incompatible, as the
 * server's comparison throws on it and the fetcher skips the release.
 *
 * A range the server's parser cannot read makes it throw an exception the
 * fetcher does not catch, and the server's whole list comes back empty.
 * Here such a release is treated as incompatible, the conservative
 * reading: the others stay what they are.
 */
final class Release
{

//  constants

    public const STABLE      = 'stable';
    public const NIGHTLY     = 'nightly';
    public const PRE_RELEASE = 'pre-release';


//  constructor

    public function __construct(
        public readonly string $version,
        public readonly string $kind,
        public readonly string $nextcloudRange,
        public readonly string $phpRange,
        public readonly bool   $compatible,
    )
    {
    }


//  getters / setters / is* / has*

    public function isStable(): bool
    {
        return $this->kind === self::STABLE;
    }

    /**
     * Unstable is what the server means by it: nightlies and pre-releases.
     */
    public function isUnstable(): bool
    {
        return !$this->isStable();
    }

    /**
     * Whether this release is above the version given, by `version_compare`,
     * as the server's installer decides it.
     */
    public function isAbove( string $version ): bool
    {
        return version_compare( $this->version, $version, '>' );
    }


//  other non-static methods

    /**
     * @return array{version: string, kind: string, nextcloud: string, php: string, compatible: bool}
     */
    public function toArray(): array
    {
        return [
            'version'    => $this->version,
            'kind'       => $this->kind,
            'nextcloud'  => $this->nextcloudRange,
            'php'        => $this->phpRange,
            'compatible' => $this->compatible,
        ];
    }


//  static methods

    /**
     * The release of one entry of an app's `releases[]` in the store's
     * `apps.json`, or null where the entry carries no version.
     *
     * An entry whose `isNightly` is not `false` is a nightly, missing
     * included, as the fetcher's `=== false` test reads it.
     *
     * @param array<array-key, mixed> $entry
     * @param string                  $serverVersion major.minor.patch
     * @param string                  $phpVersion
     */
    public static function fromStore(
        array  $entry,
        string $serverVersion,
        string $phpVersion,
    ): ?self
    {
        $version = $entry['version'] ?? null;

        if ( !is_string( $version ) || $version === '' )
        {
            return null;
        }

        $nextcloudRange = is_string( $entry['rawPlatformVersionSpec'] ?? null ) ? $entry['rawPlatformVersionSpec'] : '*';
        $phpRange       = is_string( $entry['rawPhpVersionSpec'] ?? null ) ? $entry['rawPhpVersionSpec'] : '*';

        return new self(
            $version,
            self::kindOf( $version, ( $entry['isNightly'] ?? null ) !== false ),
            $nextcloudRange,
            $phpRange,
            self::fitsNextcloud( $serverVersion, $nextcloudRange ) && self::fitsPhp( $phpVersion, $phpRange ),
        );
    }

    /**
     * Nightly where the store flags it, a pre-release where its version
     * carries a `-` suffix, stable otherwise.
     */
    public static function kindOf(
        string $version,
        bool   $isNightly,
    ): string
    {
        if ( $isNightly )
        {
            return self::NIGHTLY;
        }

        return str_contains( $version, '-' ) ? self::PRE_RELEASE : self::STABLE;
    }

    /**
     * The minimum and maximum of a store range, `''` where it has none, as
     * the server's own parser reads them: `*` has neither; one bound is a
     * minimum where it starts with `>`, a maximum otherwise; of two, the
     * first is the minimum and the second the maximum. Each bound is its
     * operator's two characters and digits and dots. Null where the range
     * cannot be parsed.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function bounds( string $range ): ?array
    {
        if ( $range === '*' )
        {
            return [ '', '' ];
        }

        $elements = explode( ' ', $range );
        $valid    = static fn( string $bound ): bool => preg_match( '/^[0-9.]+$/', $bound ) === 1;
        $first    = substr( $elements[0], 2 );

        if ( count( $elements ) === 1 && $valid( $first ) )
        {
            return str_starts_with( $elements[0], '>' ) ? [ $first, '' ] : [ '', $first ];
        }

        if ( count( $elements ) === 2 && $valid( $first ) && $valid( substr( $elements[1], 2 ) ) )
        {
            return [ $first, substr( $elements[1], 2 ) ];
        }

        return null;
    }

    /**
     * The Nextcloud minimum, required, against the server; the maximum
     * ignored.
     */
    private static function fitsNextcloud(
        string $server,
        string $range,
    ): bool
    {
        $bounds = self::bounds( $range );

        return $bounds !== null && $bounds[0] !== '' && self::compare( $server, $bounds[0], '>=' );
    }

    /**
     * Both PHP bounds, each where there is one; `*` admits any PHP.
     */
    private static function fitsPhp(
        string $php,
        string $range,
    ): bool
    {
        if ( $range === '*' )
        {
            return true;
        }

        $bounds = self::bounds( $range );

        if ( $bounds === null )
        {
            return false;
        }

        [ $minimum, $maximum ] = $bounds;

        return ( $minimum === '' || self::compare( $php, $minimum, '>=' ) )
            && ( $maximum === '' || self::compare( $php, $maximum, '<=' ) );
    }

    /**
     * The actual version, cut to as many components as the bound has,
     * compared to it; false for a bound that is not one to three numeric
     * components, on which the server's comparison throws.
     *
     * @param '>='|'<=' $operator
     */
    private static function compare(
        string $actual,
        string $bound,
        string $operator,
    ): bool
    {
        if ( preg_match( '/^\d+(?:\.\d+){0,2}$/', $bound ) !== 1 || preg_match( '/^\d+(?:\.\d+){1,2}/', $actual, $match ) !== 1 )
        {
            return false;
        }

        $length = count( explode( '.', $bound ) );
        $actual = implode( '.', array_slice( explode( '.', $match[0] ), 0, $length ) );

        $order = version_compare( $actual, $bound );

        return match ( $operator )
        {
            '>=' => $order >= 0,
            '<=' => $order <= 0,
        };
    }
}
