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

		return new DataResponse( [
			'docs' => $this->readDocs(
				[
					[
						'label' => 'FAQ',
						'name'  => 'docs/FAQ.md',
						'path'  => 'docs/FAQ.md',
					],
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
				],
			),
		] );
	}


	/**
	 * Serve public-facing help documentation to all authenticated users.
	 *
	 * The duplicate browser and personal settings pages are available to
	 * every user, so the FAQ and user help must be readable without admin
	 * privileges.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute( verb: 'GET', url: '/help' )]
	public function getHelp(): DataResponse
	{

		return new DataResponse( [
			'docs' => $this->readDocs(
				[
					[
						'label' => 'FAQ',
						'name'  => 'docs/FAQ.md',
						'path'  => 'docs/FAQ.md',
					],
					[
						'label' => 'User Help',
						'name'  => 'docs/HELP.md',
						'path'  => 'docs/HELP.md',
					],
				],
			),
		] );
	}


	/**
	 * Read the given bundled doc files and return label/name/path/content entries.
	 *
	 * @param  array<int, array{label: string, name: string, path: string}>  $files
	 *
	 * @return array<int, array{label: string, name: string, path: string, content: string|null}>
	 */
	private function readDocs( array $files ): array
	{

		$appRoot = dirname( __DIR__, 2 );

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

		return $docs;
	}

}
