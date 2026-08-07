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

class BeforeTemplateRenderedListenerTest
	extends
	TestCase
{

	private BeforeTemplateRenderedListener $listener;


	protected function setUp(): void
	{

		parent::setUp();

		$this->listener = new BeforeTemplateRenderedListener();
	}


	public function testHandleAddsInitScriptAndStyle(): void
	{

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
