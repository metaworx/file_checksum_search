<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Listener;

use OCA\FileChecksumSearch\Listener\BeforeTemplateRenderedListener;
use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BeforeTemplateRenderedListenerTest
    extends
    TestCase
{

//  private properties

	private BeforeTemplateRenderedListener $listener;

	private IInitialState&MockObject       $initialState;

	private ChecksumApi&MockObject         $api;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->initialState = $this->createMock( IInitialState::class );
		$this->api          = $this->createMock( ChecksumApi::class );

		$this->listener = new BeforeTemplateRenderedListener(
			$this->createMock( LoggerInterface::class ),
			$this->initialState,
			$this->api,
		);
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

	/**
	 * The page carries the features, read only where it is rendered: the
	 * sidebar and the Duplicates page ask the server nothing before their
	 * own request.
	 */
	public function testHandleProvidesTheFeaturesLazily(): void
	{
		if ( ! class_exists( \OC::class ) )
		{
			$this->markTestSkipped( 'Requires a booted Nextcloud server (global OC); see tests/bootstrap.php.' );
		}

		$features = [
			'version'    => '1.0.0',
			'features'   => [ 'mimetype' => true ],
			'algorithms' => [ 'allowed' => [ 'sha256' ], 'default' => 'sha256' ],
		];
		$this->api->expects( $this->once() )
		          ->method( 'getFeatures' )
		          ->willReturn( $features )
		;

		$provided = null;
		$this->initialState->expects( $this->once() )
		                   ->method( 'provideLazyInitialState' )
		                   ->with( 'features', $this->isInstanceOf( \Closure::class ) )
		                   ->willReturnCallback(
			                   static function(
				                   string   $key,
				                   \Closure $closure,
			                   ) use
			                   (
				                   &
				                   $provided,
			                   ): void
			                   {
				                   $provided = $closure;
			                   },
		                   )
		;

		$this->listener->handle( $this->createMock( BeforeTemplateRenderedEvent::class ) );

		$this->assertInstanceOf( \Closure::class, $provided );
		$this->assertSame( $features, $provided() );
	}

	public function testHandleSkipsNonBeforeTemplateRenderedEvent(): void
	{
		$event = $this->createMock( Event::class );

		$this->initialState->expects( $this->never() )
		                   ->method( 'provideLazyInitialState' )
		;

		// The handler must return early for unrecognised event types
		// without calling Util side-effects (which would throw if they
		// were reached — but since we can't mock Util statics, we rely
		// on the early return not throwing).
		$this->listener->handle( $event );
	}
}
