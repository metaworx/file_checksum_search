<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command\MimeType;

use OCA\FileChecksumSearch\Command\MimeType\Delete;
use OCA\FileChecksumSearch\Command\MimeType\Disable;
use OCA\FileChecksumSearch\Command\MimeType\Enable;
use OCA\FileChecksumSearch\Command\MimeType\IsEnabled;
use OCA\FileChecksumSearch\Command\MimeType\Refresh;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCA\FileChecksumSearch\Service\MimeTypeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The `fcias:mimetype:*` commands: thin over {@see MimeTypeService}, whose
 * rules they follow, and {@see MetadataService}, which does the work. What
 * is pinned here is what an operator, or a script, reads back.
 */
class MimeTypeCommandsTest
    extends
    TestCase
{

//  private properties

	private MimeTypeService&MockObject $mimeTypes;

	private MetadataService&MockObject $metadataService;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->mimeTypes       = $this->createMock( MimeTypeService::class );
		$this->metadataService = $this->createMock( MetadataService::class );
	}


//  other non-static methods

	public function testIsEnabledSaysOnAndExitsZero(): void
	{
		$this->given( true, true );

		$tester = new CommandTester( new IsEnabled( $this->mimeTypes ) );

		$this->assertSame( Command::SUCCESS, $tester->execute( [] ) );
		$this->assertSame( "on\n", $tester->getDisplay() );
	}

	public function testIsEnabledSaysOffAndExitsThree(): void
	{
		$this->given( false, true );

		$tester = new CommandTester( new IsEnabled( $this->mimeTypes ) );

		$this->assertSame( IsEnabled::NO, $tester->execute( [] ) );
		$this->assertSame( 3, IsEnabled::NO );
		$this->assertSame( "off\n", $tester->getDisplay() );
	}

	/** Switched on, and PHP unable to detect: not detecting, so 3. */
	public function testIsEnabledSaysUnavailableAndExitsThree(): void
	{
		$this->given( true, false );

		$tester = new CommandTester( new IsEnabled( $this->mimeTypes ) );

		$this->assertSame( IsEnabled::NO, $tester->execute( [] ) );
		$this->assertSame( "unavailable\n", $tester->getDisplay() );
	}

	public function testIsEnabledPrintsNothingWhenQuiet(): void
	{
		$this->given( true, true );

		$tester = new CommandTester( new IsEnabled( $this->mimeTypes ) );

		$this->assertSame( Command::SUCCESS, $tester->execute( [], [ 'verbosity' => OutputInterface::VERBOSITY_QUIET ] ) );
		$this->assertSame( '', $tester->getDisplay() );
	}

	/** Symfony's own answer to an unknown option is 1; the command's is 2. */
	public function testIsEnabledExitsTwoOnAnInvalidOption(): void
	{
		$this->given( true, true );

		$tester = new CommandTester( new IsEnabled( $this->mimeTypes ) );

		$this->assertSame( Command::INVALID, $tester->execute( [ '--no-such-option' => true ] ) );
	}

	/** An error is 1, apart from off and unavailable. */
	public function testIsEnabledExitsOneOnAnError(): void
	{
		$this->mimeTypes->method( 'isEnabled' )
		                ->willThrowException( new \RuntimeException( 'database gone' ) )
		;

		$tester = new CommandTester( new IsEnabled( $this->mimeTypes ) );

		$this->assertSame( Command::FAILURE, $tester->execute( [] ) );
	}

	public function testEnableIsRefusedWhileADeletionRuns(): void
	{
		$this->mimeTypes->method( 'refusalToEnable' )
		                ->willReturn( MimeTypeService::STATE_DELETING )
		;
		$this->mimeTypes->method( 'getDeleteRequestedAt' )
		                ->willReturn( 1_759_300_000 )
		;
		$this->mimeTypes->expects( $this->never() )
		                ->method( 'enable' )
		;

		$tester = new CommandTester( new Enable( $this->mimeTypes ) );

		$this->assertSame( Command::FAILURE, $tester->execute( [] ) );
		$this->assertStringContainsString( 'are being deleted', $tester->getDisplay() );
	}

	public function testEnableSwitchesItOn(): void
	{
		$this->mimeTypes->expects( $this->once() )
		                ->method( 'enable' )
		;

		$tester = new CommandTester( new Enable( $this->mimeTypes ) );

		$this->assertSame( Command::SUCCESS, $tester->execute( [] ) );
		$this->assertStringContainsString( 'fcias:mimetype:refresh', $tester->getDisplay() );
	}

	public function testDisableSwitchesItOffAndKeepsTheTypes(): void
	{
		$this->mimeTypes->expects( $this->once() )
		                ->method( 'disable' )
		;

		$tester = new CommandTester( new Disable( $this->mimeTypes ) );

		$this->assertSame( Command::SUCCESS, $tester->execute( [] ) );
		$this->assertStringContainsString( 'are kept', $tester->getDisplay() );
	}

	public function testRefreshIsRefusedWhileDetectionIsOff(): void
	{
		$this->mimeTypes->method( 'refusalToRefresh' )
		                ->willReturn( MimeTypeService::STATE_DISABLED )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markForMimeTypeAfter' )
		;

		$tester = new CommandTester( new Refresh( $this->mimeTypes, $this->metadataService ) );

		$this->assertSame( Command::FAILURE, $tester->execute( [] ) );
		$this->assertStringContainsString( 'fcias:mimetype:enable', $tester->getDisplay() );
	}

	/** In the command, not queued: the files without a type, or all with --all. */
	public function testRefreshQueuesTheFilesItself(): void
	{
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markForMimeTypeAfter' )
		                      ->with( 0, 1000, true )
		                      ->willReturn(
			                      [
				                      'marked' => 42,
				                      'last'   => 9000,
				                      'done'   => true,
			                      ],
		                      )
		;

		$tester = new CommandTester( new Refresh( $this->mimeTypes, $this->metadataService ) );

		$this->assertSame( Command::SUCCESS, $tester->execute( [ '--all' => true ] ) );
		$this->assertStringContainsString( 'Queued 42 files', $tester->getDisplay() );
	}

	public function testDeleteIsRefusedWhileDetectionIsOn(): void
	{
		$this->mimeTypes->method( 'refusalToDelete' )
		                ->willReturn( MimeTypeService::STATE_ENABLED )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'deleteMimeTypes' )
		;

		$tester = new CommandTester( new Delete( $this->mimeTypes, $this->metadataService ) );

		$this->assertSame( Command::FAILURE, $tester->execute( [] ) );
		$this->assertStringContainsString( 'fcias:mimetype:disable', $tester->getDisplay() );
	}

	/**
	 * Requested and queued first, so that a deletion cut short is finished
	 * by the job; cleared when the command is done.
	 */
	public function testDeleteRequestsDeletesAndClears(): void
	{
		$calls = [];

		$this->mimeTypes->method( 'requestDelete' )
		                ->willReturnCallback(
			                static function() use ( &$calls ): void
			                {
				                $calls[] = 'request';
			                },
		                )
		;
		$this->metadataService->method( 'deleteMimeTypes' )
		                      ->willReturnCallback(
			                      static function() use ( &$calls ): array
			                      {
				                      $calls[] = 'delete';

				                      return [
					                      'deleted' => 12,
					                      'done'    => true,
				                      ];
			                      },
		                      )
		;
		$this->mimeTypes->method( 'setDeleteRequestedAt' )
		                ->willReturnCallback(
			                static function( ?int $at ) use ( &$calls ): void
			                {
				                $calls[] = $at === null
					                ? 'clear'
					                : 'set';
			                },
		                )
		;

		$tester = new CommandTester( new Delete( $this->mimeTypes, $this->metadataService ) );

		$this->assertSame( Command::SUCCESS, $tester->execute( [] ) );
		$this->assertSame( [ 'request', 'delete', 'clear' ], $calls );
		$this->assertStringContainsString( 'Deleted the MIME types of 12 files', $tester->getDisplay() );
	}

	//  private methods
	private function given(
		bool $enabled,
		bool $available,
	): void
	{
		$this->mimeTypes->method( 'isEnabled' )
		                ->willReturn( $enabled )
		;
		$this->mimeTypes->method( 'isAvailable' )
		                ->willReturn( $available )
		;
	}
}
