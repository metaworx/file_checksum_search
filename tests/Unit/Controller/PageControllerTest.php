<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\PageController;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\IRequest;

class PageControllerTest
	extends
	FciasUnitTestCase
{

	private PageController $controller;


	protected function setUp(): void
	{

		parent::setUp();

		$this->controller = new PageController(
			'file_checksum_search',
			$this->createMock( IRequest::class ),
		);
	}


	public function testGetDocsReturnsBundledDocumentation(): void
	{

		$response = $this->controller->getDocs();

		$data = $response->getData();

		$this->assertArrayHasKey( 'docs', $data );
		$this->assertCount( 6, $data['docs'] );

		$names = array_map(
			static fn(
				$doc,
			) => $doc['name'],
			$data['docs'],
		);

		$this->assertSame(
			[
				'docs/FAQ.md',
				'README.md',
				'docs/api-v1-openapi.yaml',
				'docs/api-v1.md',
				'openapi.json',
				'LICENSE',
			],
			$names,
		);

		foreach ( $data['docs'] as $doc )
		{
			$this->assertArrayHasKey( 'content', $doc );
			$this->assertIsString( $doc['content'] );
			$this->assertNotSame( '', $doc['content'] );
		}
	}


	public function testGetHelpReturnsPublicDocumentation(): void
	{

		$response = $this->controller->getHelp();

		$data = $response->getData();

		$this->assertArrayHasKey( 'docs', $data );
		$this->assertCount( 2, $data['docs'] );

		$names = array_map(
			static fn(
				$doc,
			) => $doc['name'],
			$data['docs'],
		);

		$this->assertSame(
			[
				'docs/FAQ.md',
				'docs/HELP.md',
			],
			$names,
		);

		foreach ( $data['docs'] as $doc )
		{
			$this->assertArrayHasKey( 'content', $doc );
			$this->assertIsString( $doc['content'] );
			$this->assertNotSame( '', $doc['content'] );
		}
	}

}
