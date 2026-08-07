<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Search;

use OCA\FileChecksumSearch\Search\HashSearchProvider;
use OCA\FileChecksumSearch\Service\MetadataService;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HashSearchProviderTest
	extends
	TestCase
{

	private HashSearchProvider $provider;


	protected function setUp(): void
	{

		parent::setUp();

		$this->provider = new HashSearchProvider(
			$this->createMock( MetadataService::class ),
			$this->createMock( IRootFolder::class ),
			$this->createMock( IURLGenerator::class ),
			$this->createMock( LoggerInterface::class ),
		);
	}


	public function testGetIdReturnsProviderId(): void
	{

		$this->assertSame(
			'file_checksum_search_provider',
			$this->provider->getId(),
		);
	}


	public function testGetNameReturnsProviderName(): void
	{

		$this->assertSame(
			'File Checksums',
			$this->provider->getName(),
		);
	}


	public function testGetOrderReturnsInt(): void
	{

		$this->assertSame(
			20,
			$this->provider->getOrder( '', [] ),
		);
	}

}
