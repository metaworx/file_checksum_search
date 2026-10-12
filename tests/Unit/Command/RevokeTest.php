<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\Revoke;
use OCA\FileChecksumSearch\Service\AppConfigService;
use OCA\FileChecksumSearch\Service\ExportService;
use OCA\FileChecksumSearch\Service\MetadataService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `fcias:revoke`: `fcias:reset` with the hashes alone, whatever is named,
 * and the same restraint — nothing without `--force`.
 */
class RevokeTest
    extends
    TestCase
{

//  private properties

	private AppConfigService&MockObject $appConfigService;

	private MetadataService&MockObject  $metadataService;

	private ExportService&MockObject    $exportService;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->appConfigService = $this->createMock( AppConfigService::class );
		$this->metadataService  = $this->createMock( MetadataService::class );
		$this->exportService    = $this->createMock( ExportService::class );

		$this->metadataService->method( 'countHashedFiles' )
		                      ->willReturn( 1140 )
		;
	}


//  other non-static methods

	public function testItIsNamedRevoke(): void
	{
		$command = $this->command();

		$this->assertSame( 'file-checksum-search:revoke', $command->getName() );
		$this->assertSame( [ 'fcias:revoke' ], $command->getAliases() );
	}

	public function testWithoutForceItSaysWhatItWouldRevokeAndChangesNothing(): void
	{
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markAllRevoked' )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearHashesNow' )
		;

		$tester = new CommandTester( $this->command() );

		$this->assertSame( Command::SUCCESS, $tester->execute( [] ) );
		$this->assertStringContainsString( '1140 files would lose their checksums', $tester->getDisplay() );
		$this->assertStringNotContainsString( 'config', $tester->getDisplay() );
		$this->assertStringNotContainsString( 'status', $tester->getDisplay() );
	}

	/**
	 * The hashes, and nothing of the configuration or the queue.
	 */
	public function testForcedItRevokesTheHashesAlone(): void
	{
		$this->metadataService->expects( $this->once() )
		                      ->method( 'markAllRevoked' )
		                      ->willReturn( 1140 )
		;
		$this->metadataService->expects( $this->never() )
		                      ->method( 'clearQueueState' )
		;
		$this->appConfigService->expects( $this->never() )
		                       ->method( 'clear' )
		;

		$tester = new CommandTester( $this->command() );

		$this->assertSame( Command::SUCCESS, $tester->execute( [ '--force' => true ] ) );
		$this->assertStringContainsString( '1140 files revoked', $tester->getDisplay() );
		$this->assertStringNotContainsString( 'deprecated', $tester->getDisplay() );
	}

	public function testNowClearsInTheForeground(): void
	{
		$this->metadataService->expects( $this->never() )
		                      ->method( 'markAllRevoked' )
		;
		$this->metadataService->expects( $this->once() )
		                      ->method( 'clearHashesNow' )
		                      ->willReturn( 7 )
		;

		$tester = new CommandTester( $this->command() );
		$tester->execute(
			[
				'--force' => true,
				'--now'   => true,
			],
		);

		$this->assertStringContainsString( '7 files cleared', $tester->getDisplay() );
	}

	/**
	 * `--config` and `--status` are `fcias:reset`'s.
	 */
	public function testItTakesNoSliceOption(): void
	{
		$this->expectException( InvalidOptionException::class );

		( new CommandTester( $this->command() ) )->execute( [ '--config' => true ] );
	}

	public function testTheAuditLineNamesTheCommand(): void
	{
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )
		       ->method( 'warning' )
		       ->with(
			       $this->stringContains( '{command} carried out' ),
			       $this->callback(
				       static fn(
					       array $context,
				       ): bool => $context['command'] === 'file-checksum-search:revoke'
					       && $context['slices'] === 'hashes',
			       ),
		       )
		;

		( new CommandTester( $this->command( $logger ) ) )->execute( [ '--force' => true ] );
	}

	private function command( ?LoggerInterface $logger = null ): Revoke
	{
		return new Revoke(
			$this->appConfigService,
			$this->metadataService,
			$this->exportService,
			$logger ?? $this->createMock( LoggerInterface::class ),
		);
	}
}
