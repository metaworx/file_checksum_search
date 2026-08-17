<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Settings;

use OCA\FileChecksumSearch\Settings\Admin;
use OCA\FileChecksumSearch\Settings\AdminSection;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;

class AdminSectionTest
	extends
	FciasUnitTestCase
{

	private MockObject|IL10N         $l10n;

	private MockObject|IURLGenerator $urlGenerator;

	private AdminSection             $section;


	protected function setUp(): void
	{

		parent::setUp();

		$this->l10n         = $this->createMock( IL10N::class );
		$this->urlGenerator = $this->createMock( IURLGenerator::class );
		$this->section      = new AdminSection( $this->urlGenerator, $this->l10n );
	}


	public function testGetIdReturnsAppId(): void
	{

		$this->assertSame( 'file_checksum_search', $this->section->getID() );
	}


	public function testGetPriorityIsWithinValidRange(): void
	{

		$priority = $this->section->getPriority();

		$this->assertGreaterThanOrEqual( 0, $priority );
		$this->assertLessThanOrEqual( 99, $priority );
	}


	public function testGetNameUsesL10n(): void
	{

		$this->l10n->expects( $this->once() )
		           ->method( 't' )
		           ->with( 'File Checksum Index & Search' )
		           ->willReturn( 'Translated name' )
		;

		$this->assertSame( 'Translated name', $this->section->getName() );
	}


	public function testGetIconUsesAppSvg(): void
	{

		$this->urlGenerator->expects( $this->once() )
		                   ->method( 'imagePath' )
		                   ->with( 'file_checksum_search', 'app.svg' )
		                   ->willReturn( '/apps/file_checksum_search/img/app.svg' )
		;

		$this->assertSame( '/apps/file_checksum_search/img/app.svg', $this->section->getIcon() );
	}


	public function testAdminSettingsUseSameSectionId(): void
	{

		$admin = new Admin();

		$this->assertSame( $this->section->getID(), $admin->getSection() );
	}

}
