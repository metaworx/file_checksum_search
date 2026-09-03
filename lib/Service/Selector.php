<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use InvalidArgumentException;

/**
 * A rule's selector: which slice of the file universe it addresses.
 *
 * Scope ("whose files") and storage ("which storage") proved to be one
 * axis, not two — every off-diagonal combination of a two-field model is
 * meaningless or redundant, because non-home storages have no user
 * dimension and home's sub-addressing IS the user/group dimension. The
 * selector names the slice directly:
 *
 *   home:<uid>          one user's home
 *   group:<gid>         the members' homes
 *   home:*              all homes
 *   groupfolder:<id>    one group folder
 *   storage:<raw id>    one storage, oc_storages id verbatim
 *   *                   everything
 *
 * Parsing splits on the FIRST colon; the remainder is a value with nothing
 * concatenated after it, so raw storage ids containing ':' or '//'
 * (smb::user@host//share/) need no escaping. No sugar forms — the
 * canonical spelling is the only spelling, and `home:*` over a bare `home`
 * is deliberate: the string documents itself.
 *
 * Precedence is derived, never stored: rank = exact (home:<uid>,
 * storage:<id>) > group (group:<gid>, groupfolder:<id> — group folders sit
 * with groups presentationally; namespaces are disjoint, so the placement
 * cannot change what matches) > namespace-wide (home:*) > universal (*).
 * Display band = rank for enforced rules, rank + 4 for unenforced (1–8).
 *
 * `group:*` is deliberately NOT a value: conceptually it slots into the
 * ladder ("member of at least one group") but has no use case; the parser
 * rejects it so the space stays clean.
 */
readonly class Selector
{

	public const KIND_USER = 'user';

	public const KIND_GROUP = 'group';

	public const KIND_HOME_ALL = 'home-all';

	public const KIND_GROUPFOLDER = 'groupfolder';

	public const KIND_STORAGE = 'storage';

	public const KIND_UNIVERSAL = 'universal';

	public const RANK_EXACT = 1;

	public const RANK_GROUP = 2;

	public const RANK_NAMESPACE = 3;

	public const RANK_UNIVERSAL = 4;

	/** Display bands span two tiers of four ranks: enforced 1–4, unenforced 5–8. */
	public const BAND_COUNT = 8;


	private function __construct(
		public string  $kind,
		public ?string $target,
	) {
	}


	/**
	 * Parse a canonical selector string.
	 *
	 * @throws InvalidArgumentException on anything but the six documented forms
	 */
	public static function parse( string $value ): self
	{

		if ( $value === '*' )
		{
			return new self( self::KIND_UNIVERSAL, null );
		}

		$colon = strpos( $value, ':' );

		if ( $colon === false || $colon === 0 )
		{
			throw new InvalidArgumentException(
				sprintf( 'Unknown selector "%s".', $value ),
			);
		}

		$kind   = substr( $value, 0, $colon );
		$target = substr( $value, $colon + 1 );

		if ( $target === '' )
		{
			throw new InvalidArgumentException(
				sprintf( 'Selector "%s" is missing its target.', $value ),
			);
		}

		return match ( $kind )
		{
			'home' => $target === '*'
				? new self( self::KIND_HOME_ALL, null )
				: new self( self::KIND_USER, $target ),
			'group' => $target === '*'
				? throw new InvalidArgumentException(
					'group:* is not a selector — conceptually it slots into the ladder, practically it has no use case.',
				)
				: new self( self::KIND_GROUP, $target ),
			'groupfolder' => new self( self::KIND_GROUPFOLDER, $target ),
			'storage' => new self( self::KIND_STORAGE, $target ),
			default => throw new InvalidArgumentException(
				sprintf( 'Unknown selector kind "%s".', $kind ),
			),
		};
	}


	/**
	 * Parse a stored value, accepting the two pre-selector legacy forms —
	 * 'all' and a bare uid — so rules written before the migration keep
	 * working while it runs. New input never comes through here.
	 */
	public static function fromStored( string $value ): self
	{

		if ( $value === 'all' )
		{
			return new self( self::KIND_HOME_ALL, null );
		}

		try
		{
			return self::parse( $value );
		}
		catch ( InvalidArgumentException )
		{
			// Legacy bare uid.
			return new self( self::KIND_USER, $value );
		}
	}


	/**
	 * The stored spelling of this selector.
	 *
	 * What {@see fromStored()} and {@see parse()} must read back as the same
	 * selector — that round trip is the invariant, because rules are stored
	 * as these strings and compared as these strings. Note the two that are
	 * not `kind:target`: the home namespace is `home:*` and the universal
	 * selector is a bare `*`.
	 */
	public function canonical(): string
	{

		return match ( $this->kind )
		{
			self::KIND_USER => 'home:' . $this->target,
			self::KIND_GROUP => 'group:' . $this->target,
			self::KIND_HOME_ALL => 'home:*',
			self::KIND_GROUPFOLDER => 'groupfolder:' . $this->target,
			self::KIND_STORAGE => 'storage:' . $this->target,
			default => '*',
		};
	}


	/**
	 * How specific this selector is, lower being more specific.
	 *
	 * The whole precedence model rests on this: one account or one storage
	 * beats a group, a group beats the home namespace, and the universal
	 * selector comes last. {@see band()} is this plus four for rules nobody
	 * enforced, which is why enforcement can never be outranked by
	 * specificity.
	 */
	public function rank(): int
	{

		return match ( $this->kind )
		{
			self::KIND_USER, self::KIND_STORAGE => self::RANK_EXACT,
			self::KIND_GROUP, self::KIND_GROUPFOLDER => self::RANK_GROUP,
			self::KIND_HOME_ALL => self::RANK_NAMESPACE,
			default => self::RANK_UNIVERSAL,
		};
	}


	/** The display band: enforced rules occupy 1–4, unenforced 5–8. */
	public function band( bool $enforced ): int
	{

		return $enforced
			? $this->rank()
			: $this->rank() + 4;
	}


	/** Whether this selector addresses the home namespace at all. */
	public function isHomeKind(): bool
	{

		return in_array(
			$this->kind,
			[
				self::KIND_USER,
				self::KIND_GROUP,
				self::KIND_HOME_ALL,
			],
			true,
		);
	}

}
