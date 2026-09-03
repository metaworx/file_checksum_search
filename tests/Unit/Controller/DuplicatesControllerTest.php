<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\DuplicatesController;
use OCA\FileChecksumSearch\Service\SudoScope;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * What is left of this controller once its two unused listing routes were
 * removed: the picker's source, and nothing else. The listings themselves
 * are PublicApiController's, which is what the page calls and where their
 * tests live.
 */
class DuplicatesControllerTest
	extends
	FciasUnitTestCase
{

	private MockObject|IUserSession $userSession;

	private MockObject|SudoScope    $sudo;

	private MockObject|IAppConfig   $appConfig;

	private DuplicatesController    $controller;


	protected function setUp(): void
	{

		parent::setUp();

		$this->userSession = $this->createMock( IUserSession::class );
		$this->sudo        = $this->createMock( SudoScope::class );
		$this->appConfig   = $this->createMock( IAppConfig::class );
		$this->appConfig->method( 'getValueInt' )
		                ->willReturn( 21 )
		;

		$this->controller = new DuplicatesController(
			'file_checksum_search',
			$this->createMock( IRequest::class ),
			$this->userSession,
			$this->sudo,
			$this->appConfig,
		);
	}


	public function testSelectableAnswersWhatThePickerMayOfferAndWhetherAllIsOne(): void
	{

		$this->signedInAs( 'lead' );
		$this->sudo->method( 'selectableFor' )
		           ->with( 'lead', null, 21 )
		           ->willReturn( [
			           'prefill' => true,
			           'groups'  => [ [ 'id' => 'team', 'label' => 'Team' ] ],
			           'users'   => [ [ 'id' => 'member', 'label' => 'Member' ] ],
		           ] )
		;
		$this->sudo->method( 'isSudoer' )
		           ->willReturn( false )
		;

		$data = $this->controller->selectable()
		                         ->getData()
		;

		$this->assertTrue( $data['prefill'] );
		$this->assertFalse( $data['all'], 'a sub-admin is not offered every account' );
		$this->assertSame( [ 'team' ], array_column( $data['groups'], 'id' ) );
	}


	/**
	 * The threshold is the instance's, so the picker's behaviour follows the
	 * Advanced setting rather than a number carried here.
	 */
	public function testSelectablePassesTheConfiguredPrefillLimit(): void
	{

		$this->signedInAs( 'root' );
		$this->appConfig = $this->createMock( IAppConfig::class );
		$this->sudo->expects( $this->once() )
		           ->method( 'selectableFor' )
		           ->with( 'root', 'ali', 21 )
		           ->willReturn( [ 'prefill' => false, 'groups' => [], 'users' => [] ] )
		;
		$this->sudo->method( 'isSudoer' )
		           ->willReturn( true )
		;

		$data = $this->controller->selectable( 'ali' )
		                         ->getData()
		;

		$this->assertFalse( $data['prefill'] );
		$this->assertTrue( $data['all'], 'a sudoer is offered every account' );
	}


	public function testSelectableRefusesAnAccountThatMayNameNobody(): void
	{

		$this->signedInAs( 'bob' );
		$this->sudo->method( 'selectableFor' )
		           ->willReturn( false )
		;

		$this->assertSame( Http::STATUS_FORBIDDEN, $this->controller->selectable()->getStatus() );
	}


	public function testSelectableRefusesWithNoSession(): void
	{

		$this->userSession->method( 'getUser' )
		                  ->willReturn( null )
		;

		$this->assertSame( Http::STATUS_UNAUTHORIZED, $this->controller->selectable()->getStatus() );
	}


	private function signedInAs( string $uid ): void
	{

		$user = $this->createMock( IUser::class );
		$user->method( 'getUID' )
		     ->willReturn( $uid )
		;
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;
	}

}
