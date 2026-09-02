<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\RuleProcessingJob;
use OCA\FileChecksumSearch\Listener\UserDeletedListener;
use OCP\App\Events\AppDisableEvent;
use OCP\IAppConfig;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class UserDeletedListenerTest
	extends
	TestCase
{

	private IAppConfig&MockObject      $appConfig;

	private LoggerInterface&MockObject $logger;

	private UserDeletedListener        $listener;


	protected function setUp(): void
	{

		parent::setUp();

		$this->appConfig = $this->createMock( IAppConfig::class );
		$this->logger    = $this->createMock( LoggerInterface::class );
		$this->listener  = new UserDeletedListener( $this->appConfig, $this->logger );
	}


	public function testADeletedUserMakesThePurgeDueNow(): void
	{

		$event = $this->createMock( UserDeletedEvent::class );
		$event->method( 'getUid' )
		      ->willReturn( 'alice' )
		;

		// Zero, not "now": the job compares against its interval, and zero
		// is the one value that is due whatever the interval is.
		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueInt' )
		                ->with( Application::APP_ID, RuleProcessingJob::ORPHAN_PURGE_LAST_RUN, 0 )
		;

		$this->listener->handle( $event );
	}


	public function testOtherEventsAreIgnored(): void
	{

		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueInt' )
		;

		$this->listener->handle( $this->createMock( AppDisableEvent::class ) );
	}


	/**
	 * The deletion has already happened when this runs; nothing here may
	 * turn a successful deletion into an error the caller sees.
	 */
	public function testAFailureIsLoggedAndNotThrown(): void
	{

		$this->appConfig->method( 'setValueInt' )
		                ->willThrowException( new RuntimeException( 'config store is read-only' ) )
		;

		$this->logger->expects( $this->once() )
		             ->method( 'warning' )
		;

		$this->listener->handle( $this->createMock( UserDeletedEvent::class ) );

		$this->addToAssertionCount( 1 );
	}

}
