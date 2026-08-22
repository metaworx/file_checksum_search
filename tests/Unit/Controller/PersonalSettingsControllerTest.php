<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\PersonalSettingsController;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCA\FileChecksumSearch\Tests\Unit\FciasUnitTestCase;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class PersonalSettingsControllerTest
	extends
	FciasUnitTestCase
{

	private MockObject|RuleService               $ruleService;

	private MockObject|IUserSession              $userSession;

	private MockObject|IRequest                  $request;

	private MockObject|LoggerInterface           $logger;

	private PersonalSettingsController           $controller;


	protected function setUp(): void
	{

		parent::setUp();

		$this->ruleService = $this->createMock( RuleService::class );
		$this->userSession = $this->createMock( IUserSession::class );
		$this->request     = $this->createMock( IRequest::class );
		$this->logger      = $this->createMock( LoggerInterface::class );

		// Partial mock: only readRequestBody() is mocked so php://input
		// (read-only in CLI) can return test-provided JSON payloads.
		$this->controller = $this->getMockBuilder( PersonalSettingsController::class )
		                         ->onlyMethods( [ 'readRequestBody' ] )
		                         ->setConstructorArgs( [
			                         'file_checksum_search',
			                         $this->request,
			                         $this->ruleService,
			                         $this->userSession,
			                         $this->logger,
		                         ] )
		                         ->getMock()
		;
	}


	private function mockUser( ?string $uid ): void
	{

		if ( $uid === null )
		{
			$this->userSession->method( 'getUser' )
			                  ->willReturn( null )
			;

			return;
		}

		$user = $this->createConfiguredMock( IUser::class, [ 'getUID' => $uid ] );
		$this->userSession->method( 'getUser' )
		                  ->willReturn( $user )
		;
	}


	// getPersonalRules

	public function testGetPersonalRulesReturnsRulesAndFlags(): void
	{

		$this->mockUser( 'alice' );

		$this->ruleService->method( 'getPersonalRulesForUser' )
		                  ->with( 'alice' )
		                  ->willReturn( [
			                  [ 'id' => 'r1', 'path' => '**', 'admin_enforced' => false, 'canEdit' => true ],
		                  ] )
		;
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( true )
		;

		$response = $this->controller->getPersonalRules();

		$this->assertInstanceOf( DataResponse::class, $response );
		$data = $response->getData();
		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['canEdit'] );
		$this->assertSame( HashCalculationService::SUPPORTED_ALGOS, $data['supportedAlgos'] );
		$this->assertCount( 1, $data['rules'] );
	}


	public function testGetPersonalRulesRequiresLogin(): void
	{

		$this->mockUser( null );

		$response = $this->controller->getPersonalRules();

		$this->assertSame( Http::STATUS_UNAUTHORIZED, $response->getStatus() );
	}


	// savePersonalRule

	public function testSavePersonalRuleCreatesRule(): void
	{

		$this->mockUser( 'alice' );
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( true )
		;
		$this->ruleService->method( 'isPathWritableByUser' )
		                  ->with( 'alice', '/Documents' )
		                  ->willReturn( true )
		;
		$this->controller->method( 'readRequestBody' )
		                 ->willReturn(
			                 json_encode( [
				                 'enabled' => true,
				                 'mode'    => 'auto',
				                 'algos'   => [ 'sha1' ],
				                 'path'    => '/Documents',
			                 ] ),
		                 )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleAdd' )
		                  ->with(
			                  $this->callback(
				                  function (
					                  array $definition,
				                  ): bool {

					                  return $definition['path'] === '/Documents'
						                  && $definition['algos'] === [ 'sha1' ]
						                  && $definition['userScope'] === 'alice'
						                  && ! isset( $definition['admin_enforced'] );
				                  },
			                  ),
		                  )
		;

		$response = $this->controller->savePersonalRule();

		$this->assertSame( Http::STATUS_OK, $response->getStatus() );
		$this->assertTrue( $response->getData()['success'] );
	}


	public function testSavePersonalRuleUpdatesExistingRule(): void
	{

		$this->mockUser( 'alice' );
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( true )
		;
		$this->ruleService->method( 'isPathWritableByUser' )
		                  ->with( 'alice', '/Docs' )
		                  ->willReturn( true )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->with( 'r1' )
		                  ->willReturn( [ 'id' => 'r1', 'userScope' => 'alice', 'admin_enforced' => false ] )
		;
		$this->ruleService->method( 'canUserMutateRule' )
		                  ->willReturn( true )
		;
		$this->controller->method( 'readRequestBody' )
		                 ->willReturn(
			                 json_encode( [
				                 'id'    => 'r1',
				                 'algos' => [ 'sha256' ],
				                 'path'  => '/Docs',
			                 ] ),
		                 )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleUpdate' )
		                  ->with(
			                  'r1',
			                  $this->callback(
				                  function (
					                  array $definition,
				                  ): bool {

					                  return $definition['path'] === '/Docs'
						                  && $definition['userScope'] === 'alice'
						                  && ! isset( $definition['admin_enforced'] );
				                  },
			                  ),
		                  )
		;

		$response = $this->controller->savePersonalRule();

		$this->assertSame( Http::STATUS_OK, $response->getStatus() );
		$this->assertTrue( $response->getData()['success'] );
	}


	public function testSavePersonalRuleReturns403WhenNotInGroups(): void
	{

		$this->mockUser( 'alice' );
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( false )
		;
		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleAdd' )
		;

		$response = $this->controller->savePersonalRule();

		$this->assertSame( Http::STATUS_FORBIDDEN, $response->getStatus() );
	}


	public function testSavePersonalRuleReturns403WhenPathNotWritable(): void
	{

		$this->mockUser( 'alice' );
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( true )
		;
		$this->controller->method( 'readRequestBody' )
		                 ->willReturn( json_encode( [ 'algos' => [ 'sha1' ], 'path' => '/Nowhere' ] ) )
		;
		$this->ruleService->method( 'isPathWritableByUser' )
		                  ->with( 'alice', '/Nowhere' )
		                  ->willReturn( false )
		;

		$response = $this->controller->savePersonalRule();

		$this->assertSame( Http::STATUS_FORBIDDEN, $response->getStatus() );
	}


	public function testSavePersonalRuleReturns404WhenRuleNotFound(): void
	{

		$this->mockUser( 'alice' );
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( true )
		;
		$this->ruleService->method( 'isPathWritableByUser' )
		                  ->willReturn( true )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->with( 'missing' )
		                  ->willReturn( null )
		;
		$this->controller->method( 'readRequestBody' )
		                 ->willReturn( json_encode( [ 'id' => 'missing', 'algos' => [ 'sha1' ], 'path' => '/' ] ) )
		;

		$response = $this->controller->savePersonalRule();

		$this->assertSame( Http::STATUS_NOT_FOUND, $response->getStatus() );
	}


	public function testSavePersonalRuleReturns403WhenUpdatingAnotherUsersRule(): void
	{

		// Regression test for FCIAS Review §6, Finding 3: a user must
		// not be able to update another user's rule by guessing its ID.
		$this->mockUser( 'alice' );
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( true )
		;
		$this->ruleService->method( 'isPathWritableByUser' )
		                  ->with( 'alice', '/Docs' )
		                  ->willReturn( true )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->with( 'r1' )
		                  ->willReturn( [ 'id' => 'r1', 'userScope' => 'bob', 'admin_enforced' => false ] )
		;
		$this->ruleService->method( 'canUserMutateRule' )
		                  ->willReturn( false )
		;
		$this->controller->method( 'readRequestBody' )
		                 ->willReturn(
			                 json_encode( [
				                 'id'    => 'r1',
				                 'algos' => [ 'sha256' ],
				                 'path'  => '/Docs',
			                 ] ),
		                 )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleUpdate' )
		;

		$response = $this->controller->savePersonalRule();

		$this->assertSame( Http::STATUS_FORBIDDEN, $response->getStatus() );
	}


	public function testSavePersonalRuleRejectsInvalidAlgos(): void
	{

		$this->mockUser( 'alice' );
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( true )
		;
		$this->controller->method( 'readRequestBody' )
		                 ->willReturn( json_encode( [ 'algos' => [ 'bogus' ] ] ) )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleAdd' )
		;

		$response = $this->controller->savePersonalRule();

		$this->assertSame( Http::STATUS_BAD_REQUEST, $response->getStatus() );
	}


	// deletePersonalRule

	public function testDeletePersonalRuleDeletesById(): void
	{

		$this->mockUser( 'alice' );
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( true )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->with( 'r1' )
		                  ->willReturn( [ 'id' => 'r1', 'path' => '/', 'admin_enforced' => false ] )
		;
		$this->ruleService->method( 'canUserMutateRule' )
		                  ->willReturn( true )
		;
		$this->controller->method( 'readRequestBody' )
		                 ->willReturn( json_encode( [ 'id' => 'r1' ] ) )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleDelete' )
		                  ->with( 'r1' )
		;

		$response = $this->controller->deletePersonalRule();

		$this->assertSame( Http::STATUS_OK, $response->getStatus() );
		$this->assertTrue( $response->getData()['success'] );
	}


	public function testDeletePersonalRuleReturns403WhenEnforced(): void
	{

		$this->mockUser( 'alice' );
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( true )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->with( 'r1' )
		                  ->willReturn( [ 'id' => 'r1', 'path' => '/', 'admin_enforced' => true ] )
		;
		$this->ruleService->method( 'canUserMutateRule' )
		                  ->willReturn( false )
		;
		$this->controller->method( 'readRequestBody' )
		                 ->willReturn( json_encode( [ 'id' => 'r1' ] ) )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleDelete' )
		;

		$response = $this->controller->deletePersonalRule();

		$this->assertSame( Http::STATUS_FORBIDDEN, $response->getStatus() );
	}


	public function testDeletePersonalRuleReturns403WhenRuleBelongsToAnotherUser(): void
	{

		// Regression test for FCIAS Review §6, Finding 3: a user must
		// not be able to delete another user's rule by guessing its ID,
		// even when its path would be writable in their own home.
		$this->mockUser( 'alice' );
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( true )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->with( 'r1' )
		                  ->willReturn( [ 'id' => 'r1', 'path' => '/', 'userScope' => 'bob', 'admin_enforced' => false ] )
		;
		$this->ruleService->method( 'canUserMutateRule' )
		                  ->willReturn( false )
		;
		$this->controller->method( 'readRequestBody' )
		                 ->willReturn( json_encode( [ 'id' => 'r1' ] ) )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleDelete' )
		;

		$response = $this->controller->deletePersonalRule();

		$this->assertSame( Http::STATUS_FORBIDDEN, $response->getStatus() );
	}


	// togglePersonalRule

	public function testTogglePersonalRuleToggles(): void
	{

		$this->mockUser( 'alice' );
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( true )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->with( 'r1' )
		                  ->willReturn( [ 'id' => 'r1', 'path' => '/', 'admin_enforced' => false ] )
		;
		$this->ruleService->method( 'canUserMutateRule' )
		                  ->willReturn( true )
		;
		$this->controller->method( 'readRequestBody' )
		                 ->willReturn(
			                 json_encode( [
				                 'id'      => 'r1',
				                 'enabled' => true,
			                 ] ),
		                 )
		;

		$this->ruleService->expects( $this->once() )
		                  ->method( 'ruleToggle' )
		                  ->with( 'r1', true )
		;

		$response = $this->controller->togglePersonalRule();

		$this->assertSame( Http::STATUS_OK, $response->getStatus() );
		$this->assertTrue( $response->getData()['success'] );
	}


	public function testTogglePersonalRuleReturns403WhenNotInGroups(): void
	{

		$this->mockUser( 'alice' );
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( false )
		;

		$response = $this->controller->togglePersonalRule();

		$this->assertSame( Http::STATUS_FORBIDDEN, $response->getStatus() );
	}


	public function testTogglePersonalRuleReturns403WhenRuleBelongsToAnotherUser(): void
	{

		// Regression test for FCIAS Review §6, Finding 3.
		$this->mockUser( 'alice' );
		$this->ruleService->method( 'canUserEditRules' )
		                  ->with( 'alice' )
		                  ->willReturn( true )
		;
		$this->ruleService->method( 'findRuleById' )
		                  ->with( 'r1' )
		                  ->willReturn( [ 'id' => 'r1', 'path' => '/', 'userScope' => 'bob', 'admin_enforced' => false ] )
		;
		$this->ruleService->method( 'canUserMutateRule' )
		                  ->willReturn( false )
		;
		$this->controller->method( 'readRequestBody' )
		                 ->willReturn(
			                 json_encode( [
				                 'id'      => 'r1',
				                 'enabled' => true,
			                 ] ),
		                 )
		;

		$this->ruleService->expects( $this->never() )
		                  ->method( 'ruleToggle' )
		;

		$response = $this->controller->togglePersonalRule();

		$this->assertSame( Http::STATUS_FORBIDDEN, $response->getStatus() );
	}

}
