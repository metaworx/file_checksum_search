<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\FindDuplicates;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class FindDuplicatesTest
    extends
    TestCase
{

//  private properties

	private MockObject|HashIndexService $hashIndexService;

	private MockObject|IUserManager     $userManager;

	/** @noinspection PhpPrivateFieldCanBeLocalVariableInspection */
	private MockObject|LoggerInterface  $logger;

	private CommandTester               $tester;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->hashIndexService = $this->createMock( HashIndexService::class );
		$this->userManager      = $this->createMock( IUserManager::class );
		$this->logger           = $this->createMock( LoggerInterface::class );

		$command      = new FindDuplicates( $this->hashIndexService, $this->userManager, $this->logger );
		$this->tester = new CommandTester( $command );
	}


//  other non-static methods

	public function testReportsNoDuplicatesWhenNoneFound(): void
	{
		$this->hashIndexService->method( 'findAllDuplicates' )
		                       ->willReturn( [] )
		;

		$exitCode = $this->tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'No duplicate files found.', $this->tester->getDisplay() );
	}

	public function testListsGroupsWithFilesAndPaths(): void
	{
		$this->hashIndexService->method( 'findAllDuplicates' )
		                       ->with( 'sha1', 2, 100 )
		                       ->willReturn( [
			                       [
				                       'algo'       => 'sha1',
				                       'hash_value' => 'abc123',
				                       'file_count' => 2,
				                       'fileids'    => [ 42, 108 ],
			                       ],
		                       ] )
		;
		$this->hashIndexService->method( 'batchLookupFilecachePaths' )
		                       ->with( [ 42, 108 ], null )
		                       ->willReturn( [
			                       42  => [ 'path' => 'Docs/a.txt', 'name' => 'a.txt', 'storage_id' => 'home::alice', 'owner' => 'alice', 'location' => '/alice/files/Docs/a.txt' ],
			                       108 => [ 'path' => 'Docs/b.txt', 'name' => 'b.txt', 'storage_id' => 'home::alice', 'owner' => 'alice', 'location' => '/alice/files/Docs/b.txt' ],
		                       ] )
		;

		$exitCode = $this->tester->execute( [ '--algo' => 'sha1' ] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( 'SHA1 / abc123 (2 files)', $display );
		$this->assertStringContainsString( 'Docs/a.txt', $display );
		$this->assertStringContainsString( 'Docs/b.txt', $display );
	}

	public function testDropsGroupsBelowMinCountAfterPathResolution(): void
	{
		$this->hashIndexService->method( 'findAllDuplicates' )
		                       ->willReturn( [
			                       [
				                       'algo'       => 'sha1',
				                       'hash_value' => 'abc123',
				                       'file_count' => 2,
				                       'fileids'    => [ 42, 108 ],
			                       ],
		                       ] )
		;
		// Only one of the two file IDs resolves to a path.
		$this->hashIndexService->method( 'batchLookupFilecachePaths' )
		                       ->willReturn( [
			                       42 => [ 'path' => 'Docs/a.txt', 'name' => 'a.txt', 'storage_id' => 'home::alice', 'owner' => 'alice', 'location' => '/alice/files/Docs/a.txt' ],
		                       ] )
		;

		$this->tester->execute( [ '--min-count' => '2' ] );

		$this->assertStringContainsString( 'No duplicate files found.', $this->tester->getDisplay() );
	}

	public function testFailsWhenUserFilterDoesNotResolve(): void
	{
		$this->userManager->method( 'get' )
		                  ->with( 'ghost' )
		                  ->willReturn( null )
		;

		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'findAllDuplicates' )
		;

		$exitCode = $this->tester->execute( [ '--user' => 'ghost' ] );

		$this->assertSame( Command::FAILURE, $exitCode );
		$this->assertStringContainsString( 'User "ghost" not found.', $this->tester->getDisplay() );
	}

	public function testUserFilterUsesLargeQueryLimitAndResolvedUid(): void
	{
		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( 'alice' )
		;
		$this->userManager->method( 'get' )
		                  ->with( 'alice' )
		                  ->willReturn( $user )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'findAllDuplicates' )
		                       ->with( null, 2, 10000 )
		                       ->willReturn( [] )
		;

		$this->tester->execute( [ '--user' => 'alice' ] );
	}

	public function testJsonOutputFormat(): void
	{
		$this->hashIndexService->method( 'findAllDuplicates' )
		                       ->willReturn( [
			                       [
				                       'algo'       => 'sha1',
				                       'hash_value' => 'abc123',
				                       'file_count' => 2,
				                       'fileids'    => [ 42, 108 ],
			                       ],
		                       ] )
		;
		$this->hashIndexService->method( 'batchLookupFilecachePaths' )
		                       ->willReturn( [
			                       42  => [ 'path' => 'a.txt', 'name' => 'a.txt', 'storage_id' => 'home::alice', 'owner' => 'alice', 'location' => '/alice/a.txt' ],
			                       108 => [ 'path' => 'b.txt', 'name' => 'b.txt', 'storage_id' => 'home::alice', 'owner' => 'alice', 'location' => '/alice/b.txt' ],
		                       ] )
		;

		$this->tester->execute( [ '--output' => 'json' ] );

		$decoded = json_decode( trim( $this->tester->getDisplay() ), true );
		$this->assertCount( 1, $decoded['duplicates'] );
		$this->assertSame( 'abc123', $decoded['duplicates'][0]['hash_value'] );
	}

	/**
	 * A row nobody owns — a group folder's, an external storage's — is
	 * carried as the API carries it, `owner` null, and printed by where it
	 * lives: its path alone names nowhere. The text form printed the path
	 * for such a row, and the JSON gave `""` where the API gives `null`.
	 */
	public function testAnOwnerlessRowIsPrintedByItsLocationAndCarriedAsTheApiCarriesIt(): void
	{
		$this->hashIndexService->method( 'findAllDuplicates' )
		                       ->willReturn( [
			                       [
				                       'algo'       => 'sha1',
				                       'hash_value' => 'abc123',
				                       'file_count' => 2,
				                       'fileids'    => [ 42, 108 ],
			                       ],
		                       ] )
		;
		$this->hashIndexService->method( 'batchLookupFilecachePaths' )
		                       ->willReturn( [
			                       42  => [ 'path' => 'a.txt', 'name' => 'a.txt', 'storage_id' => 'home::alice', 'owner' => 'alice', 'location' => '/alice/files/a.txt' ],
			                       108 => [ 'path' => 'Shared/a.txt', 'name' => 'a.txt', 'storage_id' => 'local::/mnt/x/', 'owner' => null, 'location' => 'groupfolder:7:Shared/a.txt' ],
		                       ] )
		;

		$this->tester->execute( [] );

		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( '(alice) a.txt', $display, 'the owned row by its path, behind the owner' );
		$this->assertStringContainsString( 'groupfolder:7:Shared/a.txt', $display, 'the ownerless row by where it lives' );
		$this->assertStringNotContainsString( '() ', $display, 'no empty owner label' );

		$this->tester->execute( [ '--output' => 'json' ] );

		$decoded = json_decode( trim( $this->tester->getDisplay() ), true );
		$this->assertSame( 'alice', $decoded['duplicates'][0]['files'][0]['owner'] );
		$this->assertNull( $decoded['duplicates'][0]['files'][1]['owner'] );
	}

	public function testVerifyRecalculatesAndFlagsMismatches(): void
	{
		$this->hashIndexService->method( 'findAllDuplicates' )
		                       ->willReturn( [
			                       [
				                       'algo'       => 'sha1',
				                       'hash_value' => 'abc123',
				                       'file_count' => 2,
				                       'fileids'    => [ 42, 108 ],
			                       ],
		                       ] )
		;
		$this->hashIndexService->method( 'batchLookupFilecachePaths' )
		                       ->willReturn( [
			                       42  => [ 'path' => 'a.txt', 'name' => 'a.txt', 'storage_id' => 'home::alice', 'owner' => 'alice', 'location' => '/alice/a.txt' ],
			                       108 => [ 'path' => 'b.txt', 'name' => 'b.txt', 'storage_id' => 'home::alice', 'owner' => 'alice', 'location' => '/alice/b.txt' ],
		                       ] )
		;
		$this->hashIndexService->method( 'recalcHash' )
		                       ->willReturnMap( [
			                       [ 42, 'sha1', false, [ 'success' => true, 'hash' => 'abc123' ] ],
			                       [ 108, 'sha1', false, [ 'success' => true, 'hash' => 'different' ] ],
		                       ] )
		;

		$this->tester->execute( [ '--verify' => true ] );

		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( '1 MISMATCH', $display );
		$this->assertStringContainsString( '✓', $display );
	}

	public function testVerifiedOptionFiltersOutMismatchedGroups(): void
	{
		$this->hashIndexService->method( 'findAllDuplicates' )
		                       ->willReturn( [
			                       [
				                       'algo'       => 'sha1',
				                       'hash_value' => 'abc123',
				                       'file_count' => 2,
				                       'fileids'    => [ 42, 108 ],
			                       ],
		                       ] )
		;
		$this->hashIndexService->method( 'batchLookupFilecachePaths' )
		                       ->willReturn( [
			                       42  => [ 'path' => 'a.txt', 'name' => 'a.txt', 'storage_id' => 'home::alice', 'owner' => 'alice', 'location' => '/alice/a.txt' ],
			                       108 => [ 'path' => 'b.txt', 'name' => 'b.txt', 'storage_id' => 'home::alice', 'owner' => 'alice', 'location' => '/alice/b.txt' ],
		                       ] )
		;
		$this->hashIndexService->method( 'recalcHash' )
		                       ->willReturnMap( [
			                       [ 42, 'sha1', false, [ 'success' => true, 'hash' => 'abc123' ] ],
			                       [ 108, 'sha1', false, [ 'success' => true, 'hash' => 'different' ] ],
		                       ] )
		;

		$this->tester->execute( [ '--verified' => true ] );

		// --verified implies --verify, and the one mismatched group must
		// be filtered out entirely.
		$this->assertStringContainsString( 'No duplicate files found.', $this->tester->getDisplay() );
	}
}
