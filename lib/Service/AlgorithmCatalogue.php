<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Which hash algorithms this instance computes.
 *
 * What PHP offers, narrowed to what the administrator allows. Neither half
 * is compiled in: `hash_algos()` differs between builds, and a site that
 * needs `sha384` for a compliance regime should not have to wait for a
 * release that happens to add it — while a site that wants nothing but
 * `sha256` should not have seven other choices in every picker.
 *
 * Names double as metadata keys — `file-checksum-hash-<algo>` — and are
 * parsed back out of them by stripping the prefix, so an algorithm whose
 * PHP name contains `/` or `,` (`sha512/256`, `tiger192,3`) is refused
 * however the allowlist reads: it would be a legal PHP name and a hostile
 * key. {@see NAME_PATTERN}.
 *
 * Length needs no handling here. The index stores 63 characters and
 * {@see MetadataService::isTruncatable()} decides by length, so a 96-hex
 * `sha384` takes the same truncate-and-confirm path `sha512` always has.
 *
 * The default is designated, not positional: the allowlist is a set, and
 * {@see default()} answers from `default_algorithm` before it answers
 * "the first allowed".
 */
class AlgorithmCatalogue
{

//  constants

	public const CONFIG_KEY = 'allowed_algorithms';

	/** The algorithm used where none is named; empty means the first allowed. */
	public const DEFAULT_KEY = 'default_algorithm';

	/**
	 * What ships enabled: the eight the app always computed, plus the two
	 * 384-bit members of the families it already offered.
	 */
	public const DEFAULT_ALLOWLIST
		 = [
			'sha1',
			'md5',
			'adler32',
			'crc32',
			'sha256',
			'sha384',
			'sha512',
			'sha3-256',
			'sha3-384',
			'sha3-512',
		];

	/** A name that can be a metadata key. */
	public const NAME_PATTERN = '/^[a-z0-9-]+$/';

	/** @var list<string>|null */
	private ?array $algorithms = null;


//  private properties

	private ?string $default = null;


//  constructor

	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}


//  other non-static methods

	/**
	 * Every algorithm this PHP build offers under a name that can be a key.
	 *
	 * @return list<string>
	 */
	public function available(): array
	{
		$names = array_filter(
			hash_algos(),
			static fn( string $name ): bool => preg_match( self::NAME_PATTERN, $name ) === 1,
		);

		return array_values( $names );
	}

	/**
	 * The algorithms in force: the allowlist, less what PHP cannot do and
	 * what cannot be a key, in the allowlist's order. An empty or entirely
	 * unusable allowlist falls back to the shipped default rather than to
	 * nothing — an instance with no algorithm at all has no reason to exist.
	 *
	 * @return list<string>
	 */
	public function algorithms(): array
	{
		if ( $this->algorithms !== null )
		{
			return $this->algorithms;
		}

		$allowed = $this->appConfig->getValueArray( Application::APP_ID, self::CONFIG_KEY, [] );
		$usable  = $this->usable( $allowed );

		if ( $usable === [] )
		{
			$usable = $this->usable( self::DEFAULT_ALLOWLIST );
		}

		return $this->algorithms = $usable;
	}

	/**
	 * The algorithm used when none is named: the one the administrator
	 * designated, as long as it is in force, else the first allowed.
	 *
	 * The fallback is for a designation that reached the config past
	 * {@see setAllowlist()} — occ, or a PHP build that lost the algorithm;
	 * the allowlist path clears a dropped default itself.
	 */
	public function default(): string
	{
		if ( $this->default !== null )
		{
			return $this->default;
		}

		$stored = $this->appConfig->getValueString( Application::APP_ID, self::DEFAULT_KEY, '' );

		return $this->default = $this->isValid( $stored ) ? $stored : $this->algorithms()[0];
	}


