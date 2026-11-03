<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\PageController;
use OCA\FileChecksumSearch\Service\DocumentImages;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\IRequest;
use OCP\IURLGenerator;

class PageControllerTest
    extends
    FciasUnitTestCase
{

//  private properties

	private PageController $controller;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$urlGenerator = $this->createMock( IURLGenerator::class );
		$urlGenerator->method( 'linkTo' )
		             ->willReturnCallback( fn( string $app, string $file ): string => '/apps/' . $app . '/' . $file )
		;

		$this->controller = new PageController(
			'file_checksum_search',
			$this->createMock( IRequest::class ),
			$this->englishL10n(),
			new DocumentImages( $urlGenerator ),
		);
	}


//  other non-static methods

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
				'docs/user-guide.md',
				'README.md',
				'docs/api-v1-openapi.yaml',
				'docs/api-v1.md',
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
				'docs/user-guide.md',
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

	/**
	 * Every screenshot a document shows comes from the app itself: the
	 * server's image policy refuses one from anywhere else.
	 */
	public function testTheDocumentsShowTheAppsOwnScreenshots(): void
	{
		$docs = array_column( $this->controller->getDocs()->getData()['docs'], 'content', 'name' );

		$this->assertStringContainsString(
			'[shot-duplicates-page]: /apps/file_checksum_search/docs/Screenshots/Duplicates-Page.png',
			$docs['docs/user-guide.md'],
		);
		$this->assertStringContainsString(
			'[shot-admin-rules]: /apps/file_checksum_search/docs/Screenshots/Admin-Rules.png',
			$docs['README.md'],
		);
		$this->assertStringContainsString(
			'[shot-admin-advanced]: /apps/file_checksum_search/docs/Screenshots/Admin-Advanced.png',
			$docs['docs/FAQ.md'],
		);

		foreach ( [ 'docs/FAQ.md', 'docs/user-guide.md', 'README.md' ] as $name )
		{
			$this->assertDoesNotMatchRegularExpression( '~^\[shot-[^\]]+\]: (?!/apps/)~m', $docs[ $name ], $name );
		}
	}
}
