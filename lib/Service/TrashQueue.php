<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use Closure;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Deletions in an order that keeps the trash from losing any: no two files
 * of one name go to the trash in one second.
 *
 * The trash keeps a file as `<name>.d<second>`, and where that name is
 * taken it deletes the file there first, so of two files sharing a name
 * trashed in one second it keeps one. A deletion whose name the trash took
 * this second therefore waits at the back while the others go, and the
 * clock is waited on only when nothing else is left.
 *
 * The names taken are kept for as long as the queue lives, a request or a
 * command's run, so that a deletion following in the same second waits too.
 * They are kept by the name alone: the trash is per owner, and waiting once
 * too often costs a second, once too seldom a file.
 */
class TrashQueue
{

//  constants

	/** How long a wait for the clock sleeps before it looks again, in microseconds. */
	private const PAUSE = 100_000;

	/**
	 * The second the trash last took each name in.
	 *
	 * @var array<string, int>
	 */
	private array $taken = [];

	/**
	 * The deletions not yet done, in their order: the name the trash takes
	 * each under, or null for one that does not go there.
	 *
	 * @var list<array{name: ?string, act: Closure(): void}>
	 */
	private array $waiting = [];


//  constructor

	public function __construct(
		private readonly ITimeFactory $time,
	) {
	}


//  other non-static methods

	/**
	 * A deletion handed over: done at once where its name is free this
	 * second, otherwise at the back, behind what is waiting already.
	 *
	 * @param  string|null      $name  The name the trash takes it under;
	 *                                 null where it does not go there.
	 * @param  Closure(): void  $act
	 */
	public function add(
		?string $name,
		Closure $act,
	): void
	{
		$this->waiting[] = [
			'name' => $name,
			'act'  => $act,
		];

		$this->workOff();
	}

	/**
	 * What may go now, in order, done; with $all, the clock waited on until
	 * nothing is left.
	 */
	public function workOff( bool $all = false ): void
	{
		do
		{
			$left = [];

			foreach ( $this->waiting as $deletion )
			{
				$name = $deletion['name'];

				if ( $name !== null && ( $this->taken[ $name ] ?? PHP_INT_MIN ) >= $this->time->getTime() )
				{
					$left[] = $deletion;

					continue;
				}

				( $deletion['act'] )();

				// After, not before: the trash takes its second when it
				// starts, which is this one or the one before.
				if ( $name !== null )
				{
					$this->taken[ $name ] = $this->time->getTime();
				}
			}

			$this->waiting = $left;

			if ( $all && $left !== [] )
			{
				$this->pause();
			}
		}
		while ( $all && $this->waiting !== [] );
	}

	/** A moment for the clock to move on. */
	protected function pause(): void
	{
		usleep( self::PAUSE );
	}
}
