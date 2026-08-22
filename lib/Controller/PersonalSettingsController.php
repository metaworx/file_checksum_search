<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\HashCalculationService;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

class PersonalSettingsController
	extends
	Controller
{

	public function __construct(
		string                           $appName,
		IRequest                         $request,
		private readonly RuleService     $ruleService,
		private readonly IUserSession    $userSession,
		private readonly LoggerInterface $logger,
	) {

		parent::__construct( $appName, $request );
	}


	/**
	 * List rules applying to the current user's visible storages.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/personal/rules' )]
	public function getPersonalRules(): DataResponse
	{

		$userId = $this->currentUserId();

		if ( $userId === null )
		{
			return $this->unauthorized();
		}

		return new DataResponse( [
			'success'        => true,
			'rules'          => $this->ruleService->getPersonalRulesForUser( $userId ),
			'canEdit'        => $this->ruleService->canUserEditRules( $userId ),
			'supportedAlgos' => HashCalculationService::SUPPORTED_ALGOS,
		] );
	}


	/**
	 * Create or update a rule (personal scope).
	 *
	 * admin_enforced and userScope are never trusted from the request body.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[ApiRoute( verb: 'POST', url: '/personal/rules/save' )]
	public function savePersonalRule(): DataResponse
	{

		$userId = $this->currentUserId();

		if ( $userId === null )
		{
			return $this->unauthorized();
		}

		if ( ! $this->ruleService->canUserEditRules( $userId ) )
		{
			return $this->forbidden();
		}

		$body = json_decode( $this->readRequestBody(), true );

		if ( ! is_array( $body ) )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'Invalid request body.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$algos = $body['algos'] ?? [ HashCalculationService::getDefaultAlgo() ];

		if ( ! is_array( $algos ) )
		{
			$algos = [ $algos ];
		}

		$algos = array_values(
			array_filter(
				$algos,
				static fn(
					$a,
				): bool => HashCalculationService::isValidAlgo( $a ),
			),
		);

		if ( empty( $algos ) )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'At least one supported algorithm is required.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		// Never trust admin_enforced or userScope from a user request.
		$definition = [
			'enabled' => (bool) ( $body['enabled'] ?? true ),
			'mode'    => $body['mode'] ?? 'auto',
			'algos'   => $algos,
			'path'    => $body['path'] ?? '/',
		];

		if ( ! $this->ruleService->isPathWritableByUser( $userId, $definition['path'] ) )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'The selected path is not write-accessible to you.',
				],
				Http::STATUS_FORBIDDEN,
			);
		}

		$existingId = $body['id'] ?? null;

		try
		{
			if ( $existingId !== null && $existingId !== '' )
			{
				$existing = $this->ruleService->findRuleById( $existingId );

				if ( $existing === null )
				{
					return new DataResponse(
						[
							'success' => false,
							'error'   => 'Rule not found.',
						],
						Http::STATUS_NOT_FOUND,
					);
				}

				if ( ! $this->ruleService->canUserMutateRule( $userId, $existing ) )
				{
					return $this->forbidden();
				}

				$definition['userScope'] = $existing['userScope'] ?? $userId;

				$this->ruleService->ruleUpdate( $existingId, $definition );
			}
			else
			{
				$definition['userScope'] = $userId;

				$this->ruleService->ruleAdd( $definition );
			}

			return new DataResponse( [
				'success'    => true,
				'definition' => $definition,
			] );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS PersonalSettingsController: savePersonalRule failed',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[
					'success' => false,
					'error'   => $e->getMessage(),
				], Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	/**
	 * Delete a rule (personal scope).
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[ApiRoute( verb: 'POST', url: '/personal/rules/delete' )]
	public function deletePersonalRule(): DataResponse
	{

		$userId = $this->currentUserId();

		if ( $userId === null )
		{
			return $this->unauthorized();
		}

		if ( ! $this->ruleService->canUserEditRules( $userId ) )
		{
			return $this->forbidden();
		}

		$body = json_decode( $this->readRequestBody(), true );
		$id   = is_array( $body ) ? ( $body['id'] ?? null ) : null;

		if ( $id === null || $id === '' )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'Definition ID is required.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$existing = $this->ruleService->findRuleById( $id );

		if ( $existing === null )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'Rule not found.',
				],
				Http::STATUS_NOT_FOUND,
			);
		}

		if ( ! $this->ruleService->canUserMutateRule( $userId, $existing ) )
		{
			return $this->forbidden();
		}

		try
		{
			$this->ruleService->ruleDelete( $id );

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS PersonalSettingsController: deletePersonalRule failed',
				[
					'app'       => Application::APP_ID,
					'id'        => $id,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[
					'success' => false,
					'error'   => $e->getMessage(),
				], Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	/**
	 * Enable or disable a rule (personal scope).
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[ApiRoute( verb: 'POST', url: '/personal/rules/toggle' )]
	public function togglePersonalRule(): DataResponse
	{

		$userId = $this->currentUserId();

		if ( $userId === null )
		{
			return $this->unauthorized();
		}

		if ( ! $this->ruleService->canUserEditRules( $userId ) )
		{
			return $this->forbidden();
		}

		$body    = json_decode( $this->readRequestBody(), true );
		$id      = is_array( $body ) ? ( $body['id'] ?? null ) : null;
		$enabled = (bool) ( is_array( $body ) ? ( $body['enabled'] ?? false ) : false );

		if ( $id === null || $id === '' )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'Definition ID is required.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$existing = $this->ruleService->findRuleById( $id );

		if ( $existing === null )
		{
			return new DataResponse(
				[
					'success' => false,
					'error'   => 'Rule not found.',
				],
				Http::STATUS_NOT_FOUND,
			);
		}

		if ( ! $this->ruleService->canUserMutateRule( $userId, $existing ) )
		{
			return $this->forbidden();
		}

		try
		{
			$this->ruleService->ruleToggle( $id, $enabled );

			return new DataResponse( [ 'success' => true ] );
		}
		catch ( Throwable $e )
		{
			$this->logger->error(
				'FCIAS PersonalSettingsController: togglePersonalRule failed',
				[
					'app'       => Application::APP_ID,
					'id'        => $id,
					'enabled'   => $enabled,
					'exception' => $e,
				],
			);

			return new DataResponse(
				[
					'success' => false,
					'error'   => $e->getMessage(),
				], Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}


	/**
	 * Read the raw HTTP request body.
	 *
	 * Protected so unit tests can mock it via
	 * getMockBuilder()->onlyMethods() — php://input is read-only
	 * in CLI mode where PHPUnit runs.
	 */
	protected function readRequestBody(): string
	{

		return file_get_contents( 'php://input' );
	}


	private function currentUserId(): ?string
	{

		$user = $this->userSession->getUser();

		return $user === null
			? null
			: $user->getUID();
	}


	private function unauthorized(): DataResponse
	{

		return new DataResponse(
			[
				'success' => false,
				'error'   => 'Not logged in.',
			],
			Http::STATUS_UNAUTHORIZED,
		);
	}


	private function forbidden(): DataResponse
	{

		return new DataResponse(
			[
				'success' => false,
				'error'   => 'You are not allowed to edit this rule.',
			],
			Http::STATUS_FORBIDDEN,
		);
	}

}
