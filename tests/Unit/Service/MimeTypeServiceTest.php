<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use LogicException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\MimeTypeDelete;
use OCA\FileChecksumSearch\BackgroundJob\MimeTypeRefresh;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCA\FileChecksumSearch\Service\MimeTypeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The switch, what detection answers, the ids it stores a type by, and the
 * rules for switching it on, refreshing and deleting.
 */
class MimeTypeServiceTest
    extends
    TestCase
{

//  private properties

	private IAppConfig&MockObject        $appConfig;

	private IMimeTypeDetector&MockObject $detector;

	private IMimeTypeLoader&MockObject   $loader;

	private IJobList&MockObject          $jobList;

	private MimeTypeService              $service;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->appConfig = $this->createMock( IAppConfig::class );
		$this->detector  = $this->createMock( IMimeTypeDetector::class );
		$this->loader    = $this->createMock( IMimeTypeLoader::class );
		$this->jobList   = $this->createMock( IJobList::class );

		$time = $this->createMock( ITimeFactory::class );
		$time->method( 'getTime' )
		     ->willReturn( 1_759_300_000 )
		;

		$this->service = new MimeTypeService( $this->appConfig, $this->detector, $this->loader, $this->jobList, $time );
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

	/**
	 * A deletion requested, the types are not detected, the switch or not:
	 * switching on refuses it, and a config edited by hand can still leave
	 * both.
	 */
	public function testNothingIsDetectedWhileADeletionIsRequested(): void
	{
		$this->appConfig->method( 'getValueBool' )
		                ->willReturn( true )
		;
		$this->appConfig->method( 'getValueInt' )
		                ->with( Application::APP_ID, ConfigLexicon::MIMETYPE_DELETE_REQUESTED_AT )
		                ->willReturn( 1_759_300_000 )
		;

		$this->assertFalse( $this->service->isActive() );
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

	/** A deletion running holds detection off, whatever the switch says. */
	public function testTheStateIsDeletingWhileADeletionRuns(): void
	{
		$this->given( true, 1_759_299_000 );

		$this->assertSame( MimeTypeService::STATE_DELETING, $this->service->getState() );
	}

	public function testTheStateIsDisabledWhileSwitchedOff(): void
	{
		$this->given( false, 0 );

		$this->assertSame( MimeTypeService::STATE_DISABLED, $this->service->getState() );
	}

	public function testTheStateIsEnabledWhileSwitchedOn(): void
	{
		$this->given( true, 0 );

		$this->assertSame( MimeTypeService::STATE_ENABLED, $this->service->getState() );
	}

	/** While a deletion runs, the types detected meanwhile would go with it. */
	public function testSwitchingOnIsRefusedWhileADeletionRuns(): void
	{
		$this->given( false, 1_759_299_000 );

		$this->assertSame( MimeTypeService::STATE_DELETING, $this->service->refusalToEnable() );

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueBool' )
		;
		$this->expectException( LogicException::class );

		$this->service->enable();
	}

	public function testSwitchingOnWritesTheSwitch(): void
	{
		$this->given( false, 0 );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueBool' )
		                ->with( Application::APP_ID, ConfigLexicon::MIMETYPE_DETECTION, true )
		;

		$this->service->enable();
	}

	/** Only what is detected can be refreshed. */
	public function testARefreshIsRefusedWhileDetectionIsOff(): void
	{
		$this->given( false, 0 );

		$this->assertSame( MimeTypeService::STATE_DISABLED, $this->service->refusalToRefresh() );

		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;
		$this->expectException( LogicException::class );

		$this->service->requestRefresh( false );
	}

	public function testARefreshIsQueuedFromTheFirstFile(): void
	{
		$this->given( true, 0 );

		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with(
			              MimeTypeRefresh::class,
			              [
				              'all'   => true,
				              'after' => 0,
			              ],
		              )
		;

		$this->service->requestRefresh( true );
	}

	/** Switched on, the types it detects would be deleted with the rest. */
	public function testADeletionIsRefusedWhileDetectionIsOn(): void
	{
		$this->given( true, 0 );

		$this->assertSame( MimeTypeService::STATE_ENABLED, $this->service->refusalToDelete() );

		$this->jobList->expects( $this->never() )
		              ->method( 'add' )
		;
		$this->expectException( LogicException::class );

		$this->service->requestDelete();
	}

	/** The time of the request is kept, and the job queued once. */
	public function testADeletionIsRequestedNowAndQueued(): void
	{
		$this->given( false, 0 );

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueInt' )
		                ->with( Application::APP_ID, ConfigLexicon::MIMETYPE_DELETE_REQUESTED_AT, 1_759_300_000 )
		;
		$this->jobList->method( 'has' )
		              ->willReturn( false )
		;
		$this->jobList->expects( $this->once() )
		              ->method( 'add' )
		              ->with( MimeTypeDelete::class )
		;

		$this->service->requestDelete();
	}

	/** Asked again while one runs, the deletion is requested anew. */
	public function testADeletionRequestedAgainWhileOneRunsIsNotRefused(): void
	{
		$this->given( false, 1_759_299_000 );

		$this->assertNull( $this->service->refusalToDelete() );
	}

	public function testNoDeletionRequestedRemovesTheKey(): void
	{
		$this->appConfig->expects( $this->once() )
		                ->method( 'deleteKey' )
		                ->with( Application::APP_ID, ConfigLexicon::MIMETYPE_DELETE_REQUESTED_AT )
		;

		$this->service->setDeleteRequestedAt( null );
	}

	//  private methods
	/** The switch, and when a deletion was requested: 0 for none. */
	private function given(
		bool $enabled,
		int  $deleteRequestedAt,
	): void
	{
		$this->appConfig->method( 'getValueBool' )
		                ->willReturn( $enabled )
		;
		$this->appConfig->method( 'getValueInt' )
		                ->willReturn( $deleteRequestedAt )
		;
	}
}
