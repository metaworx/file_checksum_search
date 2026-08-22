<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Command\ShowConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ShowConfigTest
	extends
	TestCase
{

	private MockObject|IAppConfig      $appConfig;

	private MockObject|LoggerInterface $logger;

	private CommandTester              $tester;


	protected function setUp(): void
	{

		parent::setUp();

		$this->appConfig = $this->createMock( IAppConfig::class );
		$this->logger     = $this->createMock( LoggerInterface::class );

		$command      = new ShowConfig( $this->appConfig, $this->logger );
		$this->tester = new CommandTester( $command );
	}


	public function testPlainOutputListsKeyValuePairs(): void
	{

		$this->appConfig->method( 'getAllValues' )
		                ->with( Application::APP_ID )
		                ->willReturn( [
			                'installed_version' => '1.9.2',
			                'rule_definitions'   => [ 'a', 'b' ],
		                ] )
		;

		$exitCode = $this->tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( 'installed_version', $display );
		$this->assertStringContainsString( '1.9.2', $display );
		$this->assertStringContainsString( 'rule_definitions', $display );
	}


	public function testPlainOutputFormatsBooleansAsWords(): void
	{

		$this->appConfig->method( 'getAllValues' )
		                ->willReturn( [ 'lazy_enabled' => true, 'strict_mode' => false ] )
		;

		$this->tester->execute( [] );

		$display = $this->tester->getDisplay();
		$this->assertStringContainsString( 'true', $display );
		$this->assertStringContainsString( 'false', $display );
	}


	public function testPlainOutputReportsNoConfigValues(): void
	{

		$this->appConfig->method( 'getAllValues' )
		                ->willReturn( [] )
		;

		$exitCode = $this->tester->execute( [] );

		$this->assertSame( Command::SUCCESS, $exitCode );
		$this->assertStringContainsString( 'No config values found.', $this->tester->getDisplay() );
	}


	public function testJsonOutputFormat(): void
	{

		$this->appConfig->method( 'getAllValues' )
		                ->willReturn( [ 'installed_version' => '1.9.2' ] )
		;

		$this->tester->execute( [ '--output' => 'json' ] );

		$display = $this->tester->getDisplay();
		$decoded = json_decode( trim( $display ), true );
		$this->assertSame( [ 'installed_version' => '1.9.2' ], $decoded );
	}


	public function testJsonPrettyOutputIsIndented(): void
	{

		$this->appConfig->method( 'getAllValues' )
		                ->willReturn( [ 'installed_version' => '1.9.2' ] )
		;

		$this->tester->execute( [ '--output' => 'json_pretty' ] );

		$this->assertStringContainsString( "\n", trim( $this->tester->getDisplay() ) );
	}

}
