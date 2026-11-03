<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\DocumentImages;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * A bundled document's images, pointed at the app's own copy: a relative
 * target that names an image inside the app becomes its web path, and
 * nothing else changes. The app is a temporary directory with a README, a
 * guide under docs/ and two screenshots.
 */
class DocumentImagesTest
    extends
    TestCase
{

//  private properties

	private string $appRoot;

	private DocumentImages $images;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->appRoot = sys_get_temp_dir() . '/fcias-document-images-test-' . getmypid();
		mkdir( $this->appRoot . '/docs/Screenshots', 0o755, true );
		touch( $this->appRoot . '/docs/Screenshots/Page.png' );
		touch( $this->appRoot . '/docs/Screenshots/Pane.gif' );
		touch( $this->appRoot . '/docs/FAQ.md' );

		$urlGenerator = $this->createMock( IURLGenerator::class );
		$urlGenerator->method( 'linkTo' )
		             ->willReturnCallback( fn( string $app, string $file ): string => '/custom_apps/' . $app . '/' . $file )
		;

		$this->images = new DocumentImages( $urlGenerator );
	}


//  other non-static methods

	protected function tearDown(): void
	{
		foreach ( [ 'docs/Screenshots/Page.png', 'docs/Screenshots/Pane.gif', 'docs/FAQ.md' ] as $file )
		{
			unlink( $this->appRoot . '/' . $file );
		}

		rmdir( $this->appRoot . '/docs/Screenshots' );
		rmdir( $this->appRoot . '/docs' );
		rmdir( $this->appRoot );

		parent::tearDown();
	}

	public function testAReferenceDefinitionInTheGuideNamesTheAppsCopy(): void
	{
		self::assertSame(
			"![The page][shot]\n\n[shot]: /custom_apps/file_checksum_search/docs/Screenshots/Page.png\n",
			$this->images->servedByTheApp( "![The page][shot]\n\n[shot]: Screenshots/Page.png\n", 'docs/user-guide.md', $this->appRoot ),
		);
	}

	/** The README sits at the top: its targets start with docs/. */
	public function testTheReadmesTargetsResolveFromTheTop(): void
	{
		self::assertSame(
			'[shot]: /custom_apps/file_checksum_search/docs/Screenshots/Pane.gif',
			$this->images->servedByTheApp( '[shot]: docs/Screenshots/Pane.gif', 'README.md', $this->appRoot ),
		);
	}

	public function testAnInlineImageNamesTheAppsCopy(): void
	{
		self::assertSame(
			"See ![The page,\nopened](/custom_apps/file_checksum_search/docs/Screenshots/Page.png) here.",
			$this->images->servedByTheApp( "See ![The page,\nopened](Screenshots/Page.png) here.", 'docs/FAQ.md', $this->appRoot ),
		);
	}

	public function testATargetInAngleBracketsKeepsThem(): void
	{
		self::assertSame(
			'[shot]: </custom_apps/file_checksum_search/docs/Screenshots/Page.png>',
			$this->images->servedByTheApp( '[shot]: <Screenshots/Page.png>', 'docs/FAQ.md', $this->appRoot ),
		);
	}

	/**
	 * A link to a document, an anchor, an absolute URL, an absolute path, a
	 * missing image and one outside the app all stay as they are.
	 */
	public function testWhatIsNotAnImageInsideTheAppStays(): void
	{
		$markdown = implode( "\n", [
			'[faq]: FAQ.md',
			'[readme]: ../README.md#installing-and-updating',
			'[top]: #top',
			'[remote]: https://example.com/Page.png',
			'[rooted]: /docs/Screenshots/Page.png',
			'[gone]: Screenshots/Gone.png',
			'[outside]: ../../Page.png',
			'![remote](https://example.com/Page.png)',
		] );

		self::assertSame( $markdown, $this->images->servedByTheApp( $markdown, 'docs/FAQ.md', $this->appRoot ) );
	}
}
