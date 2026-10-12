<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Server;

/**
 * The one writer of the index rows this app keeps itself
 * ({@see MetadataService::setSelfIndexed()}): each half written as given,
 * left as it is, or NULL; one row per file and key; the string cut to the
 * column's width. Against the real table, under a file id no file has.
 */
class SelfIndexedRowTest
    extends
    DatabaseTestCase
{

//  constants

	private const KEY = MetadataService::KEY_FILE_CHECKSUM_PREFIX . 'test-row';


//  private properties

	private MetadataService $metadataService;

	private int $fileId;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->metadataService = Server::get( MetadataService::class );

		// Above every file the filecache holds, so no file's rows are touched.
		$this->fileId = (int) $this->getRawConnection()
		                           ->fetchOne( 'SELECT COALESCE(MAX(`fileid`), 0) FROM `*PREFIX*filecache`' )
		                + 1_000_000;
	}


//  other non-static methods

	protected function tearDown(): void
	{
		$this->getRawConnection()
		     ->executeStatement( 'DELETE FROM `*PREFIX*files_metadata_index` WHERE `file_id` = ?', [ $this->fileId ] )
		;

		parent::tearDown();
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testARowIsWrittenWithItsStringCutToTheColumn(): void
	{
		$long = str_repeat( 'a', 64 );

		$this->assertTrue( $this->metadataService->setSelfIndexed( $this->fileId, self::KEY, $long, 7 ) );

		$this->assertSame(
			[ [ substr( $long, 0, MetadataService::META_VALUE_STRING_MAX_LENGTH ), 7 ] ],
			$this->rows(),
		);
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testUnchangedLeavesAHalfAsItIs(): void
	{
		$this->metadataService->setSelfIndexed( $this->fileId, self::KEY, 'etag', 100 );

		$this->metadataService->setSelfIndexed( $this->fileId, self::KEY, int: 200 );
		$this->assertSame( [ [ 'etag', 200 ] ], $this->rows(), 'The string stays.' );

		$this->metadataService->setSelfIndexed( $this->fileId, self::KEY, 'other' );
		$this->assertSame( [ [ 'other', 200 ] ], $this->rows(), 'The int stays.' );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testNullWritesNull(): void
	{
		$this->metadataService->setSelfIndexed( $this->fileId, self::KEY, 'etag', 100 );
		$this->metadataService->setSelfIndexed( $this->fileId, self::KEY, null, null );

		$this->assertSame( [ [ null, null ] ], $this->rows() );
	}

	/**
	 * A half left unchanged on a row that does not exist yet is NULL.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAnUnchangedHalfOfANewRowIsNull(): void
	{
		$this->metadataService->setSelfIndexed( $this->fileId, self::KEY, int: 5 );

		$this->assertSame( [ [ null, 5 ] ], $this->rows() );
	}

	/**
	 * Nothing to write is nothing written: the row keeps its id, and the call
	 * says so.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTheSameValuesWriteNothing(): void
	{
		$this->metadataService->setSelfIndexed( $this->fileId, self::KEY, 'etag', 100 );
		$id = $this->ids();

		$this->assertFalse( $this->metadataService->setSelfIndexed( $this->fileId, self::KEY, 'etag', 100 ) );
		$this->assertSame( $id, $this->ids() );
	}

	/**
	 * Two writers racing leave two rows; the next write leaves one, the
	 * older, with the values written.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testASecondRowOfTheKeyGoes(): void
	{
		foreach ( [ 'first', 'second' ] as $value )
		{
			$this->getRawConnection()
			     ->executeStatement(
				     'INSERT INTO `*PREFIX*files_metadata_index` (`file_id`, `meta_key`, `meta_value_string`, `meta_value_int`) VALUES (?, ?, ?, ?)',
				     [ $this->fileId, self::KEY, $value, 1 ],
			     )
			;
		}

		$older = $this->ids()[0];

		$this->assertTrue( $this->metadataService->setSelfIndexed( $this->fileId, self::KEY, int: 2 ) );
		$this->assertSame( [ [ 'first', 2 ] ], $this->rows() );
		$this->assertSame( [ $older ], $this->ids() );
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testUnsetRemovesTheRows(): void
	{
		$this->metadataService->setSelfIndexed( $this->fileId, self::KEY, 'etag', 100 );

		$this->assertSame( 1, $this->metadataService->unsetSelfIndexed( $this->fileId, self::KEY ) );
		$this->assertSame( [], $this->rows() );
		$this->assertSame( 0, $this->metadataService->unsetSelfIndexed( $this->fileId, self::KEY ) );
	}

	/**
	 * A hash row's int is when its value was written, which `since` reads:
	 * it moves when the value does, and only then.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAHashRowKeepsItsTimeUntilItsValueChanges(): void
	{
		$key = MetadataService::getHashKey( 'sha1' );

		$this->metadataService->syncHashIndex( $this->fileId, [ 'sha1' => str_repeat( 'a', 40 ) ] );
		$this->getRawConnection()
		     ->executeStatement(
			     'UPDATE `*PREFIX*files_metadata_index` SET `meta_value_int` = 1 WHERE `file_id` = ? AND `meta_key` = ?',
			     [ $this->fileId, $key ],
		     )
		;

		$this->metadataService->syncHashIndex( $this->fileId, [ 'sha1' => str_repeat( 'a', 40 ) ] );
		$this->assertSame( [ [ str_repeat( 'a', 40 ), 1 ] ], $this->rows( $key ), 'The same value keeps its time.' );

		$this->metadataService->syncHashIndex( $this->fileId, [ 'sha1' => str_repeat( 'b', 40 ) ] );
		$rows = $this->rows( $key );
		$this->assertSame( str_repeat( 'b', 40 ), $rows[0][0] );
		$this->assertGreaterThan( 1, $rows[0][1], 'A new value moves it.' );

		$this->metadataService->syncHashIndex( $this->fileId, [] );
		$this->assertSame( [], $this->rows( $key ), 'A hash the document no longer holds loses its row.' );
	}

	/**
	 * The file's rows of the key, as [string, int], oldest first.
	 *
	 * @return list<array{0: ?string, 1: ?int}>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function rows( string $key = self::KEY ): array
	{
		return array_map(
			static fn( array $row ): array => [
				$row['meta_value_string'] === null ? null : (string) $row['meta_value_string'],
				$row['meta_value_int'] === null ? null : (int) $row['meta_value_int'],
			],
			$this->getRawConnection()
			     ->fetchAllAssociative(
				     'SELECT `meta_value_string`, `meta_value_int` FROM `*PREFIX*files_metadata_index` WHERE `file_id` = ? AND `meta_key` = ? ORDER BY `id`',
				     [ $this->fileId, $key ],
			     ),
		);
	}

	/**
	 * @return list<int>
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	private function ids(): array
	{
		return array_map(
			intval( ... ),
			$this->getRawConnection()
			     ->fetchFirstColumn(
				     'SELECT `id` FROM `*PREFIX*files_metadata_index` WHERE `file_id` = ? AND `meta_key` = ? ORDER BY `id`',
				     [ $this->fileId, self::KEY ],
			     ),
		);
	}
}
