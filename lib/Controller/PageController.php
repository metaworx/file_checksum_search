<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Page controller for the global duplicate file browser.
 */
class PageController
	extends
	Controller
{

	public function __construct(
		string   $appName,
		IRequest $request,
	) {

		parent::__construct( $appName, $request );
	}


	/**
	 * Render the global duplicate browser page.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute( verb: 'GET', url: '/duplicates' )]
	public function index(): TemplateResponse
	{

		return new TemplateResponse(
			'file_checksum_search',
			'duplicates',
			[],
			TemplateResponse::RENDER_AS_USER,
		);
	}


	/**
	 * Serve bundled documentation files for the Documentation tab.
	 *
	 * Admin-only by default — the method deliberately omits #[NoAdminRequired].
	 *
	 * @noinspection PhpUnused
	 */
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/admin/docs' )]
	public function getDocs(): DataResponse
	{

		$appRoot = dirname( __DIR__, 2 );

		// Whitelist of bundled docs — never serve arbitrary paths.
		$files = [
			[
				'label' => 'README',
				'name'  => 'README.md',
				'path'  => 'README.md',
			],
			[
				'label' => 'API v1 (OpenAPI)',
				'name'  => 'docs/api-v1-openapi.yaml',
				'path'  => 'docs/api-v1-openapi.yaml',
			],
			[
				'label' => 'API v1 (Markdown)',
				'name'  => 'docs/api-v1.md',
				'path'  => 'docs/api-v1.md',
			],
			[
				'label' => 'openapi.json',
				'name'  => 'openapi.json',
				'path'  => 'openapi.json',
			],
			[
				'label' => 'LICENSE',
				'name'  => 'LICENSE',
				'path'  => 'LICENSE',
			],
		];

		$docs = [];

		foreach ( $files as $file )
		{
			$fullPath = $appRoot . '/' . $file['path'];
			$content  = null;

			if ( is_file( $fullPath ) )
			{
				$content = file_get_contents( $fullPath );
			}

			$docs[] = [
				'label'   => $file['label'],
				'name'    => $file['name'],
				'path'    => $file['path'],
				'content' => $content === false
					? null
					: $content,
			];
		}

		return new DataResponse( [ 'docs' => $docs ] );
	}

}
