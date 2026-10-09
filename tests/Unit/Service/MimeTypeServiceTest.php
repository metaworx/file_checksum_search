<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCA\FileChecksumSearch\Service\MimeTypeService;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The switch, what detection answers, and the ids it stores a type by.
 */
class MimeTypeServiceTest
    extends
    TestCase
{

//  private properties

	private IAppConfig&MockObject        $appConfig;

	private IMimeTypeDetector&MockObject $detector;

	private IMimeTypeLoader&MockObject   $loader;

	private MimeTypeService              $service;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->appConfig = $this->createMock( IAppConfig::class );
		$this->detector  = $this->createMock( IMimeTypeDetector::class );
		$this->loader    = $this->createMock( IMimeTypeLoader::class );

		$this->service = new MimeTypeService( $this->appConfig, $this->detector, $this->loader );
	}


//  other non-static methods

	public function testTheSwitchIsReadFromTheAppConfig(): void
	{
		$this->appConfig->expects( $this->exactly( 2 ) )
		                ->method( 'getValueBool' )
		                ->with( Application::APP_ID, ConfigLexicon::MIMETYPE_DETECTION )
		                ->willReturnOnConsecutiveCalls( false, true )
		;

		$this->assertFalse( $this->service->isEnabled() );
		$this->assertTrue( $this->service->isEnabled() );
	}

	/**
	 * Nextcloud requires the Fileinfo extension, so every instance this
	 * runs on has it: active is the switch.
	 */
	public function testActiveIsTheSwitchWherePhpCanDetect(): void
	{
		$this->assertTrue( $this->service->isAvailable() );

		$this->appConfig->method( 'getValueBool' )
		                ->willReturnOnConsecutiveCalls( false, true )
		;

		$this->assertFalse( $this->service->isActive() );
		$this->assertTrue( $this->service->isActive() );
	}

	public function testNoDeletionIsRequestedWhileTheTimeIsZero(): void
	{
		$this->appConfig->method( 'getValueInt' )
		                ->with( Application::APP_ID, ConfigLexicon::MIMETYPE_DELETE_REQUESTED_AT )
		                ->willReturnOnConsecutiveCalls( 0, 1_759_300_000 )
		;

		$this->assertNull( $this->service->getDeleteRequestedAt() );
		$this->assertSame( 1_759_300_000, $this->service->getDeleteRequestedAt() );
	}

	public function testDetectReturnsWhatLibmagicNames(): void
	{
		$this->detector->expects( $this->once() )
		               ->method( 'detectString' )
		               ->with( '%PDF-1.4' )
		               ->willReturn( "application/pdf\n" )
		;

		$this->assertSame( 'application/pdf', $this->service->detect( '%PDF-1.4' ) );
	}

	public function testNoAnswerIsNoType(): void
	{
		$this->detector->method( 'detectString' )
		               ->willReturn( '' )
		;

		$this->assertNull( $this->service->detect( 'anything' ) );
	}

	/** `oc_mimetypes.mimetype` holds 255 characters, and the insert would fail. */
	public function testATypeTooLongToStoreIsNoType(): void
	{
		$this->detector->method( 'detectString' )
		               ->willReturn( 'application/' . str_repeat( 'x', 244 ) )
		;

		$this->assertNull( $this->service->detect( 'anything' ) );
	}

	public function testATypeIsStoredByCoresId(): void
	{
		$this->loader->expects( $this->once() )
		             ->method( 'getId' )
		             ->with( 'application/pdf' )
		             ->willReturn( 17 )
		;
		$this->loader->expects( $this->once() )
		             ->method( 'getMimetypeById' )
		             ->with( 17 )
		             ->willReturn( 'application/pdf' )
		;

		$this->assertSame( 17, $this->service->idOf( 'application/pdf' ) );
		$this->assertSame( 'application/pdf', $this->service->typeOf( 17 ) );
	}

	/** `oc_mimetypes` counts from 1: 0 names nothing, and is not asked about. */
	public function testIdZeroNamesNoType(): void
	{
		$this->loader->expects( $this->never() )
		             ->method( 'getMimetypeById' )
		;

		$this->assertNull( $this->service->typeOf( 0 ) );
	}
}