//  getters / setters / is* / has*

	/**
	 * Designate the default. A name not in force is refused — a default
	 * nothing computes is no default — and the empty name clears the
	 * designation, so the first allowed applies again.
	 */
	public function setDefault( string $name ): bool
	{
		$name = strtolower( trim( $name ) );

		if ( $name === '' )
		{
			$this->appConfig->deleteKey( Application::APP_ID, self::DEFAULT_KEY );
			$this->default = null;

			return true;
		}

		if ( ! $this->isValid( $name ) )
		{
			return false;
		}

		$this->appConfig->setValueString( Application::APP_ID, self::DEFAULT_KEY, $name );
		$this->default = $name;

		return true;
	}

	/**
	 * Whether a raw, untrusted value names an algorithm in force.
	 */
	public function isValid( mixed $algo ): bool
	{
		return is_string( $algo ) && in_array( $algo, $this->algorithms(), true );
	}

	/**
	 * Keep only the algo => hash pairs this instance could itself have
	 * produced, with the hashes lower-cased.
	 *
	 * For pairs that came from `oc_filecache.checksum`. That column holds
	 * whatever a sync client put in its `OC-Checksum` header — core stores
	 * it verbatim — so the algorithm name is not a name this app chose and
	 * the value is not a hash this app computed. Adopting them saves
	 * recomputing what somebody already computed, which is the point of the
	 * column; adopting them unread is how a client gets to name a metadata
	 * key. `file-checksum-hash-<token>` has 31 characters to fit into, and
	 * an algorithm this instance does not compute has no business being one
	 * of its keys whatever its length.
	 *
	 * This checks the shape, not the content: it cannot tell whether the
	 * hash is really this file's without reading the file, which is the one
	 * thing adopting the column exists to avoid.
	 *
	 * @param  array<string, string>  $pairs
	 *
	 * @return array<string, string>
	 */
	public function keepPlausible( array $pairs ): array
	{
		$kept = [];

		foreach ( $pairs as $algo => $hash )
		{
			$algo = strtolower( trim( (string) $algo ) );
			$hash = strtolower( trim( (string) $hash ) );

			if ( ! $this->isValid( $algo ) )
			{
				continue;
			}

			// Hex, and as many digits as this algorithm produces. A hash of
			// the wrong length is not this algorithm's, whoever sent it.
			if ( preg_match( '/^[0-9a-f]+$/', $hash ) !== 1
			     || strlen( $hash ) !== strlen( hash( $algo, '' ) ) )
			{
				continue;
			}

			$kept[ $algo ] = $hash;
		}

		return $kept;
	}

	/**
	 * Store a new allowlist, keeping only what can be used. Returns what was
	 * kept, so a caller can tell the administrator what it dropped.
	 *
	 * A list that keeps nothing is not stored: the previous list stays in
	 * force and the empty result is the caller's signal to say so. Storing
	 * it would silently fall back to the shipped default, which is not what
	 * anyone who typed a list of algorithms asked for.
	 *
	 * @param  list<mixed>  $names
	 *
	 * @return list<string>
	 */
	public function setAllowlist( array $names ): array
	{
		$kept = $this->usable( $names );

		if ( $kept === [] )
		{
			return [];
		}

		$this->appConfig->setValueArray( Application::APP_ID, self::CONFIG_KEY, $kept );
		$this->algorithms = $kept;
		$this->default    = null;

		// A designated default the new list no longer contains is cleared,
		// not kept dormant: the default snaps to the first remaining, and
		// re-allowing the algorithm later does not silently reinstate it.
		$stored = $this->appConfig->getValueString( Application::APP_ID, self::DEFAULT_KEY, '' );

		if ( $stored !== '' && ! in_array( $stored, $kept, true ) )
		{
			$this->appConfig->deleteKey( Application::APP_ID, self::DEFAULT_KEY );
		}

		return $kept;
	}

	/**
	 * @param  list<mixed>  $names
	 *
	 * @return list<string>
	 */
	private function usable( array $names ): array
	{
		$available = $this->available();
		$kept      = [];

		foreach ( $names as $name )
		{
			if ( ! is_string( $name ) )
			{
				continue;
			}

			$name = strtolower( trim( $name ) );

			if ( in_array( $name, $available, true ) && ! in_array( $name, $kept, true ) )
			{
				$kept[] = $name;
			}
		}

		return $kept;
	}
}
