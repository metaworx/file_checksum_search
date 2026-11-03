<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\TableNameService;
use OCA\FileChecksumSearch\Tests\Unit\AppDoubles;
use PHPUnit\Framework\TestCase;

class TableNameServiceTest
    extends
    TestCase
{
	use AppDoubles;


//  other non-static methods

	private function makeService( string $prefix ): TableNameService
	{
		$this->systemValues['dbtableprefix'] = $prefix;

		return new TableNameService( $this->systemConfig() );
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

	public function testGetPrefixIsServersDefaultWhereNoneIsConfigured(): void
	{
		$service = new TableNameService( $this->systemConfig() );

		$this->assertSame( 'oc_', $service->getPrefix() );
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
		$this->systemValues['dbtableprefix'] = 'oc_';

		$config = $this->systemConfig();
		$config->expects( $this->once() )
		       ->method( 'getSystemValueString' )
		       ->with( 'dbtableprefix', 'oc_' )
		;

		$service = new TableNameService( $config );

		// Calling the getters repeatedly must not re-read config — the
		// expects( once() ) above is the real assertion, counting the reads
		// while the double answers; these check that the cached answers stay
		// the same rather than discarding them.
		$this->assertSame( 'oc_', $service->getPrefix() );
		$this->assertSame( 'oc_', $service->getPrefix() );
		$this->assertSame( 'oc_filecache', $service->getFilecacheTableName() );
	}
}
