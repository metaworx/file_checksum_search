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
use OCA\FileChecksumSearch\Service\RuleOverrides;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateHashesTest
	extends
	TestCase
{

	private MockObject|HashIndexService $hashIndexService;

	private MockObject|MetadataService  $metadataService;

	private MockObject|FilecacheService $filecacheService;

	private MockObject|RuleService      $ruleService;

	private MockObject|LoggerInterface $logger;

	private CommandTester              $tester;


	protected function setUp(): void
	{

		parent::setUp();

		$this->hashIndexService = $this->createMock( HashIndexService::class );
		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->filecacheService = $this->createMock( FilecacheService::class );
		$this->ruleService      = $this->createMock( RuleService::class );
		$this->logger           = $this->createMock( LoggerInterface::class );

		$command      = new GenerateHashes(
			$this->hashIndexService,
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
		                       ->willReturn(
			                       [
				                       'processed' => 5,
				                       'skipped'   => 1,
			                       ],
		                       )
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
			                       [
				                       'sha1',
				                       'md5',
			                       ],
			                       null,
			                       0,
			                       $this->anything(),
		                       )
		                       ->willReturn(
			                       [
				                       'processed' => 0,
				                       'skipped'   => 0,
			                       ],
		                       )
		;

		$this->tester->execute(
			[
				'--user' => 'alice',
				'--algo' => 'SHA1, md5, sha1',
			],
		);
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
		                       ->willReturn(
			                       [
				                       'processed' => 0,
				                       'skipped'   => 0,
			                       ],
		                       )
		;

		$this->tester->execute(
			[
				'--user' => 'alice',
				'--algo' => 'all',
			],
		);
	}


	public function testPathOptionIsPassedThrough(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn( [ 'alice' ] )
		;

		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'generateMissingHashes' )
		                       ->with( 'alice', $this->anything(), '**/*.pdf', 0, $this->anything() )
		                       ->willReturn(
			                       [
				                       'processed' => 0,
				                       'skipped'   => 0,
			                       ],
		                       )
		;

		$this->tester->execute(
			[
				'--user' => 'alice',
				'--path' => '**/*.pdf',
			],
		);
	}


	public function testAggregatesResultsAcrossMultipleUsers(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->with( 'all' )
		                  ->willReturn(
			                  [
				                  'alice',
				                  'bob',
			                  ],
		                  )
		;

		$this->hashIndexService->expects( $this->exactly( 2 ) )
		                       ->method( 'generateMissingHashes' )
		                       ->willReturnOnConsecutiveCalls(
			                       [
				                       'processed' => 3,
				                       'skipped'   => 0,
			                       ],
			                       [
				                       'processed' => 2,
				                       'skipped'   => 1,
			                       ],
		                       )
		;

		$this->tester->execute( [] );

		$this->assertStringContainsString( 'Done. 5 files hashed, 1 skipped.', $this->tester->getDisplay() );
	}


	public function testBatchSizeIsConsumedAcrossUsersAndStopsWhenExhausted(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn(
			                  [
				                  'alice',
				                  'bob',
			                  ],
		                  )
		;

		// alice consumes the entire batch; bob should never be processed.
		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'generateMissingHashes' )
		                       ->with( 'alice', $this->anything(), null, 10, $this->anything() )
		                       ->willReturn(
			                       [
				                       'processed' => 10,
				                       'skipped'   => 0,
			                       ],
		                       )
		;

		$this->tester->execute( [ '--batch-size' => '10' ] );

		$this->assertStringContainsString(
			'Batch limit reached. 10 files hashed, 0 skipped.',
			$this->tester->getDisplay(),
		);
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
		$this->assertStringContainsString(
			'Batch limit reached. 0 files hashed, 0 skipped.',
			$this->tester->getDisplay(),
		);
	}


	// --mark

	public function testMarkSkipsAndReportsFilesTheRulesExclude(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn( [ 'alice' ] )
		;

		$userFolder = $this->createMock( Folder::class );
		$this->filecacheService->method( 'getUserFolder' )
		                       ->with( 'alice' )
		                       ->willReturn( $userFolder )
		;

		$kept = $this->createMock( File::class );
		$kept->method( 'getId' )
		     ->willReturn( 1 )
		;
		$kept->method( 'getPath' )
		     ->willReturn( '/files/a.txt' )
		;
		$skipped = $this->createMock( File::class );
		$skipped->method( 'getId' )
		        ->willReturn( 2 )
		;
		$skipped->method( 'getPath' )
		        ->willReturn( '/files/Archive/b.txt' )
		;

		$this->ruleService->method( 'searchFilesByGlob' )
		                  ->willReturn(
			                  [
				                  $kept,
				                  $skipped,
			                  ],
		                  )
		;

		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturnCallback(
			                  static fn(
				                  string $path,
			                  ): array => str_contains( $path, '/Archive/' )
				                  ? [
					                  'id'   => 'archive',
					                  'type' => 'exclude',
				                  ]
				                  : [
					                  'id'   => 'r1',
					                  'mode' => 'auto',
				                  ],
		                  )
		;

		// This command is the CLI face of the background job, so a rule that
		// stops automatic hashing stops it too — and says so, rather than
		// silently doing less than asked.
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markPending' )
		                      ->with( 1, MetadataService::PENDING_AUTO )
		;

		$this->tester->execute(
			[
				'--user' => 'alice',
				'--mark' => true,
			],
		);

		$this->assertStringContainsString(
			'Marked 1 files, skipped 1 excluded by rules.',
			$this->tester->getDisplay(),
		);
		$this->assertStringContainsString(
			'1 skipped: a rule excludes them from hashing.',
			$this->tester->getDisplay(),
		);
	}


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
		$file1->method( 'getId' )
		      ->willReturn( 1 )
		;
		$file1->method( 'getPath' )
		      ->willReturn( '/files/a.txt' )
		;
		$file2 = $this->createMock( File::class );
		$file2->method( 'getId' )
		      ->willReturn( 2 )
		;
		$file2->method( 'getPath' )
		      ->willReturn( '/files/b.txt' )
		;

		$this->ruleService->method( 'searchFilesByGlob' )
		                  ->with( $userFolder, '**', 0 )
		                  ->willReturn(
			                  [
				                  $file1,
				                  $file2,
			                  ],
		                  )
		;

		// Marking now asks whether each file is one the rules maintain.
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturn(
			                  [
				                  'id'   => 'r1',
				                  'mode' => 'auto',
			                  ],
		                  )
		;

		$this->metadataService->expects( $this->exactly( 2 ) )
		                      ->method( 'markPending' )
		                      ->willReturnCallback(
			                      function (
				                      int    $fileId,
				                      string $mode,
			                      ): void {

				                      $this->assertSame( MetadataService::PENDING_AUTO, $mode );
			                      },
		                      )
		;

		$exitCode = $this->tester->execute(
			[
				'--user' => 'alice',
				'--mark' => true,
			],
		);

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

		$exitCode = $this->tester->execute(
			[
				'--user' => 'ghost',
				'--mark' => true,
			],
		);

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'User folder not found, skipping.', $this->tester->getDisplay() );
	}


	// ─── rule overrides ─────────────────────────────────────────────

	public function testWithIgnoredProcessesIgnoredFilesButStillSkipsExcluded(): void
	{

		$this->markSetup(
			[
				'/files/a.txt'         => [
					'id'   => 'r1',
					'type' => 'include',
				],
				'/files/quiet/b.txt'   => [
					'id'   => 'quiet',
					'type' => 'ignore',
				],
				'/files/metered/c.txt' => [
					'id'   => 'metered',
					'type' => 'exclude',
				],
			],
		);

		// "ignore" means not automatically, but when asked — and an operator
		// typing a command is asking. "exclude" means the storage must not be
		// read, which no amount of asking changes.
		$marked = [];
		$this->metadataService->method( 'markPending' )
		                      ->willReturnCallback(
			                      static function (
				                      int $fileId,
			                      ) use
			                      (
				                      &
				                      $marked,
			                      ): void
			                      {

				                      $marked[] = $fileId;
			                      },
		                      )
		;

		$this->tester->execute(
			[
				'--user'         => 'alice',
				'--mark'         => true,
				'--with-ignored' => true,
			],
		);

		$this->assertSame(
			[
				1,
				2,
			],
			$marked,
		);
		$this->assertStringContainsString(
			'Processing files their rule says to ignore.',
			$this->tester->getDisplay(),
		);
	}


	public function testIgnoreRuleIsPassedToTheLookupSoTheNextRuleDecides(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn( [ 'alice' ] )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->with( 'metered' )
		                  ->willReturn(
			                  [
				                  'id'   => 'metered',
				                  'type' => 'exclude',
				                  'path' => '/metered/**',
			                  ],
		                  )
		;

		// The override is not a permit to hash: it changes which rule governs,
		// and whatever answers next still decides.
		$this->hashIndexService->expects( $this->once() )
		                       ->method( 'generateMissingHashes' )
		                       ->with(
			                       'alice',
			                       $this->anything(),
			                       $this->anything(),
			                       $this->anything(),
			                       $this->anything(),
			                       $this->callback(
				                       static fn(
					                       RuleOverrides $overrides,
				                       ): bool => $overrides->ignoreRuleIds === [ 'metered' ]
					                       && ! $overrides->withIgnored,
			                       ),
		                       )
		                       ->willReturn(
			                       [
				                       'processed' => 0,
				                       'skipped'   => 0,
			                       ],
		                       )
		;

		$exit = $this->tester->execute(
			[
				'--user'        => 'alice',
				'--ignore-rule' => [ 'metered' ],
			],
		);

		$this->assertSame( Command::SUCCESS, $exit );
	}


	public function testIgnoreRuleIsRepeatable(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn( [ 'alice' ] )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturnCallback(
			                  static fn(
				                  string $id,
			                  ): array => [
				                  'id'   => $id,
				                  'type' => 'exclude',
			                  ],
		                  )
		;

		$this->hashIndexService->method( 'generateMissingHashes' )
		                       ->with(
			                       $this->anything(),
			                       $this->anything(),
			                       $this->anything(),
			                       $this->anything(),
			                       $this->anything(),
			                       $this->callback(
				                       static fn(
					                       RuleOverrides $overrides,
				                       ): bool => $overrides->ignoreRuleIds === [
						                       'one',
						                       'two',
					                       ],
			                       ),
		                       )
		                       ->willReturn(
			                       [
				                       'processed' => 0,
				                       'skipped'   => 0,
			                       ],
		                       )
		;

		$this->assertSame(
			Command::SUCCESS,
			$this->tester->execute(
				[
					'--user'        => 'alice',
					'--ignore-rule' => [
						'one',
						'two',
					],
				],
			),
		);
	}


	public function testUnknownIgnoreRuleIdFailsRatherThanBeingSkipped(): void
	{

		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn( null )
		;

		// A typo must not produce a normal-looking run that quietly honoured
		// the rule the operator meant to set aside.
		$this->hashIndexService->expects( $this->never() )
		                       ->method( 'generateMissingHashes' )
		;

		$exit = $this->tester->execute(
			[
				'--user'        => 'alice',
				'--ignore-rule' => [ 'nosuchrule' ],
			],
		);

		$this->assertSame( Command::FAILURE, $exit );
		$this->assertStringContainsString( 'No rule with ID "nosuchrule".', $this->tester->getDisplay() );
	}


	public function testSettingAsideAnEnforcedRuleIsAllowedButLoggedAndAnnounced(): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn( [ 'alice' ] )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->willReturn(
			                  [
				                  'id'             => 'mandate',
				                  'type'           => 'exclude',
				                  'path'           => '/metered/**',
				                  'admin_enforced' => true,
			                  ],
		                  )
		;

		// Shell access as the web server user is already the highest privilege
		// here, so there is nothing to protect — but an enforced rule is the
		// one an administrator wrote down as non-negotiable, so setting it
		// aside leaves a trace someone else can find.
		$this->logger->expects( $this->once() )
		             ->method( 'warning' )
		             ->with(
			             $this->stringContains( 'set aside admin-enforced rule' ),
			             $this->callback(
				             static fn(
					             array $context,
				             ): bool => $context['ruleId'] === 'mandate',
			             ),
		             )
		;

		$this->hashIndexService->method( 'generateMissingHashes' )
		                       ->willReturn(
			                       [
				                       'processed' => 0,
				                       'skipped'   => 0,
			                       ],
		                       )
		;

		$this->tester->execute(
			[
				'--user'        => 'alice',
				'--ignore-rule' => [ 'mandate' ],
			],
		);

		$this->assertStringContainsString(
			'Setting aside admin-enforced rule mandate',
			$this->tester->getDisplay(),
		);
	}


	public function testVerboseNamesTheRuleThatSkippedEachFile(): void
	{

		$this->markSetup(
			[
				'/files/a.txt'         => [
					'id'   => 'r1',
					'type' => 'include',
				],
				'/files/metered/c.txt' => [
					'id'   => 'metered',
					'type' => 'exclude',
				],
			],
		);

		$this->tester->execute(
			[
				'--user' => 'alice',
				'--mark' => true,
			],
			[ 'verbosity' => OutputInterface::VERBOSITY_VERBOSE ],
		);

		$display = $this->tester->getDisplay();

		// -v answers "why is this file not being hashed" for the skipped ones…
		$this->assertStringContainsString( 'skip /files/metered/c.txt [metered: band', $display );
		$this->assertStringContainsString( 'exclude]', $display );
		// …and stays quiet about the ones that proceeded.
		$this->assertStringNotContainsString( 'hash /files/a.txt', $display );
	}


	public function testVeryVerboseAlsoNamesTheRuleForFilesThatProceeded(): void
	{

		$this->markSetup(
			[
				'/files/a.txt' => [
					'id'   => 'r1',
					'type' => 'include',
				],
			],
		);

		$this->tester->execute(
			[
				'--user' => 'alice',
				'--mark' => true,
			],
			[ 'verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE ],
		);

		$this->assertStringContainsString( 'hash /files/a.txt [r1: band', $this->tester->getDisplay() );
	}


	public function testAFileNoRuleMatchesIsReportedAsSuch(): void
	{

		$this->markSetup( [ '/files/a.txt' => null ] );

		$this->tester->execute(
			[
				'--user' => 'alice',
				'--mark' => true,
			],
			[ 'verbosity' => OutputInterface::VERBOSITY_VERBOSE ],
		);

		// Nothing decided, so nothing is hashed — and the reason says so
		// rather than leaving the file simply absent from the output.
		$this->assertStringContainsString( 'skip /files/a.txt [no matching rule]', $this->tester->getDisplay() );
	}


	/**
	 * Wire up a --mark run over $rulesByPath, one file per path, ids from 1.
	 *
	 * @param  array<string, array|null>  $rulesByPath
	 */
	private function markSetup( array $rulesByPath ): void
	{

		$this->ruleService->method( 'resolveUsers' )
		                  ->willReturn( [ 'alice' ] )
		;
		$this->filecacheService->method( 'getUserFolder' )
		                       ->willReturn( $this->createMock( Folder::class ) )
		;

		$files = [];
		$id    = 1;

		foreach ( array_keys( $rulesByPath ) as $path )
		{
			$file = $this->createMock( File::class );
			$file->method( 'getId' )
			     ->willReturn( $id ++ )
			;
			$file->method( 'getPath' )
			     ->willReturn( $path )
			;
			$files[] = $file;
		}

		$this->ruleService->method( 'searchFilesByGlob' )
		                  ->willReturn( $files )
		;
		$this->ruleService->method( 'findFirstMatchingRule' )
		                  ->willReturnCallback(
			                  static fn(
				                  string $path,
			                  ): ?array => $rulesByPath[ $path ] ?? null,
		                  )
		;
	}

}
