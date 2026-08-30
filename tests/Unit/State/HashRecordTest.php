<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\State;

use OCA\FileChecksumSearch\State\HashRecord;
use PHPUnit\Framework\TestCase;

/**
 * The record every format reads into and writes out of.
 */
class HashRecordTest
	extends
	TestCase
{

	public function testItSurvivesTheArrayFormAndBack(): void
	{

		$record = new HashRecord( 'home::alice', 'files/a.txt', 'sha256', 'abc', 5 );

		$this->assertEquals( $record, HashRecord::fromArray( $record->toArray() ) );
	}


	public function testAlgorithmsAndHashesAreLowercased(): void
	{

		// A hash read from a sumfile written on another system is the same
		// hash whatever case it arrived in; comparing them must not depend
		// on which tool wrote it.
		$record = HashRecord::fromArray(
			[
				'path' => 'files/a.txt',
				'algo' => 'SHA256',
				'hash' => 'ABCDEF',
			],
		);

		$this->assertSame( 'sha256', $record->algo );
		$this->assertSame( 'abcdef', $record->hash );
	}


	public function testAnAbsentTimestampStaysAbsent(): void
	{

		// Not zero: the epoch is a claim, and one that would make the file
		// look hopelessly outdated rather than unstamped.
		foreach (
			[
				[],
				[ 'updated_at' => null ],
				[ 'updated_at' => '' ],
			] as $row
		)
		{
			$this->assertNull( HashRecord::fromArray( $row + [ 'path' => 'a' ] )->updatedAt );
		}

		$this->assertSame( 0, HashRecord::fromArray( [ 'updated_at' => '0' ] )->updatedAt );
	}


	/**
	 * @dataProvider incompleteRows
	 */
	public function testARecordMissingAnyOfPathAlgoOrHashSaysSo( array $row ): void
	{

		$this->assertFalse(
			HashRecord::fromArray( $row )
			          ->isComplete(),
		);
	}


	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function incompleteRows(): array
	{

		return [
			'no path' => [
				[
					'algo' => 'sha256',
					'hash' => 'abc',
				],
			],
			'no algo' => [
				[
					'path' => 'a',
					'hash' => 'abc',
				],
			],
			'no hash' => [
				[
					'path' => 'a',
					'algo' => 'sha256',
				],
			],
			'nothing' => [ [] ],
		];
	}


	public function testARecordWithoutAStorageIsStillComplete(): void
	{

		// The storage comes from the anchor for a sumfile, and the record is
		// complete before it is anchored.
		$this->assertTrue(
			HashRecord::fromArray(
				[
					'path' => 'a',
					'algo' => 'sha256',
					'hash' => 'abc',
				],
			)
			          ->isComplete(),
		);
	}

}
