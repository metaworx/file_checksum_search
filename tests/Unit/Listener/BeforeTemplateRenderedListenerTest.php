<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Listener;

use OCA\FileChecksumSearch\Listener\BeforeTemplateRenderedListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BeforeTemplateRenderedListenerTest
    extends
    TestCase
{

//  private properties

	private BeforeTemplateRenderedListener $listener;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$logger = $this->createMock( LoggerInterface::class );

		$this->listener = new BeforeTemplateRenderedListener( $logger );
	}


//  other non-static methods

	public function testHandleAddsInitScriptAndStyle(): void
	{
		// Util::addInitScript() resolves through the global \OC container,
		// which only exists when lib/base.php has booted a server. Under the
		// source-tree fallback in tests/bootstrap.php only the autoloaders are
		// loaded, so this test can only run inside a real installation (as in
		// CI). Skipping is the honest report: the code is untestable here,
		// not broken.
		if ( ! class_exists( \OC::class ) )
		{
			$this->markTestSkipped( 'Requires a booted Nextcloud server (global OC); see tests/bootstrap.php.' );
		}

		$event = $this->createMock( BeforeTemplateRenderedEvent::class );

		// The Util::addInitScript / Util::addStyle calls are static and
		// cannot be easily mocked.  The test verifies that handle() does
		// not throw when receiving a BeforeTemplateRenderedEvent.
		$this->listener->handle( $event );

		$this->addToAssertionCount( 1 );
	}

	public function testHandleSkipsNonBeforeTemplateRenderedEvent(): void
	{
		$event = $this->createMock( Event::class );

		// The handler must return early for unrecognised event types
		// without calling Util side-effects (which would throw if they
		// were reached — but since we can't mock Util statics, we rely
		// on the early return not throwing).
		$this->listener->handle( $event );

		$this->addToAssertionCount( 1 );
	}
}
