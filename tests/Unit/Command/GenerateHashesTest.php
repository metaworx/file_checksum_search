<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\GenerateHashes;
use OCA\FileChecksumSearch\Service\FilecacheService;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\HashIndexService;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateHashesTest
	extends
	TestCase
{

	private MockObject|HashIndexService       $hashIndexService;

	private MockObject|HashCalculationService $hashCalc;

	private MockObject|MetadataService        $metadataService;

	private MockObject|FilecacheService        $filecacheService;

	private MockObject|RuleService             $ruleService;

	private MockObject|LoggerInterface         $logger;

	private CommandTester                      $tester;


	protected function setUp(): void
	{

		parent::setUp();

		$this->hashIndexService = $this->createMock( HashIndexService::class );
		$this->hashCalc         = $this->createMock( HashCalculationService::class );
		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->filecacheService = $this->createMock( FilecacheService::class );
		$this->ruleService      = $this->createMock( RuleService::class );
		$this->logger           = $this->createMock( LoggerInterface::class );

		$command      = new GenerateHashes(
			$this->hashIndexService,
			$this->hashCalc,
			$this->metadataService,
			$this->filecacheService,
			$this->ruleService,
			$this->logger,
		);
		$this->tester = new CommandTester( $command );
	}


	public function testFailsWhenNoUsersMatchScope(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->with( 'ghost' )
		                  ->willReturn( [] )
		;

		$exitCode = $this->tester->execute( [ '--user' => 'ghost' ] );

		$this->assertSame( Command::FAILURE, $exitCode );
		$this->assertStringContainsString( 'No users found for scope "ghost".', $this->tester->getDisplay() );
	}


	public function testGeneratesForSingleUserWithDefaultAlgo(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->with( 'alice' )
		                  ->willReturn( [ 'alice' ] )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'generateMissingHashes' )
		                       ->with(
			                       'alice',
			                       [ HashCalculationService::getDefaultAlgo() ],
			                       null,
			                       0,
			                       $this->anything(),
		                       )
		                       ->willReturn( [ 'processed' => 5, 'skipped' => 1 ] )
		;

		$exitCode = $this->tester->execute( [ '--user' => 'alice' ] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'Done. 5 files hashed, 1 skipped.', $this->tester->getDisplay() );
	}


	public function testCommaSeparatedAlgoListIsNormalized(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn( [ 'alice' ] )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'generateMissingHashes' )
		                       ->with(
			                       'alice',
			                       [ 'sha1', 'md5' ],
			                       null,
			                       0,
			                       $this->anything(),
		                       )
		                       ->willReturn( [ 'processed' => 0, 'skipped' => 0 ] )
		;

		$this->tester->execute( [ '--user' => 'alice', '--algo' => 'SHA1, md5, sha1' ] );
	}


	public function testAlgoAllExpandsToEverySupportedAlgorithm(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn( [ 'alice' ] )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'generateMissingHashes' )
		                       ->with(
			                       'alice',
			                       HashCalculationService::SUPPORTED_ALGOS,
			                       null,
			                       0,
			                       $this->anything(),
		                       )
		                       ->willReturn( [ 'processed' => 0, 'skipped' => 0 ] )
		;

		$this->tester->execute( [ '--user' => 'alice', '--algo' => 'all' ] );
	}


	public function testPathOptionIsPassedThrough(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn( [ 'alice' ] )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'generateMissingHashes' )
		                       ->with( 'alice', $this->anything(), '**/*.pdf', 0, $this->anything() )
		                       ->willReturn( [ 'processed' => 0, 'skipped' => 0 ] )
		;

		$this->tester->execute( [ '--user' => 'alice', '--path' => '**/*.pdf' ] );
	}


	public function testAggregatesResultsAcrossMultipleUsers(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->with( 'all' )
		                  ->willReturn( [ 'alice', 'bob' ] )
		;

		$this->hashIndexService->expects( $this->exactly( 2 ) )
		                       ->method( 'generateMissingHashes' )
		                       ->willReturnOnConsecutiveCalls(
			                       [ 'processed' => 3, 'skipped' => 0 ],
			                       [ 'processed' => 2, 'skipped' => 1 ],
		                       )
		;

		$this->tester->execute( [] );

		$this->assertStringContainsString( 'Done. 5 files hashed, 1 skipped.', $this->tester->getDisplay() );
	}


	public function testBatchSizeIsConsumedAcrossUsersAndStopsWhenExhausted(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn( [ 'alice', 'bob' ] )
		;

		// alice consumes the entire batch; bob should never be processed.
		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'generateMissingHashes' )
		                       ->with( 'alice', $this->anything(), null, 10, $this->anything() )
		                       ->willReturn( [ 'processed' => 10, 'skipped' => 0 ] )
		;

		$this->tester->execute( [ '--batch-size' => '10' ] );

		$this->assertStringContainsString( 'Batch limit reached. 10 files hashed, 0 skipped.', $this->tester->getDisplay() );
	}


	public function testInvalidBatchSizeSilentlyBehavesAsZeroAndProcessesNoUsers(): void
	{

		// Known gap (FCIAS Review §6, Finding 3, not yet fixed): an
		// unparseable --batch-size coerces to 0 via (int) with no
		// validation, which this command's remaining>0 pre-check then
		// treats as "batch already exhausted" — no user is processed and
		// no error is surfaced for the typo'd flag. This test documents
		// the current behavior; it is not an endorsement of it.
		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn( [ 'alice' ] )
		;

		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'generateMissingHashes' )
		;

		$exitCode = $this->tester->execute( [ '--batch-size' => 'not-a-number' ] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'Batch limit reached. 0 files hashed, 0 skipped.', $this->tester->getDisplay() );
	}


	// --mark

	public function testMarkOnlyMarksMatchingFilesAsPendingAuto(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn( [ 'alice' ] )
		;

		$userFolder = $this->createMock( Folder::class );
		$this->filecacheService->method( 'getUserFolder' )
		                       ->with( 'alice' )
		                       ->willReturn( $userFolder )
		;

		$file1 = $this->createMock( File::class );
		$file1->method( 'getId' )->willReturn( 1 );
		$file2 = $this->createMock( File::class );
		$file2->method( 'getId' )->willReturn( 2 );

		$this->ruleService->method( 'searchFilesByGlob' )
		                  ->with( $userFolder, '**', 0 )
		                  ->willReturn( [ $file1, $file2 ] )
		;

		$this->metadataService->expects( $this->exactly( 2 ) )
		                      ->method( 'markPending' )
		                      ->willReturnCallback( function ( int $fileId, string $mode ): void {

			                      $this->assertSame( MetadataService::PENDING_AUTO, $mode );
		                      } )
		;

		$exitCode = $this->tester->execute( [ '--user' => 'alice', '--mark' => true ] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'Marked 2 files.', $this->tester->getDisplay() );
		$this->assertStringContainsString( 'Done. 2 files marked as pending:auto.', $this->tester->getDisplay() );
	}


	public function testMarkOnlySkipsUserWithoutFolder(): void
	{

		// Regression-relevant: getUserFolder() actually throws the
		// internal \OC\User\NoUserException when the user vanished
		// between resolveUsers() and this loop (a race), not an
		// OCP-namespaced type — the command must tolerate any
		// Throwable here, not one specific (and non-existent) class.
		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn( [ 'ghost' ] )
		;

		$this->filecacheService->method( 'getUserFolder' )
		                       ->willThrowException( new \RuntimeException( 'user vanished' ) )
		;

		$this->metadataService->expects( $this->never() )
		                      ->method( 'markPending' )
		;

		$exitCode = $this->tester->execute( [ '--user' => 'ghost', '--mark' => true ] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'User folder not found, skipping.', $this->tester->getDisplay() );
	}

}
