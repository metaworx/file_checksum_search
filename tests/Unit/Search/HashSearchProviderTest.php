<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Search;

use OCA\FileChecksumSearch\Search\HashSearchProvider;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Tests\Unit\EnglishL10n;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDateTimeFormatter;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HashSearchProviderTest
    extends
    TestCase
{
	use EnglishL10n;


//  private properties

	private HashSearchProvider $provider;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->provider = new HashSearchProvider(
			$this->createMock( MetadataService::class ),
			$this->createMock( IRootFolder::class ),
			$this->createMock( \OCA\FileChecksumSearch\Service\ReachResolver::class ),
			$this->createMock( IURLGenerator::class ),
			$this->createMock( LoggerInterface::class ),
			$this->englishL10n(),
			$this->createMock( HashIndexService::class ),
			$this->createMock( IDateTimeFormatter::class ),
		);
	}


//  other non-static methods

	public function testAFilesTitleIsItsName(): void
	{
		$entry = $this->foundOne( null );

		$this->assertSame( 'a.txt', $entry['title'] );
	}

	/**
	 * A version is offered as its file, its date beside the name, where the
	 * search's list does not cut it off, in the account's time zone.
	 */
	public function testAVersionsTitleIsItsFilesNameAndItsDate(): void
	{
		$entry = $this->foundOne( '2023-11-14T22:13:20+00:00' );

		$this->assertSame( 'a.txt (version of Nov 14, 2023, 11:13:20 PM)', $entry['title'] );
		$this->assertStringNotContainsString( 'version', $entry['subline'] );
	}

	public function testGetIdReturnsProviderId(): void
	{
		$this->assertSame(
			'file_checksum_search_provider',
			$this->provider->getId(),
		);
	}

	public function testGetNameReturnsProviderName(): void
	{
		$this->assertSame(
			'File checksums',
			$this->provider->getName(),
		);
	}

	public function testGetOrderReturnsInt(): void
	{
		$this->assertSame(
			20,
			$this->provider->getOrder( '', [] ),
		);
	}

	/**
	 * Each surviving row costs a getById(), so the search caps the limit
	 * whatever core hands it: a search for a common hash with a huge limit
	 * would otherwise spend thousands of queries.
	 */
	public function testSearchCapsTheLimitCoreAsksFor(): void
	{
		$metadata = $this->createMock( MetadataService::class );

		// The searching account, whose mounts are one storage.
		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )->willReturn( 'u1' );
		$reach = $this->createMock( \OCA\FileChecksumSearch\Service\ReachResolver::class );
		$reach->method( 'storageIdsFor' )->with( 'u1' )->willReturn( [ 1 ] );

		$metadata->expects( $this->once() )
		         ->method( 'queryByHash' )
		         ->with( $this->anything(), $this->anything(), 100, [ 1 ] )
		         ->willReturn( [] )
		;
		$metadata->method( 'confirmFullHash' )->willReturn( [] );

		$provider = new HashSearchProvider(
			$metadata,
			$this->createMock( IRootFolder::class ),
			$reach,
			$this->createMock( IURLGenerator::class ),
			$this->createMock( LoggerInterface::class ),
			$this->englishL10n(),
			$this->createMock( HashIndexService::class ),
			$this->createMock( IDateTimeFormatter::class ),
		);

		$query = $this->createMock( ISearchQuery::class );
		$query->method( 'getTerm' )->willReturn( 'abc123abc123abc123abc123abc123abc123abc1' );
		$query->method( 'getLimit' )->willReturn( 100000 );

		$provider->search( $user, $query );
	}

	/**
	 * The one entry a search finds: row 8, `/Docs/a.txt` in u1's files, or,
	 * given a timestamp, a version of that file, file 7.
	 *
	 * @return array<string, mixed>  the entry, serialised
	 */
	private function foundOne( ?string $versionTimestamp ): array
	{
		$hash = 'aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d';
		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )->willReturn( 'u1' );

		$reach = $this->createMock( \OCA\FileChecksumSearch\Service\ReachResolver::class );
		$reach->method( 'storageIdsFor' )->willReturn( [ 1 ] );

		$row      = [ MetadataService::FIELD_FILE_ID => 8 ];
		$metadata = $this->createMock( MetadataService::class );
		$metadata->method( 'queryByHash' )->willReturn( [ $row ] );
		$metadata->method( 'confirmFullHash' )->willReturn( [ $row ] );
		$metadata->method( 'extractAlgorithm' )->willReturn( [ 'hash' => $hash, 'algo' => 'sha1' ] );

		$hashIndex = $this->createMock( HashIndexService::class );
		$hashIndex->method( 'versionsAmong' )
		          ->with( [ 8 ] )
		          ->willReturn(
			          $versionTimestamp === null
				          ? []
				          : [
				          8 => [
					          'fileid'    => 7,
					          'timestamp' => $versionTimestamp,
				          ],
			          ],
		          )
		;

		$node = $this->createMock( File::class );
		$node->method( 'getName' )->willReturn( 'a.txt' );
		$node->method( 'getPath' )->willReturn( '/u1/files/Docs/a.txt' );

		$userFolder = $this->createMock( Folder::class );
		$userFolder->method( 'getById' )
		           ->with( $versionTimestamp === null ? 8 : 7 )
		           ->willReturn( [ $node ] )
		;
		$userFolder->method( 'getRelativePath' )->willReturn( '/Docs/a.txt' );

		$rootFolder = $this->createMock( IRootFolder::class );
		$rootFolder->method( 'getUserFolder' )->willReturn( $userFolder );

		// The account's own time zone, an hour east of the server's.
		$formatter = $this->createMock( IDateTimeFormatter::class );
		$formatter->method( 'formatDateTime' )
		          ->with( 1700000000, 'medium', 'medium' )
		          ->willReturn( 'Nov 14, 2023, 11:13:20 PM' )
		;

		$provider = new HashSearchProvider(
			$metadata,
			$rootFolder,
			$reach,
			$this->createMock( IURLGenerator::class ),
			$this->createMock( LoggerInterface::class ),
			$this->englishL10n(),
			$hashIndex,
			$formatter,
		);

		$query = $this->createMock( ISearchQuery::class );
		$query->method( 'getTerm' )->willReturn( $hash );
		$query->method( 'getLimit' )->willReturn( 5 );

		$entries = $provider->search( $user, $query )->jsonSerialize()['entries'];
		$this->assertCount( 1, $entries );

		return $entries[0]->jsonSerialize();
	}
}
