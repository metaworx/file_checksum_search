<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\Update;
use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The app's update command is the scoped package's under the app's names:
 * what it is called and which options it has. What it takes from the store
 * is the package's suite.
 */
class UpdateTest
    extends
    TestCase
{

//  other non-static methods

	public function testIsNamedForTheApp(): void
	{
		$command = $this->command();

		self::assertSame( 'file-checksum-search:update', $command->getName() );
		self::assertSame( [ 'fcias:update' ], $command->getAliases() );
	}

	public function testHasTheOptions(): void
	{
		$definition = $this->command()->getDefinition();

		foreach ( [ 'check', 'stable', 'nightly', 'output' ] as $option )
		{
			self::assertTrue( $definition->hasOption( $option ), '--' . $option );
		}

		self::assertSame( 'plain', $definition->getOption( 'output' )->getDefault() );
	}

	/**
	 * Both kinds at once is a usage error, before the store is asked.
	 */
	public function testStableAndNightlyExcludeEachOther(): void
	{
		$tester = new CommandTester( $this->command() );

		self::assertSame( 2, $tester->execute( [ '--stable' => true, '--nightly' => true ] ) );
		self::assertStringContainsString( '--stable and --nightly exclude each other', $tester->getDisplay() );
	}

	/**
	 * As the server's container builds it: the five services, none of them
	 * asked for anything here.
	 */
	private function command(): Update
	{
		return new Update(
			$this->createMock( IClientService::class ),
			$this->createMock( IConfig::class ),
			$this->createMock( IAppManager::class ),
			$this->createMock( IAppDataFactory::class ),
			$this->createMock( IAppConfig::class ),
		);
	}
}
