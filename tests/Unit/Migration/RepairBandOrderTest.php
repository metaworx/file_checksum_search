<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Migration;

use OCA\FileChecksumSearch\Migration\RepairBandOrder;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class RepairBandOrderTest
	extends
	TestCase
{

	private MockObject|RuleService     $ruleService;

	private MockObject|LoggerInterface $logger;

	private MockObject|IOutput         $output;

	private RepairBandOrder            $step;


	protected function setUp(): void
	{

		parent::setUp();

		$this->ruleService = $this->createMock( RuleService::class );
		$this->logger      = $this->createMock( LoggerInterface::class );
		$this->output      = $this->createMock( IOutput::class );

		$this->step = new RepairBandOrder( $this->ruleService, $this->logger );
	}


	public function testReportsWhatItSorted(): void
	{

		$this->ruleService->expects( $this->once() )
		                  ->method( 'migrateToBands' )
		                  ->willReturn( [
			                  'pinnedId' => 'default',
			                  'rules'    => 3,
		                  ] )
		;

		$this->output->expects( $this->once() )
		             ->method( 'info' )
		             ->with( $this->stringContains( '3 hash-generation rule(s)' ) )
		;

		$this->step->run( $this->output );
	}


	public function testSaysSoWhenThereIsNothingToSort(): void
	{

		$this->ruleService->method( 'migrateToBands' )
		                  ->willReturn( [
			                  'pinnedId' => null,
			                  'rules'    => 0,
		                  ] )
		;

		$this->output->expects( $this->once() )
		             ->method( 'info' )
		             ->with( $this->stringContains( 'No hash-generation rules' ) )
		;

		$this->step->run( $this->output );
	}


	public function testAFailureWarnsAndLogsRatherThanBlockingTheUpgrade(): void
	{

		// A throwing repair step aborts the whole Nextcloud upgrade. Rule
		// order is recoverable from the admin page, so this must degrade to
		// a warning rather than wedge the instance mid-upgrade.
		$this->ruleService->method( 'migrateToBands' )
		                  ->willThrowException( new RuntimeException( 'config write failed' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'error' )
		;

		$this->output->expects( $this->once() )
		             ->method( 'warning' )
		             ->with( $this->stringContains( 'config write failed' ) )
		;

		$this->output->expects( $this->never() )
		             ->method( 'info' )
		;

		$this->step->run( $this->output );
	}


	public function testHasADescriptiveName(): void
	{

		$this->assertStringContainsString( 'bands', $this->step->getName() );
	}

}
