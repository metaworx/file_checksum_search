<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Listener\AppDisableListener;
use OCP\App\Events\AppDisableEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AppDisableListenerTest
	extends
	TestCase
{

	private MockObject|LoggerInterface $logger;

	private AppDisableListener         $listener;


	protected function setUp(): void
	{

		parent::setUp();

		$this->logger   = $this->createMock( LoggerInterface::class );
		$this->listener = new AppDisableListener( $this->logger );
	}


	public function testHandleDispatchesOnMatchingAppDisableEvent(): void
	{

		$event = $this->createMock( AppDisableEvent::class );
		$event->method( 'getAppId' )
		      ->willReturn( Application::APP_ID )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'info' )
		             ->with(
			             $this->stringContains( 'FCIAS AppDisableListener' ),
			             $this->arrayHasKey( 'app' ),
		             )
		;

		$this->listener->handle( $event );
	}


	public function testHandleIgnoresOtherAppEvents(): void
	{

		$event = $this->createMock( Event::class );

		$this->logger->expects( $this->never() )
		             ->method( 'info' )
		;

		$this->listener->handle( $event );
	}

}
