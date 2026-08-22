<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\TableNameService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TableNameServiceTest
	extends
	TestCase
{

	private function makeService( string $prefix ): TableNameService
	{

		/** @var MockObject|IConfig $config */
		$config = $this->createMock( IConfig::class );
		$config->method( 'getSystemValueString' )
		       ->with( 'dbtableprefix', 'oc_' )
		       ->willReturn( $prefix )
		;

		return new TableNameService( $config );
	}


	public function testGetPrefixReturnsConfiguredPrefix(): void
	{

		$service = $this->makeService( 'oc_' );

		$this->assertSame( 'oc_', $service->getPrefix() );
	}


	public function testGetPrefixReturnsNonDefaultPrefix(): void
	{

		// Regression-relevant: README explicitly documents that FCIAS
		// must read a non-default dbtableprefix correctly.
		$service = $this->makeService( 'nc_custom_' );

		$this->assertSame( 'nc_custom_', $service->getPrefix() );
	}


	public function testGetFilecacheTableNameUsesConfiguredPrefix(): void
	{

		$service = $this->makeService( 'oc_' );

		$this->assertSame( 'oc_filecache', $service->getFilecacheTableName() );
	}


	public function testGetFilecacheTableNameWithNonDefaultPrefix(): void
	{

		$service = $this->makeService( 'nc_custom_' );

		$this->assertSame( 'nc_custom_filecache', $service->getFilecacheTableName() );
	}


	public function testPrefixIsReadOnceAtConstructionTime(): void
	{

		/** @var MockObject|IConfig $config */
		$config = $this->createMock( IConfig::class );
		$config->expects( $this->once() )
		       ->method( 'getSystemValueString' )
		       ->with( 'dbtableprefix', 'oc_' )
		       ->willReturn( 'oc_' )
		;

		$service = new TableNameService( $config );

		// Calling the getters repeatedly must not re-read config.
		$service->getPrefix();
		$service->getPrefix();
		$service->getFilecacheTableName();
	}

}
