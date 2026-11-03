<?php

declare( strict_types=1 );

namespace OCA\FileChecksumSearch\Vendor\mwx\Nextcloud\App\Update;

/**
 * How a run ended: updated to a version, an update offered where only
 * checked, nothing to take, or failed with a reason; and whether the
 * server's installer was asked, which tells its answer from no answer.
 */
final class Outcome
{

//  constants

    public const UPDATED = 'updated';
    public const OFFERED = 'offered';
    public const NOTHING = 'nothing';
    public const FAILED  = 'failed';

    public const EXIT_TAKEN   = 0;
    public const EXIT_NOTHING = 1;
    public const EXIT_FAILED  = 2;


//  constructor

    private function __construct(
        public readonly string  $status,
        public readonly ?string $version,
        public readonly string  $reason,
        public readonly bool    $installerAsked,
    )
    {
    }


//  other non-static methods

    /**
     * 0 updated, or offered under `--check`; 1 nothing to take; 2 failed.
     */
    public function exitCode(): int
    {
        return match ( $this->status )
        {
            self::UPDATED, self::OFFERED => self::EXIT_TAKEN,
            self::NOTHING                => self::EXIT_NOTHING,
            default                      => self::EXIT_FAILED,
        };
    }

    /**
     * The version the server's installer offered: a string where it offered
     * one, false where it offered none, null where it was not asked.
     */
    public function serverOffers(): string|false|null
    {
        if ( !$this->installerAsked )
        {
            return null;
        }

        return $this->version ?? false;
    }

    /**
     * One line for the report.
     */
    public function describe(): string
    {
        return match ( $this->status )
        {
            self::UPDATED => 'updated to '.$this->version,
            self::OFFERED => 'would update to '.$this->version.' (--check: nothing changed)',
            self::NOTHING => 'nothing to take: '.$this->reason,
            default       => 'failed: '.$this->reason,
        };
    }

    /**
     * @return array{status: string, version: string|null, reason: string, exitCode: int}
     */
    public function toArray(): array
    {
        return [
            'status'   => $this->status,
            'version'  => $this->version,
            'reason'   => $this->reason,
            'exitCode' => $this->exitCode(),
        ];
    }


//  static methods

    public static function updated( string $version ): self
    {
        return new self( self::UPDATED, $version, '', true );
    }

    public static function offered( string $version ): self
    {
        return new self( self::OFFERED, $version, '', true );
    }

    public static function nothing(
        string $reason,
        bool   $installerAsked = false,
    ): self
    {
        return new self( self::NOTHING, null, $reason, $installerAsked );
    }

    /**
     * @param string|null $version the version the installer offered, where it did
     */
    public static function failed(
        string  $reason,
        bool    $installerAsked = false,
        ?string $version = null,
    ): self
    {
        return new self( self::FAILED, $version, $reason, $installerAsked );
    }
}
