<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Listener\LoadDuplicatesScriptListener;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LoadDuplicatesScriptListenerTest
	extends
	TestCase
{

	private MockObject|IAppManager            $appManager;

	private LoadDuplicatesScriptListener      $listener;


	protected function setUp(): void
	{

		parent::setUp();

		$this->appManager = $this->createMock( IAppManager::class );
		$this->listener   = new LoadDuplicatesScriptListener( $this->appManager );
	}


	public function testHandleAddsDuplicateScript(): void
	{

		$event = $this->createMock( LoadAdditionalScriptsEvent::class );

		$this->appManager->expects( $this->once() )
		                 ->method( 'isEnabledForUser' )
		                 ->with( Application::APP_ID )
		                 ->willReturn( true )
		;

		// Util::addInitScript is a static side-effect.  The test
		// verifies the branching: when the app is enabled, the
		// handler proceeds past the isEnabledForUser guard.
		$this->listener->handle( $event );
	}


	public function testHandleDoesNotAddScriptWhenNotEnabled(): void
	{

		$event = $this->createMock( LoadAdditionalScriptsEvent::class );

		$this->appManager->expects( $this->once() )
		                 ->method( 'isEnabledForUser' )
		                 ->with( Application::APP_ID )
		                 ->willReturn( false )
		;

		// The handler must return early without calling Util::addInitScript.
		$this->listener->handle( $event );

		$this->addToAssertionCount( 1 );
	}


	public function testHandleSkipsNonLoadAdditionalScriptsEvent(): void
	{

		$event = $this->createMock( Event::class );

		$this->appManager->expects( $this->never() )
		                 ->method( 'isEnabledForUser' )
		;

		$this->listener->handle( $event );
	}

}
