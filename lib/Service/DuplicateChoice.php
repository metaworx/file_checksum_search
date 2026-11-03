<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

/**
 * Which rows of a duplicate group go, by the rules the Duplicates page's
 * *Select duplicates* follows (`src/duplicates-vue/pageSelection.ts`), held
 * to it by the cases in `tests/fixtures/duplicate-choice.json`:
 *
 * - a group's best copies are its files outside the trash, or, where it has
 *   none, its versions outside the trash ({@see bestCopies()});
 * - one of them stays: the oldest by when it last changed, the first path
 *   where two are as old; none where one of them may not be deleted, as it
 *   stays anyway ({@see keeperOf()}).
 *
 * To those the command adds what the page does not offer: which copy stays,
 * or none, and the paths a row may go from and the ones it stays in, the
 * pattern naming more folders winning where both match a row.
 *
 * A row is one of the listing's, with its `actions`
 * ({@see DuplicateActionService::withActions()}).
 */
readonly class DuplicateChoice
{

//  constants

	/** The best copy that last changed longest ago stays: the page's choice. */
	public const KEEP_OLDEST = 'oldest';

	/** The best copy that changed last stays. */
	public const KEEP_NEWEST = 'newest';

	/** The best copy first by its path stays. */
	public const KEEP_FIRST = 'first';

	public const KEEPS = [
		self::KEEP_OLDEST,
		self::KEEP_NEWEST,
		self::KEEP_FIRST,
	];


//  constructor

	/**
	 * @param  string|null        $keep     Which best copy stays, of
	 *                                      {@see KEEPS}; null for none.
	 * @param  list<PathPattern>  $paths    The rows that may go; none given,
	 *                                      every row.
	 * @param  list<PathPattern>  $noPaths  The rows that stay.
	 */
	public function __construct(
		private ?string $keep = self::KEEP_OLDEST,
		private array   $paths = [],
		private array   $noPaths = [],
	) {
	}


//  static methods

	/**
	 * Whether the account may delete the row, one way or the other.
	 *
	 * @param  array<string, mixed>  $row
	 */
	public static function choosable( array $row ): bool
	{
		$actions = (array) ( $row['actions'] ?? [] );

		return in_array( DuplicateActionService::ACTION_TRASH, $actions, true )
		       || in_array( DuplicateActionService::ACTION_DELETE, $actions, true );
	}

	/**
	 * The copies a group keeps its content in: its files outside the trash,
	 * or, where it has none, its versions outside the trash. A version is no
	 * copy beside a file, going with its file and expiring, but in a group of
	 * versions alone it is the best there is.
	 *
	 * @param  array{files: list<array<string, mixed>>}  $group
	 *
	 * @return list<array<string, mixed>>  In the group's order.
	 */
	public static function bestCopies( array $group ): array
	{
		$outside = array_values( array_filter( $group['files'], static fn ( array $row ): bool => ! isset( $row['trash'] ) ) );
		$files   = array_values( array_filter( $outside, static fn ( array $row ): bool => ! isset( $row['version'] ) ) );

		return $files !== []
			? $files
			: $outside;
	}

	/**
	 * The best copy that stays, by $keep; none where one of the best copies
	 * may not be deleted, as it stays anyway, or where there is none.
	 *
	 * Two that changed as long ago are ordered by path, byte by byte; the
	 * page compares them in the browser's language, which orders the same
	 * where the paths differ in more than case and punctuation.
	 *
	 * @param  array{files: list<array<string, mixed>>}  $group
	 */
	public static function keeperOf(
		array  $group,
		string $keep = self::KEEP_OLDEST,
	): ?int
	{
		$best = self::bestCopies( $group );

		if ( $best === [] || array_filter( $best, static fn ( array $row ): bool => ! self::choosable( $row ) ) !== [] )
		{
			return null;
		}

		usort(
			$best,
			static fn ( array $a, array $b ): int => match ( $keep )
			{
				self::KEEP_NEWEST => self::heldSince( $b ) <=> self::heldSince( $a ),
				self::KEEP_FIRST  => 0,
				default           => self::heldSince( $a ) <=> self::heldSince( $b ),
			} ?: strcmp( (string) $a['path'], (string) $b['path'] ),
		);

		return (int) $best[0]['fileid'];
	}

	/**
	 * When a copy last held its content: a version's own time, otherwise the
	 * file's last change, in seconds.
	 *
	 * @param  array<string, mixed>  $row
	 */
	private static function heldSince( array $row ): int
	{
		$timestamp = $row['version']['timestamp'] ?? null;

		return is_string( $timestamp )
			? (int) strtotime( $timestamp )
			: (int) ( $row['mtime'] ?? 0 );
	}

	/**
	 * How many folders the most specific of $patterns matching $path names;
	 * null where none matches.
	 *
	 * @param  list<PathPattern>  $patterns
	 */
	private static function mostSpecific(
		array  $patterns,
		string $path,
	): ?int
	{
		$most = null;

		foreach ( $patterns as $pattern )
		{
			if ( $pattern->matches( $path ) )
			{
				$most = max( $most ?? 0, $pattern->folders() );
			}
		}

		return $most;
	}


//  other non-static methods

	/**
	 * The rows of $group that go, and the copy that stays for them.
	 *
	 * A row goes where the account may delete it and the paths let it. Where
	 * those are every best copy of the group, the one $keep picks stays,
	 * unless no copy is to stay; where a best copy stays anyway, outside the
	 * paths or not the account's to delete, none needs keeping.
	 *
	 * @param  array{files: list<array<string, mixed>>}  $group
	 *
	 * @return array{going: list<int>, keeper: ?int, keepsNone: bool}
	 *         `going` by file id in the group's order; `keepsNone` where no
	 *         best copy would stay.
	 */
	public function of( array $group ): array
	{
		$going = [];

		foreach ( $group['files'] as $row )
		{
			if ( self::choosable( $row ) && $this->mayGo( (string) $row['path'] ) )
			{
				$going[] = (int) $row['fileid'];
			}
		}

		$best   = array_map( static fn ( array $row ): int => (int) $row['fileid'], self::bestCopies( $group ) );
		$whole  = $best !== [] && array_diff( $best, $going ) === [];
		$keeper = $whole && $this->keep !== null
			? self::keeperOf( $group, $this->keep )
			: null;

		return [
			'going'     => array_values( array_filter( $going, static fn ( int $fileId ): bool => $fileId !== $keeper ) ),
			'keeper'    => $keeper,
			'keepsNone' => $whole && $keeper === null,
		];
	}

	/**
	 * Whether the paths let the row at $path go: one of them matches it, or
	 * none is given, and no pattern it stays by matches it more specifically
	 * or as specifically.
	 */
	public function mayGo( string $path ): bool
	{
		$go   = $this->paths === []
			? -1
			: self::mostSpecific( $this->paths, $path );
		$stay = self::mostSpecific( $this->noPaths, $path );

		if ( $go === null )
		{
			return false;
		}

		return $stay === null || $go > $stay;
	}
}
