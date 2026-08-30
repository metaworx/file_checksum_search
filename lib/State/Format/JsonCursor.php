<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\State\Format;

use RuntimeException;

/**
 * A pull cursor over a JSON stream, just deep enough for a backup document.
 *
 * A backup of a large instance does not fit in memory as a decoded PHP array —
 * a million records is a few hundred megabytes of text and several times that
 * once decoded — so the document is walked rather than loaded. This is not a
 * general JSON parser: it knows how to step over the structure (objects,
 * arrays, strings, numbers, literals) and hands each *element* of the record
 * array to `json_decode` on its own, which is where the real parsing happens.
 *
 * Kept separate from {@see JsonFormat} because it carries a read position, and
 * a format should stay reusable across streams.
 *
 * @internal
 */
class JsonCursor
{

	/** How much to pull from the stream at a time. */
	private const CHUNK = 65536;

	private string $buffer = '';

	private int    $offset = 0;

	/**
	 * Set while a raw value is being scanned, because that scan holds an
	 * offset into the buffer and compaction would move the ground under it.
	 */
	private bool $pinned = false;


	/**
	 * @param  resource  $stream
	 */
	public function __construct(
		private $stream,
	) {
	}


	/**
	 * The next character without consuming it, or null at end of input.
	 */
	public function peek(): ?string
	{

		if ( ! $this->ensure() )
		{
			return null;
		}

		return $this->buffer[ $this->offset ];
	}


	/**
	 * Consume and return the next character.
	 */
	public function next(): string
	{

		if ( ! $this->ensure() )
		{
			throw new RuntimeException( 'Unexpected end of JSON input.' );
		}

		return $this->buffer[ $this->offset ++ ];
	}


	/**
	 * Consume whitespace; leaves the cursor on the next meaningful character.
	 */
	public function skipWhitespace(): void
	{

		while ( ( $char
				= $this->peek() ) !== null
			&& ( $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" ) )
		{
			$this->offset ++;
		}
	}


	/**
	 * Consume the given character, or say what was there instead.
	 */
	public function expect( string $expected ): void
	{

		$this->skipWhitespace();
		$char = $this->peek();

		if ( $char !== $expected )
		{
			throw new RuntimeException(
				sprintf(
					'Expected %s in JSON input, found %s.',
					$expected,
					$char === null
						? 'end of input'
						: $char,
				),
			);
		}

		$this->offset ++;
	}


	/**
	 * Read one complete JSON value and return its raw text, whatever its
	 * shape. Nesting is tracked by depth; quoting by an escape flag, so a
	 * brace inside a string never ends the value.
	 */
	public function readRawValue(): string
	{

		$this->skipWhitespace();
		$this->pinned = true;

		try
		{
			return $this->scanRawValue();
		}
		finally
		{
			$this->pinned = false;
		}
	}


	/**
	 * Step over one complete value without keeping any of it.
	 *
	 * For a section this reader has no use for — the status slice a backup
	 * records for the operator — whose size is the size of the instance.
	 * Reading it as text would defeat the streaming it sits in front of.
	 */
	public function skipValue(): void
	{

		$this->skipWhitespace();
		$this->walkValue();
	}


	/**
	 * The scan itself, split out so that the pin is released on every exit.
	 */
	private function scanRawValue(): string
	{

		$start = $this->offset;
		$this->walkValue();

		return substr( $this->buffer, $start, $this->offset - $start );
	}


	/**
	 * Advance the cursor past one complete value, whatever its shape.
	 * Nesting is tracked by depth; quoting by an escape flag, so a brace
	 * inside a string never ends the value.
	 */
	private function walkValue(): void
	{

		$depth   = 0;
		$inQuote = false;
		$escaped = false;

		while ( true )
		{
			if ( ! $this->ensure() )
			{
				if ( $depth !== 0 || $inQuote )
				{
					throw new RuntimeException( 'Unexpected end of JSON input.' );
				}

				return;
			}

			$char = $this->buffer[ $this->offset ];

			if ( $inQuote )
			{
				$this->offset ++;

				if ( $escaped )
				{
					$escaped = false;
				}
				elseif ( $char === '\\' )
				{
					$escaped = true;
				}
				elseif ( $char === '"' )
				{
					$inQuote = false;

					if ( $depth === 0 )
					{
						break;
					}
				}

				continue;
			}

			if ( $char === '"' )
			{
				$inQuote = true;
				$this->offset ++;

				continue;
			}

			if ( $char === '{' || $char === '[' )
			{
				$depth ++;
				$this->offset ++;

				continue;
			}

			if ( $char === '}' || $char === ']' )
			{
				// A closer at depth zero belongs to the container around this
				// value, not to the value: stop before it.
				if ( $depth === 0 )
				{
					break;
				}

				$depth --;
				$this->offset ++;

				if ( $depth === 0 )
				{
					break;
				}

				continue;
			}

			if ( $depth === 0 && ( $char === ',' || $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" ) )
			{
				break;
			}

			$this->offset ++;
		}
	}


	/**
	 * Read one value and decode it.
	 */
	public function readValue(): mixed
	{

		$raw     = $this->readRawValue();
		$decoded = json_decode( $raw, true );

		if ( $decoded === null && trim( $raw ) !== 'null' )
		{
			throw new RuntimeException( 'Malformed JSON value: ' . substr( $raw, 0, 120 ) );
		}

		return $decoded;
	}


	/**
	 * Read a string literal — the cursor must be on its opening quote.
	 */
	public function readString(): string
	{

		$this->skipWhitespace();

		if ( $this->peek() !== '"' )
		{
			throw new RuntimeException( 'Expected a JSON string.' );
		}

		$value = $this->readValue();

		if ( ! is_string( $value ) )
		{
			throw new RuntimeException( 'Expected a JSON string.' );
		}

		return $value;
	}


	/**
	 * Make sure one more byte is available from the current offset, refilling
	 * from the stream and discarding what has already been read.
	 */
	private function ensure(): bool
	{

		// Everything before the cursor has been consumed and can go, but only
		// while no scan holds a position in it.
		if ( ! $this->pinned && $this->offset >= self::CHUNK )
		{
			$this->buffer = substr( $this->buffer, $this->offset );
			$this->offset = 0;
		}

		while ( strlen( $this->buffer ) <= $this->offset )
		{
			$chunk = fread( $this->stream, self::CHUNK );

			if ( $chunk === false || $chunk === '' )
			{
				return false;
			}

			$this->buffer .= $chunk;
		}

		return true;
	}

}
