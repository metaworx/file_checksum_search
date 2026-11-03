<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\TrashQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The order deletions go in over a clock that moves only when the queue
 * waits for it: a name the trash took this second waits at the back, the
 * others go, and the clock is waited on only when nothing else is left.
 */
class TrashQueueTest
    extends
    TestCase
{

//  private properties

	private int $now = 1_759_300_000;

	private int $pauses = 0;

	/** @var list<string> */
	private array $done = [];

	private TrashQueue $queue;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$time = $this->createMock( ITimeFactory::class );
		$time->method( 'getTime' )
		     ->willReturnCallback( fn (): int => $this->now )
		;

		$this->queue = new class( $time, function(): void
		{
			$this->pauses ++;
			$this->now ++;
		} )
		    extends
		    TrashQueue
		{
			public function __construct(
				ITimeFactory              $time,
				private readonly \Closure $onPause,
			)
			{
				parent::__construct( $time );
			}

			protected function pause(): void
			{
				( $this->onPause )();
			}
		};
	}


//  other non-static methods

	/**
	 * $label done, under $name.
	 */
	private function add(
		?string $name,
		string  $label,
	): void
	{
		$this->queue->add( $name, function() use ( $label ): void
		{
			$this->done[] = $label;
		} );
	}

	public function testAFreeNameGoesAtOnce(): void
	{
		$this->add( 'a.txt', 'first a' );
		$this->add( 'b.txt', 'b' );

		$this->assertSame( [ 'first a', 'b' ], $this->done );
	}

	/** D10-A: the second of a name waits at the back while the others go. */
	public function testANameTakenThisSecondWaitsWhileTheOthersGo(): void
	{
		$this->add( 'a.txt', 'first a' );
		$this->add( 'a.txt', 'second a' );
		$this->add( 'b.txt', 'b' );
		$this->add( null, 'a version' );

		$this->assertSame( [ 'first a', 'b', 'a version' ], $this->done );
		$this->assertSame( 0, $this->pauses, 'nobody waited yet' );

		$this->queue->workOff( true );

		$this->assertSame( [ 'first a', 'b', 'a version', 'second a' ], $this->done );
		$this->assertSame( 1, $this->pauses, 'the clock waited on once, when only the second a was left' );
	}

	/** A deletion handed over later finds the waiting one free once the clock moved. */
	public function testWhatWaitsGoesAsSoonAsTheSecondHasPassed(): void
	{
		$this->add( 'a.txt', 'first a' );
		$this->add( 'a.txt', 'second a' );

		$this->now ++;
		$this->add( 'b.txt', 'b' );

		$this->assertSame( [ 'first a', 'second a', 'b' ], $this->done, 'in their order, the clock having moved' );
		$this->assertSame( 0, $this->pauses );
	}

	public function testThreeOfOneNameGoASecondApart(): void
	{
		foreach ( [ 1, 2, 3 ] as $n )
		{
			$this->add( 'a.txt', "a $n" );
		}

		$this->queue->workOff( true );

		$this->assertSame( [ 'a 1', 'a 2', 'a 3' ], $this->done );
		$this->assertSame( 2, $this->pauses );
	}

	/** The names taken outlive a run: a call following in the same second waits too. */
	public function testANameTakenByAnEarlierRunWaitsToo(): void
	{
		$this->add( 'a.txt', 'first a' );
		$this->queue->workOff( true );

		$this->add( 'a.txt', 'second a' );

		$this->assertSame( [ 'first a' ], $this->done );

		$this->queue->workOff( true );

		$this->assertSame( [ 'first a', 'second a' ], $this->done );
	}

	public function testWhatGoesForGoodNeverWaits(): void
	{
		$this->add( null, 'one' );
		$this->add( null, 'two' );
		$this->queue->workOff( true );

		$this->assertSame( [ 'one', 'two' ], $this->done );
		$this->assertSame( 0, $this->pauses );
	}
}
