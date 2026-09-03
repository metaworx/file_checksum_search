<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Public;

use InvalidArgumentException;
use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCA\FileChecksumSearch\Service\AlgorithmCatalogue;
use OCA\FileChecksumSearch\Service\DatabaseService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\PermissionService;
use OCA\FileChecksumSearch\Service\RuleDefinitionValidator;
use OCP\IGroupManager;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Service\StatusService;
use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChecksumApiTest
	extends
	TestCase
{

// private properties
	private MockObject|HashIndexService $hashIndexService;

	private MockObject|MetadataService  $metadataService;

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private StatusService                $statusService;

	private MockObject|IRootFolder       $rootFolder;

	private MockObject|IUserMountCache   $userMountCache;

	private MockObject|IUserManager      $userManager;

	private MockObject|IUserSession      $userSession;

	private MockObject|RuleService       $ruleService;

	private MockObject|PermissionService $permissionService;

	private MockObject|IGroupManager     $groupManager;

	private ChecksumApi                  $api;


	protected function setUp(): void
	{

		parent::setUp();

		$this->hashIndexService = $this->createMock( HashIndexService::class );
		$this->metadataService  = $this->createMock( MetadataService::class );

		// StatusService is readonly — cannot be mocked by PHPUnit 10.5.
		// Construct a real instance with mocked collaborators.
		$this->statusService = new StatusService(
			$this->createMock( DatabaseService::class ),
			$this->createMock( TableNameService::class ),
			$this->createMock( IAppManager::class ),
			$this->createMock( MetadataService::class ),
		);

		$this->rootFolder     = $this->createMock( IRootFolder::class );
		$this->userMountCache = $this->createMock( IUserMountCache::class );
		$this->userSession    = $this->createMock( IUserSession::class );

		$this->ruleService       = $this->createMock( RuleService::class );
		$this->permissionService = $this->createMock( PermissionService::class );
		$this->groupManager      = $this->createMock( IGroupManager::class );

		$this->userManager = $this->createMock( IUserManager::class );
		$this->userManager->method( 'userExists' )
		                  ->willReturn( true )
		;
		$userManager = $this->userManager;
		$this->groupManager->method( 'groupExists' )
		                   ->willReturn( true )
		;

		$this->api = new ChecksumApi(
			$this->hashIndexService,
			$this->metadataService,
			$this->statusService,
			$this->rootFolder,
			$this->userSession,
			$this->ruleService,
			new RuleDefinitionValidator( $this->groupManager, $userManager, new AlgorithmCatalogue( $this->createMock( IAppConfig::class ) ) ),
			$this->permissionService,
			$this->groupManager,
			new AlgorithmCatalogue( $this->createMock( IAppConfig::class ) ),
			$this->createMock( IUserConfig::class ),
			$this->userMountCache,
			$this->userManager,
		);
	}


	// ─── findByHash ─────────────────────────────────────────────────

	public function testFindByHashClampsLimitTo500(): void
	{

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'findByHash' )
		                       ->with( 'abc', null, 500, null )
		                       ->willReturn( [] )
		;

		$this->api->findByHash( 'abc', null, 999 );
	}


	public function testFindByHashPassesAlgoFilter(): void
	{

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'findByHash' )
		                       ->with( 'abc', 'md5', 100, null )
		                       ->willReturn( [] )
		;

		$result = $this->api->findByHash( 'abc', 'md5' );

		$this->assertEmpty( $result['results'] );
	}


	public function testFindByHashReturnsResults(): void
	{

		$rows = [
			[
				'fileid'     => '42',
				'algo'       => 'sha1',
				'hash_value' => 'abc123',
				'path'       => 'Docs',
				'name'       => 'report.pdf',
			],
		];

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'findByHash' )
		                       ->with( 'abc123', null, 100, null )
		                       ->willReturn( $rows )
		;

		$result = $this->api->findByHash( 'abc123' );

		$this->assertArrayHasKey( 'results', $result );
		$this->assertCount( 1, $result['results'] );
		$this->assertSame( 42, $result['results'][0]['fileid'] );
		$this->assertSame( 'sha1', $result['results'][0]['algo'] );
		$this->assertSame( 'abc123', $result['results'][0]['hash'] );
	}


	/**
	 * A scoped lookup — one account, as every non-sudo call is — spends its
	 * limit on that account's mounts and lets getById() decide visibility:
	 * a row the user cannot open is dropped, not counted, so a share or a
	 * group-folder copy is found and a foreign copy never hides an own file.
	 */
	public function testFindByHashScopedResolvesThroughGetByIdAndDropsWhatTheUserCannotOpen(): void
	{

		$this->userManager->method( 'get' )
		                  ->with( 'bob' )
		                  ->willReturn( $this->createMock( IUser::class ) )
		;

		$mount = $this->createMock( \OCP\Files\Config\ICachedMountInfo::class );
		$mount->method( 'getStorageId' )
		      ->willReturn( 42 )
		;
		$this->userMountCache->method( 'getMountsForUser' )
		                     ->willReturn( [ $mount ] )
		;

		$rows = [
			[ MetadataService::FIELD_FILE_ID => 7, MetadataService::FIELD_META_KEY => 'file-checksum-sha1' ],
			[ MetadataService::FIELD_FILE_ID => 8, MetadataService::FIELD_META_KEY => 'file-checksum-sha1' ],
		];

		$this->metadataService->expects( $this->once() )
		                      ->method( 'queryByHash' )
		                      ->with( 'abc', null, 100, [ 42 ] )
		                      ->willReturn( $rows )
		;
		$this->metadataService->method( 'confirmFullHash' )
		                      ->willReturn( $rows )
		;

		$userFolder = $this->createMock( Folder::class );
		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'bob' )
		                 ->willReturn( $userFolder )
		;

		$mine = $this->createMock( \OCP\Files\Node::class );
		$mine->method( 'getPath' )->willReturn( '/bob/files/Docs/report.pdf' );
		$mine->method( 'getName' )->willReturn( 'report.pdf' );

		// File 7 is the user's; file 8 is a foreign copy of the same hash —
		// getById() answers empty for it, and it never reaches the results.
		$userFolder->method( 'getById' )
		           ->willReturnMap( [
			           [ 7, [ $mine ] ],
			           [ 8, [] ],
		           ] );
		$userFolder->method( 'getRelativePath' )
		           ->with( '/bob/files/Docs/report.pdf' )
		           ->willReturn( 'Docs/report.pdf' )
		;
		$this->metadataService->method( 'extractAlgorithm' )
		                      ->willReturn( [ 'algo' => 'sha1', 'hash' => 'abc' ] )
		;

		$result = $this->api->findByHash( 'abc', null, 100, 'bob' );

		$this->assertCount( 1, $result['results'] );
		$this->assertSame( 7, $result['results'][0]['fileid'] );
		$this->assertSame( 'Docs/report.pdf', $result['results'][0]['path'] );
	}


	public function testFindByHashThrowsOnEmptyHash(): void
	{

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Hash parameter is required.' );

		$this->api->findByHash( '' );
	}


	public function testFindByHashTrimsWhitespace(): void
	{

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'findByHash' )
		                       ->with( 'abc123', null, 100, null )
		                       ->willReturn( [] )
		;

		$result = $this->api->findByHash( '  abc123  ' );

		$this->assertEmpty( $result['results'] );
	}


	/**
	 * A scoped lookup resolves visibility through the account, so an account
	 * that no longer exists reads nothing — and never falls through to the
	 * instance-wide path, which would answer for everyone.
	 */
	public function testFindByHashScopedToAGoneAccountReadsNothing(): void
	{

		$this->userManager->method( 'get' )
		                  ->with( 'ghost' )
		                  ->willReturn( null )
		;
		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'findByHash' )
		;

		$this->assertSame( [ 'results' => [] ], $this->api->findByHash( 'abc123', null, 100, 'ghost' ) );
	}


	// ─── findDuplicates ─────────────────────────────────────────────

	public function testFindDuplicatesClampsLimit(): void
	{

		$this->signedInAs( 'bob' );

		// A caller asking for 999 gets 500, and the clamped value is what the
		// listing is built with — not just what the response reports.
		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'listDuplicatesForUser' )
		                       ->with( 'bob', null, 2, 500, 0 )
		                       ->willReturn( $this->emptyListing( 500 ) )
		;

		$result = $this->api->findDuplicates( null, 2, 999 );

		$this->assertSame( 500, $result['pagination']['limit'] );
	}


	public function testFindDuplicatesRespectsAlgoAndMinCount(): void
	{

		$this->signedInAs( 'bob' );

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'listDuplicatesForUser' )
		                       ->with( 'bob', 'sha256', 3, 50, 0 )
		                       ->willReturn( $this->emptyListing() )
		;

		$result = $this->api->findDuplicates( 'sha256', 3 );

		$this->assertEmpty( $result['duplicates'] );
	}


	public function testFindDuplicatesReturnsEmptyWhenNoUser(): void
	{

		$this->userSession->method( 'getUser' )
		                  ->willReturn( null )
		;

		// Nobody to filter to, so nothing is queried at all.
		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'listDuplicatesForUser' )
		;

		$result = $this->api->findDuplicates();

		$this->assertEmpty( $result['duplicates'] );
		$this->assertSame( 0, $result['total_groups'] );
	}


	/**
	 * The grouping itself lives in HashIndexService and is tested there; this
	 * checks the API hands back what it was given, for the signed-in user.
	 */
	public function testFindDuplicatesReturnsTheListingUntouched(): void
	{

		$this->signedInAs( 'bob' );

		$listing = [
			'duplicates'   => [
				[
					'algo'       => 'sha1',
					'hash_value' => 'abc',
					'file_count' => 2,
					'files'      => [
						[
							'fileid' => 42,
							'path'   => 'files/a.pdf',
							'name'   => 'a.pdf',
						],
						[
							'fileid' => 108,
							'path'   => 'files/b.pdf',
							'name'   => 'b.pdf',
						],
					],
				],
			],
			'total_groups' => 1,
			'pagination'   => [
				'offset' => 0,
				'limit'  => 50,
			],
		];

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'listDuplicatesForUser' )
		                       ->with( 'bob', null, 2, 50, 0 )
		                       ->willReturn( $listing )
		;

		$this->assertSame( $listing, $this->api->findDuplicates() );
	}


	/**
	 * @noinspection PhpSameParameterValueInspection
	 */
	private function signedInAs( string $uid ): void
	{

		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( $uid )
		;
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;
	}


	/**
	 * @return array{duplicates: array, total_groups: int, pagination: array{offset: int, limit: int}}
	 */
	private function emptyListing( int $limit = 50 ): array
	{

		return [
			'duplicates'   => [],
			'total_groups' => 0,
			'pagination'   => [
				'offset' => 0,
				'limit'  => $limit,
			],
		];
	}


	// ─── getHashesByFile ────────────────────────────────────────────

	public function testGetHashesByFileDelegatesToMetadataService(): void
	{

		$file = $this->createMock( File::class );
		$file->expects( $this->once() )
		     ->method( 'getId' )
		     ->willReturn( 42 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [ 'sha1' => 'abc' ] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( null )
		;

		$data = $this->api->getHashesByFile( $file );

		$this->assertSame( 42, $data['fileid'] );
		$this->assertCount( 1, $data['hashes'] );
	}


	// ─── getHashesByFileId ──────────────────────────────────────────


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testGetHashesByFileIdReturnsEmptyForUnknownFile(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 99999 )
		                      ->willReturn( [] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'getUpdatedAt' )
		                      ->with( 99999 )
		                      ->willReturn( null )
		;

		$data = $this->api->getHashesByFileId( 99999 );

		$this->assertSame( 99999, $data['fileid'] );
		$this->assertEmpty( $data['hashes'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testGetHashesByFileIdReturnsHashes(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [
			                      'sha1'   => 'abc',
			                      'sha256' => 'def',
		                      ] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( 1234567890 )
		;

		$data = $this->api->getHashesByFileId( 42 );

		$this->assertSame( 42, $data['fileid'] );
		$this->assertCount( 2, $data['hashes'] );
		$this->assertSame( 'sha1', $data['hashes'][0]['algo'] );
		$this->assertSame( 'abc', $data['hashes'][0]['hash'] );
		$this->assertNotNull( $data['hashes'][0]['updated_at'] );

		// What the sidebar composes its quick buttons from. No rule governs the
		// mocked file and no preference is stored, so: nothing from a rule, the
		// instance default, and no preference.
		$this->assertSame( [], $data['algos'] );
		$this->assertSame( 'sha1', $data['default'] );
		$this->assertSame( '', $data['preferred'] );
	}


	public function testGetHashesByFileIdThrowsWhenRequestingUserCannotAccessFile(): void
	{

		// Regression test for FCIAS Review §6, Finding 1.
		$userFolder = $this->createMock( Folder::class );

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $userFolder )
		;
		$userFolder->method( 'getById' )
		           ->with( 42 )
		           ->willReturn( [] )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'getHashes' )
		;

		$this->expectException( NotFoundException::class );

		$this->api->getHashesByFileId( 42, 'alice' );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testGetHashesByFileIdAllowsRequestingUserWithAccess(): void
	{

		$userFolder = $this->createMock( Folder::class );
		$node       = $this->createMock( File::class );

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $userFolder )
		;
		$userFolder->method( 'getById' )
		           ->with( 42 )
		           ->willReturn( [ $node ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [ 'sha1' => 'abc' ] )
		;
		$this->metadataService->method( 'getUpdatedAt' )
		                      ->willReturn( null )
		;

		$data = $this->api->getHashesByFileId( 42, 'alice' );

		$this->assertSame( 42, $data['fileid'] );
	}


	// ─── getHashesByPath ────────────────────────────────────────────

	public function testGetHashesByPathThrowsOnNonFile(): void
	{

		$folder = $this->createMock( Folder::class );

		$this->rootFolder->method( 'get' )
		                 ->willReturn( $folder )
		;

		$this->expectException( NotFoundException::class );
		$this->expectExceptionMessage( 'Path does not resolve to a file' );

		$this->api->getHashesByPath( '/some/folder' );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testGetHashesByPathWithUserResolvesRelativePath(): void
	{

		$file       = $this->createMock( File::class );
		$userFolder = $this->createMock( Folder::class );

		$this->rootFolder->expects( $this->once() )
		                 ->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $userFolder )
		;

		$userFolder->expects( $this->once() )
		           ->method( 'get' )
		           ->with( 'Documents/report.pdf' )
		           ->willReturn( $file )
		;

		$file->expects( $this->once() )
		     ->method( 'getId' )
		     ->willReturn( 42 )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( null )
		;

		$data = $this->api->getHashesByPath( 'Documents/report.pdf', 'alice' );

		$this->assertSame( 42, $data['fileid'] );
		$this->assertSame( 'Documents/report.pdf', $data['path'] );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testGetHashesByPathWithoutUserResolvesAbsolutePath(): void
	{

		$file = $this->createMock( File::class );

		$this->rootFolder->expects( $this->once() )
		                 ->method( 'get' )
		                 ->with( '/alice/files/Docs/x.pdf' )
		                 ->willReturn( $file )
		;

		$file->method( 'getId' )
		     ->willReturn( 42 )
		;
		$file->method( 'getPath' )
		     ->willReturn( '/alice/files/Docs/x.pdf' )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [] )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'getUpdatedAt' )
		                      ->with( 42 )
		                      ->willReturn( null )
		;

		$data = $this->api->getHashesByPath( '/alice/files/Docs/x.pdf' );

		$this->assertSame( 42, $data['fileid'] );
		$this->assertSame( '/alice/files/Docs/x.pdf', $data['path'] );
	}


	// ─── findSameHash ───────────────────────────────────────────────

	/**
	 * The reference file is checked before its hashes are read. Without
	 * this, sweeping file ids answers "does that file hold something I also
	 * hold" for every file on the instance.
	 */
	public function testFindSameHashThrowsWhenRequestingUserCannotAccessTheReferenceFile(): void
	{

		$userFolder = $this->createMock( Folder::class );

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $userFolder )
		;
		$userFolder->method( 'getById' )
		           ->with( 42 )
		           ->willReturn( [] )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'getHashes' )
		;

		$this->expectException( NotFoundException::class );

		$this->api->findSameHash( 42, 'alice' );
	}


	public function testFindSameHashReturnsEmptyWhenNoHashes(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [] )
		;

		$data = $this->api->findSameHash( 42 );

		$this->assertEmpty( $data['duplicates'] );
	}


	public function testFindSameHashReturnsEmptyWhenNoDuplicates(): void
	{

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [ 'sha1' => 'abc' ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'queryByHash' )
		                      ->with( 'abc', 'sha1', 100 )
		                      ->willReturn( [
			                      [
				                      MetadataService::FIELD_FILE_ID    => 42,
				                      MetadataService::FIELD_META_KEY   => 'file-checksum-sha1',
				                      MetadataService::FIELD_JSON_ALIAS => '{}',
			                      ],
		                      ] )
		;

		// Only the reference file itself was found (filtered out), so empty result
		$data = $this->api->findSameHash( 42 );

		$this->assertEmpty( $data['duplicates'] );
	}


	public function testFindSameHashReturnsGroupedDuplicates(): void
	{

		$this->metadataService->method( 'confirmFullHash' )
		                      ->willReturnCallback(
			                      static fn(
				                      array $rows,
			                      ): array => $rows,
		                      )
		;

		$user       = $this->createMock( IUser::class );
		$userFolder = $this->createMock( Folder::class );
		$dupNode    = $this->createMock( File::class );

		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;
		$user->method( 'getUID' )
		     ->willReturn( 'bob' )
		;

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'bob' )
		                 ->willReturn( $userFolder )
		;

		// Duplicate file found
		$userFolder->method( 'getById' )
		           ->with( 108 )
		           ->willReturn( [ $dupNode ] )
		;
		$dupNode->method( 'getPath' )
		        ->willReturn( '/bob/files/Backup/photo.jpg' )
		;
		$dupNode->method( 'getName' )
		        ->willReturn( 'photo.jpg' )
		;
		$userFolder->method( 'getRelativePath' )
		           ->with( '/bob/files/Backup/photo.jpg' )
		           ->willReturn( 'Backup/photo.jpg' )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [ 'sha1' => 'abc' ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'queryByHash' )
		                      ->with( 'abc', 'sha1', 100 )
		                      ->willReturn( [
			                      [
				                      MetadataService::FIELD_FILE_ID    => 42,
				                      MetadataService::FIELD_META_KEY   => 'file-checksum-sha1',
				                      MetadataService::FIELD_JSON_ALIAS => '{}',
			                      ],
			                      [
				                      MetadataService::FIELD_FILE_ID    => 108,
				                      MetadataService::FIELD_META_KEY   => 'file-checksum-sha1',
				                      MetadataService::FIELD_JSON_ALIAS => '{}',
			                      ],
		                      ] )
		;

		$data = $this->api->findSameHash( 42 );

		$this->assertCount( 1, $data['duplicates'] );
		$this->assertSame( 'sha1', $data['duplicates'][0]['algo'] );
		$this->assertSame( 'abc', $data['duplicates'][0]['hash_value'] );
		$this->assertCount( 1, $data['duplicates'][0]['files'] );
		$this->assertSame( 108, $data['duplicates'][0]['files'][0]['fileid'] );
		$this->assertSame( 'Backup/photo.jpg', $data['duplicates'][0]['files'][0]['path'] );
	}


	public function testFindSameHashRejectsTruncatedPrefixFalsePositive(): void
	{

		// Regression test for FCIAS Review §6, Finding 6: queryByHash()
		// matches on the truncated index value for long hashes, so a
		// candidate row may only share the truncated prefix. The full
		// authoritative hash must be verified via extractAlgorithm()
		// before the file is reported as sharing the hash.
		$fullHash = str_repeat( 'a', 128 );

		$this->metadataService->expects( $this->once() )
		                      ->method( 'getHashes' )
		                      ->with( 42 )
		                      ->willReturn( [ 'sha512' => $fullHash ] )
		;

		$this->metadataService->expects( $this->once() )
		                      ->method( 'queryByHash' )
		                      ->with( $fullHash, 'sha512' )
		                      ->willReturn( [
			                      [
				                      MetadataService::FIELD_FILE_ID    => 108,
				                      MetadataService::FIELD_META_KEY   => 'file-checksum-sha512',
				                      MetadataService::FIELD_JSON_ALIAS => '{}',
			                      ],
		                      ] )
		;

		// The confirmation itself lives in MetadataService, written once for
		// every caller; what this asserts is that the API honours it rather
		// than reporting the row anyway.
		$this->metadataService->expects( $this->once() )
		                      ->method( 'confirmFullHash' )
		                      ->willReturn( [] )
		;

		$data = $this->api->findSameHash( 42 );

		$this->assertEmpty( $data['duplicates'] );
	}


	// ─── getStatus ──────────────────────────────────────────────────

	public function testGetStatusGivesAnAdminTheWholeSnapshot(): void
	{

		$this->groupManager->method( 'isAdmin' )
		                   ->with( 'theadmin' )
		                   ->willReturn( true )
		;

		// StatusService is a real instance with mocked collaborators.
		// The collaborators return defaults (null/0), so we verify the
		// response shape rather than exact values.
		$status = $this->api->getStatus( 'theadmin' );

		$this->assertArrayHasKey( 'version', $status );
		$this->assertArrayHasKey( 'dbVersion', $status );
		$this->assertArrayHasKey( 'rowCount', $status );
		$this->assertArrayHasKey( 'pendingRows', $status );
		$this->assertIsInt( $status['rowCount'] );
		$this->assertIsInt( $status['pendingRows'] );
	}


	/**
	 * The version is a compatibility marker and harmless; the rest describes
	 * the instance and is the administrator's. A non-admin — including the
	 * anonymous caller, uid null — gets the version and nothing else.
	 */
	public function testGetStatusGivesANonAdminTheVersionAlone(): void
	{

		$this->groupManager->method( 'isAdmin' )
		                   ->willReturn( false )
		;

		$status = $this->api->getStatus( 'bob' );

		$this->assertSame( [ 'version' ], array_keys( $status ) );
	}


	// ─── recalcHash ─────────────────────────────────────────────────

	/**
	 * The manual-recalculation permission gates triggering work, on top of
	 * owning the file: an account it does not name is refused before any
	 * rule is looked up, with a reason the client can show.
	 */
	public function testRecalcHashRefusesAnAccountThePermissionDoesNotName(): void
	{

		$node       = $this->createMock( File::class );
		$userFolder = $this->createMock( Folder::class );
		$userFolder->method( 'getById' )
		           ->with( 42 )
		           ->willReturn( [ $node ] )
		;
		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $userFolder )
		;
		$this->groupManager->method( 'isAdmin' )
		                   ->with( 'alice' )
		                   ->willReturn( false )
		;
		$this->permissionService->method( 'isAllowed' )
		                        ->with( PermissionService::PERMISSION_MANUAL_RECALC, 'alice' )
		                        ->willReturn( false )
		;
		$this->ruleService->expects( $this->never() )
		                  ->method( 'findFirstMatchingRule' )
		;
		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'recalcHash' )
		;

		$result = $this->api->recalcHash( 42, null, 'alice' );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['forbidden'] );
		$this->assertArrayNotHasKey( 'excluded', $result );
	}


	public function testRecalcHashRefusesAFileAnExcludeRuleCovers(): void
	{

		$node = $this->createMock( File::class );
		$node->method( 'getPath' )
		     ->willReturn( '/files/Archive/big.iso' )
		;

		$this->rootFolder->method( 'getById' )
		                 ->with( 42 )
		                 ->willReturn( [ $node ] )
		;

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( [
			                  'id'   => 'archive',
			                  'type' => 'exclude',
		                  ] )
		;

		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'recalcHash' )
		;

		$result = $this->api->recalcHash( 42 );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['excluded'] );
		$this->assertSame( 'archive', $result['ruleId'] );
	}


	public function testRecalcHashAllowsAFileAnIgnoreRuleCovers(): void
	{

		$node = $this->createMock( File::class );
		$node->method( 'getPath' )
		     ->willReturn( '/files/Photos/a.jpg' )
		;

		$this->rootFolder->method( 'getById' )
		                 ->willReturn( [ $node ] )
		;

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn( [
			                  'id'   => 'photos',
			                  'type' => 'ignore',
		                  ] )
		;

		// `ignore` stops *automatic* hashing. Asking for one file by hand is
		// precisely the case it leaves open.
		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'recalcHash' )
		                       ->willReturn( [ 'success' => true ] )
		;

		$this->assertTrue( $this->api->recalcHash( 42 )['success'] );
	}


	public function testRecalcHashProceedsWhenTheRuleLookupFails(): void
	{

		$this->rootFolder->method( 'getById' )
		                 ->willThrowException( new \RuntimeException( 'storage unavailable' ) )
		;

		// The ownership check is the security boundary; this lookup is a
		// policy check, so a failure here must not block a recalculation that
		// would have been allowed before verdicts existed.
		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'recalcHash' )
		                       ->willReturn( [ 'success' => true ] )
		;

		$this->assertTrue( $this->api->recalcHash( 42 )['success'] );
	}


	public function testRecalcHashDelegatesToHashIndexService(): void
	{

		$expected = [
			'success' => true,
			'algo'    => 'sha256',
			'hash'    => 'def456',
			'fileid'  => 42,
		];

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'recalcHash' )
		                       ->with( 42, 'sha256' )
		                       ->willReturn( $expected )
		;

		$result = $this->api->recalcHash( 42, 'sha256' );

		$this->assertSame( $expected, $result );
	}


	public function testRecalcHashReturnsFailureResult(): void
	{

		$expected = [
			'success' => false,
			'error'   => 'File not found.',
		];

		$this->hashIndexService->method( 'recalcHash' )
		                       ->willReturn( $expected )
		;

		$result = $this->api->recalcHash( 99999 );

		$this->assertFalse( $result['success'] );
	}


	public function testRecalcHashUsesDefaultAlgoWhenNull(): void
	{

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'recalcHash' )
		                       ->with( 42, 'sha1' )
		                       ->willReturn( [ 'success' => true ] )
		;

		$this->api->recalcHash( 42 );
	}


	public function testRecalcHashReturnsFailureWhenRequestingUserCannotAccessFile(): void
	{

		// Regression test for FCIAS Review §6, Finding 1.
		$userFolder = $this->createMock( Folder::class );

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $userFolder )
		;
		$userFolder->method( 'getById' )
		           ->with( 99999 )
		           ->willReturn( [] )
		;

		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'recalcHash' )
		;

		$result = $this->api->recalcHash( 99999, 'sha1', 'alice' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'File not found.', $result['error'] );
	}


	public function testRecalcHashProceedsWhenRequestingUserHasAccess(): void
	{

		$userFolder = $this->createMock( Folder::class );
		$node       = $this->createMock( File::class );

		$this->rootFolder->method( 'getUserFolder' )
		                 ->with( 'alice' )
		                 ->willReturn( $userFolder )
		;
		$userFolder->method( 'getById' )
		           ->with( 42 )
		           ->willReturn( [ $node ] )
		;

		// Owning the file is one gate, the manual-recalculation permission the
		// other; this test is about the first, so the second says yes.
		$this->permissionService->method( 'isAllowed' )
		                        ->with( PermissionService::PERMISSION_MANUAL_RECALC, 'alice' )
		                        ->willReturn( true )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'recalcHash' )
		                       ->with( 42, 'sha256' )
		                       ->willReturn( [ 'success' => true ] )
		;

		$result = $this->api->recalcHash( 42, 'sha256', 'alice' );

		$this->assertTrue( $result['success'] );
	}


	// ─── rules surface ──────────────────────────────────────────────


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testTrustedCallerActsAsAdminAndIsAuditedAsApi(): void
	{

		// null requesting user = server-side code with full authority: the
		// scope passes through as an administrator's would, and the audit
		// names the surface.
		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleAdd' )
		                  ->with(
			                  $this->callback(
				                  static fn(
					                  array $definition,
				                  ): bool => $definition['selector'] === 'group:staff'
					                  && $definition['admin_enforced'] === true,
			                  ),
			                  'api',
		                  )
		                  ->willReturn( 'newid' )
		;

		$id = $this->api->createRule(
			[
				'path'           => '/legal/**',
				'selector'       => 'group:staff',
				'admin_enforced' => true,
			],
		);

		$this->assertSame( 'newid', $id );
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testANamedNonAdminIsEnforcedExactlyLikeRest(): void
	{

		$this->groupManager->method( 'isAdmin' )
		                   ->with( 'bob' )
		                   ->willReturn( false )
		;
		$this->permissionService->method( 'canUserEditRules' )
		                        ->with( 'bob' )
		                        ->willReturn( true )
		;
		$this->ruleService->method( 'ruleTargetRefusal' )
		                  ->willReturn( null )
		;

		// Whatever the payload claims: bob's rule is bob's, never enforced.
		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleAdd' )
		                  ->with(
			                  $this->callback(
				                  static fn(
					                  array $definition,
				                  ): bool => $definition['selector'] === 'home:bob'
					                  && $definition['admin_enforced'] === false,
			                  ),
			                  'bob',
		                  )
		                  ->willReturn( 'newid' )
		;

		$this->api->createRule(
			[
				'path'           => '/docs/**',
				'selector'       => '*',
				'admin_enforced' => true,
			],
			'bob',
		);
	}


	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testANamedUserWithoutThePermissionIsRefused(): void
	{

		$this->groupManager->method( 'isAdmin' )
		                   ->willReturn( false )
		;
		$this->permissionService->method( 'canUserEditRules' )
		                        ->willReturn( false )
		;
		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleAdd' )
		;

		$this->expectException( InvalidArgumentException::class );

		$this->api->createRule( [ 'path' => '/docs/**' ], 'bob' );
	}


	public function testApplyRunsSynchronouslyAndReturnsTheBuckets(): void
	{

		$rule = [
			'id'      => 'r1',
			'enabled' => true,
			'type'    => 'include',
		];
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( $rule )
		;
		$this->ruleService->expects( $this->once() )
		                  ->method( 'applyRule' )
		                  ->with( $rule, null, null, 'api' )
		                  ->willReturn( [
			                  'matched' => 4,
			                  'marked'  => 2,
			                  'skipped' => 1,
			                  'fresh'   => 1,
		                  ] )
		;

		// Synchronous by design: a DI caller controls its own execution
		// context and usually wants the result.
		$this->assertSame( 2, $this->api->applyRule( 'r1' )['marked'] );
	}

}
